<?php

declare(strict_types=1);

namespace SimpleDevis\Tools\SuperPdp;

use App\Services\SuperPdp\Emission\EmissionJson;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use RuntimeException;
use Throwable;

require_once __DIR__.'/SuperPdpClientCredentialsDiagnostic.php';

/** Standalone: never bootstrap Laravel, load models or persist credentials. */
final readonly class AuthorizationCodeProfile
{
    public const LOCAL = 'http://127.0.0.1:8765';

    public const CALLBACK = self::LOCAL.'/oauth/callback';

    public const AUTHORIZE = DiagnosticProfile::ORIGIN.'/oauth2/authorize';

    public const SESSION = DiagnosticProfile::API.'/oauth2_sessions/me';

    private function __construct(private DiagnosticProfile $credentials) {}

    public static function fromFile(string $path): self
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 65536) {
            throw new RuntimeException('profile_invalid');
        }

        return self::fromText(file_get_contents($path));
    }

    public static function fromText(#[\SensitiveParameter] string $text): self
    {
        $credentials = DiagnosticProfile::fromText($text);
        $selected = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (! preg_match('/^\s*(?:export\s+)?(APP_URL|SUPERPDP_AUTHORIZE_URL)\s*=/', $line, $match)) {
                continue;
            }
            if (isset($selected[$match[1]]) || str_contains($line, '${')) {
                throw new RuntimeException('profile_invalid');
            }
            $selected[$match[1]] = $line;
        }
        $values = Dotenv::parse(implode("\n", $selected));
        if (($values['APP_URL'] ?? null) !== 'http://127.0.0.1:8000'
            || ($values['SUPERPDP_AUTHORIZE_URL'] ?? null) !== self::AUTHORIZE) {
            throw new RuntimeException('profile_invalid');
        }

        return new self($credentials);
    }

    public function options(): array
    {
        return $this->credentials->providerOptions();
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->options(), JSON_THROW_ON_ERROR).self::CALLBACK);
    }

    public function begin(array &$session, int $now): string
    {
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $session['pending'] = ['state' => $state, 'verifier' => $verifier, 'created_at' => $now, 'profile' => $this->fingerprint()];

        return self::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code', 'client_id' => $this->options()['clientId'], 'redirect_uri' => self::CALLBACK,
            'state' => $state, 'scope' => '', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'superpdp_company_number_scheme' => 'sandbox', 'superpdp_company_number' => '000000002',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function __debugInfo(): array
    {
        return ['environment' => 'sandbox', 'company_number' => '000000002'];
    }
}

final class AuthorizationCodeDiagnostic
{
    public static function simulate(AuthorizationCodeProfile $profile, array &$session, string $query, MockHandler $handler, int $now): array
    {
        return self::run($profile, $session, $query, $now, $handler);
    }

    public static function live(AuthorizationCodeProfile $profile, array &$session, string $query, bool $approved = false): array
    {
        // Check before selecting a native HTTP backend or consuming the callback.
        if (! $approved) {
            unset($session['pending']);

            return self::failure('live_disabled');
        }

        return self::run($profile, $session, $query, time(), null);
    }

    private static function run(AuthorizationCodeProfile $profile, array &$session, string $query, int $now, ?MockHandler $mock): array
    {
        $pending = $session['pending'] ?? null;
        unset($session['pending']); // Consume even malformed, denied or expired callbacks.
        $result = self::failure('callback_invalid');
        $result['mode'] = $mock === null ? 'live' : 'simulated';
        try {
            $params = self::query($query);
            if (! is_array($pending) || ! is_string($pending['state'] ?? null)
                || ! is_string($params['state'] ?? null) || ! hash_equals($pending['state'], $params['state'])
                || ! is_int($pending['created_at'] ?? null) || $pending['created_at'] > $now || $pending['created_at'] < $now - 600
                || ($pending['profile'] ?? null) !== $profile->fingerprint()
                || ! is_string($pending['verifier'] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $pending['verifier'])) {
                return $result;
            }
            if (isset($params['error'])) {
                $result['error'] = isset($params['code']) ? 'callback_invalid' : 'consent_refused';

                return $result;
            }
            if (! isset($params['code']) || $params['code'] === '' || strlen($params['code']) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $params['code'])) {
                return $result;
            }
            $client = new Client([
                'handler' => HandlerStack::create($mock ?? Utils::chooseHandler()),
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true, 'debug' => false, 'proxy' => '',
                'timeout' => 20, 'connect_timeout' => 5, 'cookies' => false,
                'headers' => ['Accept' => 'application/json'],
                'on_headers' => static function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 65536) {
                        throw new RuntimeException('response_invalid');
                    }
                },
                'progress' => static function ($total, $downloaded): void {
                    if ($downloaded > 65536) {
                        throw new RuntimeException('response_invalid');
                    }
                },
            ]);
            $phase = 'token';
            $options = $profile->options();
            $tokens = self::request($client, 'POST', DiagnosticProfile::TOKEN, ['form_params' => [
                'grant_type' => 'authorization_code', 'client_id' => $options['clientId'], 'client_secret' => $options['clientSecret'],
                'redirect_uri' => AuthorizationCodeProfile::CALLBACK, 'code' => $params['code'], 'code_verifier' => $pending['verifier'],
            ]], $result);
            if (! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === ''
                || preg_match('/[\x00-\x20\x7f]/', $tokens['access_token'])
                || ! is_string($tokens['token_type'] ?? null) || strcasecmp($tokens['token_type'], 'Bearer') !== 0
                || ! is_int($tokens['expires_in'] ?? null) || $tokens['expires_in'] < 60
                || (array_key_exists('company_id', $tokens) && (! is_int($tokens['company_id']) || $tokens['company_id'] <= 0))) {
                throw new RuntimeException('token_invalid');
            }
            $result['oauth'] = 'passed';
            // Only record compatibility, never a refresh token, even in the session.
            $result['refresh_token_present'] = is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== '';
            $auth = ['headers' => ['Authorization' => 'Bearer '.$tokens['access_token']]];
            $phase = 'session';
            $row = self::request($client, 'GET', AuthorizationCodeProfile::SESSION, $auth, $result);
            if (($row['client_id'] ?? null) !== $options['clientId'] || ! self::date($row['created_at'] ?? null)) {
                throw new RuntimeException('session_invalid');
            }
            foreach (['company_verification_status', 'user_identity_verification_status'] as $key) {
                $value = $row[$key] ?? null;
                $result[$key] = in_array($value, ['verified', 'needs_review', 'failed', 'not_verified'], true) ? $value : 'missing_or_unknown';
            }
            if (($row['company_verification_status'] ?? null) !== 'verified' || ($row['user_identity_verification_status'] ?? null) !== 'verified') {
                throw new RuntimeException('session_not_verified');
            }
            $result['seller_session'] = 'passed';
            if ($tokens['expires_in'] < time() - $now + 30 && $mock === null) {
                throw new RuntimeException('token_expired');
            }
            $phase = 'company';
            $company = self::request($client, 'GET', DiagnosticProfile::COMPANY, $auth, $result);
            if (($company['number_scheme'] ?? null) !== 'sandbox' || ($company['number'] ?? null) !== '000000002'
                || ($company['env'] ?? null) !== 'sandbox' || ($company['country'] ?? null) !== 'FR'
                || ! self::date($company['created_at'] ?? null) || ! is_int($company['id'] ?? null) || $company['id'] <= 0
                || (isset($tokens['company_id']) && $tokens['company_id'] !== $company['id'])) {
                throw new RuntimeException('company_mismatch');
            }
            $result['provider_company_id'] = $company['id'];
            $result['seller_identity'] = 'passed';
            $result['error'] = null;
        } catch (Throwable $exception) {
            // Never chain, log or display HTTP exceptions, payloads, codes or credentials.
            $safe = ['session_not_verified', 'company_mismatch', 'token_expired'];
            $result['error'] = in_array($exception->getMessage(), $safe, true)
                ? $exception->getMessage() : (isset($phase) ? $phase.'_response_or_transport_invalid' : 'callback_invalid');
        }

        return $result;
    }

    private static function query(string $query): array
    {
        if (strlen($query) > 16384) {
            throw new RuntimeException('callback_invalid');
        }
        $params = [];
        foreach (explode('&', $query) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = urldecode($key);
            if (! in_array($key, ['code', 'state', 'error', 'error_description', 'error_uri'], true) || isset($params[$key])) {
                throw new RuntimeException('callback_invalid');
            }
            $params[$key] = urldecode($value);
        }

        return $params;
    }

    private static function request(Client $client, string $method, string $url, #[\SensitiveParameter] array $options, array &$result): array
    {
        // All three call sites have fixed URL/method pairs. No retry/refresh/redirect.
        $result['requests']++;
        $options['sink'] = \GuzzleHttp\Psr7\Utils::streamFor(fopen('php://memory', 'w+'));
        $response = $client->request($method, $url, $options);
        $result['http_status'] = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || strlen($body) > 65536) {
            throw new RuntimeException('response_invalid');
        }

        return EmissionJson::object($body);
    }

    private static function date(mixed $date): bool
    {
        return is_string($date) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $date) && strtotime($date) !== false;
    }

    public static function failure(string $error): array
    {
        return ['mode' => 'offline', 'oauth' => 'not_confirmed', 'seller_session' => 'not_confirmed', 'seller_identity' => 'not_confirmed',
            'provider_company_id' => null, 'refresh_token_present' => false, 'error' => $error, 'requests' => 0, 'http_status' => null];
    }
}

/** Pure HTTP routing for offline tests; the standalone router owns the PHP session. */
final class AuthorizationCodeWeb
{
    public static function inspectionMode(?string $mode): bool
    {
        return match ($mode) {
            null, '', 'observe' => true,
            'oauth' => false,
            default => throw new RuntimeException('diagnostic_mode_invalid'),
        };
    }

    public static function startObservation(array $server, array $post, array $session): array
    {
        $origin = $server['HTTP_ORIGIN'] ?? null;
        $originKind = match ($origin) {
            AuthorizationCodeProfile::LOCAL => 'expected',
            'null' => 'null',
            null => 'missing',
            default => 'other',
        };
        $method = $server['REQUEST_METHOD'] ?? null;
        $stored = $session['csrf'] ?? null;
        $submitted = $post['csrf'] ?? null;
        $checks = [
            'method_post' => $method === 'POST',
            'uri_exact' => ($server['REQUEST_URI'] ?? null) === '/oauth/start',
            'origin_expected' => $originKind === 'expected',
            'session_csrf_present' => is_string($stored),
            'submitted_csrf_present' => is_string($submitted),
            'csrf_matches' => is_string($stored) && is_string($submitted) && hash_equals($stored, $submitted),
            'fields_expected' => array_diff(array_keys($post), ['csrf']) === [],
        ];
        $failures = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $reason = match (true) {
            ! $checks['method_post'] => 'post_required',
            ! $checks['uri_exact'] => 'start_uri_invalid',
            ! $checks['origin_expected'] => 'origin_invalid',
            ! $checks['session_csrf_present'] => 'form_missing_or_already_consumed',
            ! $checks['submitted_csrf_present'] => 'form_nonce_missing',
            ! $checks['csrf_matches'] => 'form_stale_or_changed',
            ! $checks['fields_expected'] => 'unexpected_form_fields',
            default => 'form_valid_observation_only',
        };

        // Only closed enums and booleans: never include raw headers, field names
        // or values, cookie IDs, CSRF/state/PKCE, URLs, credentials or payloads.
        return ['source' => 'local_diagnostic', 'mode' => 'start_inspection',
            'method' => in_array($method, ['GET', 'POST'], true) ? $method : 'other',
            'origin' => $originKind, 'checks' => $checks, 'failed_checks' => $failures,
            'accepted' => $failures === [], 'reason' => $reason,
            'restart_url' => AuthorizationCodeProfile::LOCAL.'/',
            'next_action' => $failures === [] ? 'await_explicit_oauth_mode' : 'reload_local_form_then_submit_once',
            'provider_requests' => 0];
    }

    public static function navigationHeaders(string $requestUri): array
    {
        $formPage = $requestUri === '/';
        $consentNavigation = $formPage || $requestUri === '/oauth/start';

        return [
            // no-referrer on the form can serialize the POST Origin as "null".
            // same-origin preserves our exact Origin check without disclosing a
            // Referer to the external OAuth provider. Callback stays no-referrer.
            'Referrer-Policy' => $formPage ? 'same-origin' : 'no-referrer',
            // A browser may apply form-action to the POST's 303 destination.
            // Allow only the fixed authorization endpoint, never arbitrary hosts.
            'Content-Security-Policy' => "default-src 'none'; form-action 'self'"
                .($consentNavigation ? ' '.AuthorizationCodeProfile::AUTHORIZE : '')
                ."; frame-ancestors 'none'; base-uri 'none'",
        ];
    }

    public static function allowed(array $server, bool $enabled): bool
    {
        return $enabled && ($server['HTTP_HOST'] ?? null) === '127.0.0.1:8765'
            && ($server['SERVER_PORT'] ?? null) === '8765' && ($server['REMOTE_ADDR'] ?? null) === '127.0.0.1';
    }

    public static function handle(AuthorizationCodeProfile $profile, array $server, array $post, array &$session, bool $enabled = false, ?MockHandler $mock = null, bool $inspectOnly = false): array
    {
        if (! self::allowed($server, $enabled)) {
            unset($session['pending']);

            return [403, [], 'diagnostic_disabled'];
        }
        $method = $server['REQUEST_METHOD'] ?? '';
        $uri = $server['REQUEST_URI'] ?? '';
        $path = explode('?', $uri, 2)[0];
        if ($inspectOnly && $path === '/oauth/callback') {
            unset($session['pending']);

            return [423, ['Content-Type' => 'application/json; charset=utf-8'], '{"source":"local_diagnostic","mode":"start_inspection","error":"oauth_paused","provider_requests":0}'];
        }
        if ($inspectOnly && $path === '/oauth/start') {
            $observation = self::startObservation($server, $post, $session);
            if ($observation['accepted']) {
                unset($session['csrf']);
            }
            unset($session['pending']);

            return [$observation['accepted'] ? 200 : 403, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($observation, JSON_THROW_ON_ERROR)];
        }
        if ($path === '/oauth/callback' && $method === 'GET') {
            $query = explode('?', $uri, 2)[1] ?? '';
            $report = $mock === null ? AuthorizationCodeDiagnostic::live($profile, $session, $query, true)
                : AuthorizationCodeDiagnostic::simulate($profile, $session, $query, $mock, time());

            return [200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($report, JSON_THROW_ON_ERROR)];
        }
        if ($path === '/' && $method === 'GET' && $uri === '/') {
            $session['csrf'] = bin2hex(random_bytes(32));
            $html = '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Diagnostic OAuth sandbox</title>'
                .'<h1>Burger Queen — diagnostic OAuth sandbox</h1><p>Entreprise sandbox / 000000002. Aucun enregistrement de connexion métier.</p>'
                .'<form method="post" action="/oauth/start"><input type="hidden" name="csrf" value="'.$session['csrf'].'">'
                .'<button>'.($inspectOnly ? 'Tester le formulaire local (sans OAuth)' : 'Tester le consentement SUPER PDP').'</button></form>'
                .($inspectOnly ? '<p>Observation locale active : aucun consentement ou appel SUPER PDP ne sera lancé.</p>' : '').'</html>';

            return [200, ['Content-Type' => 'text/html; charset=utf-8'], $html];
        }
        if ($path === '/oauth/start' && $method === 'POST' && $uri === '/oauth/start') {
            $observation = self::startObservation($server, $post, $session);
            if (! $observation['accepted']) {
                return [403, ['Content-Type' => 'application/json; charset=utf-8'], json_encode([
                    'source' => 'local_diagnostic', 'error' => 'request_refused', 'reason' => $observation['reason'],
                    'restart_url' => $observation['restart_url'], 'next_action' => $observation['next_action'], 'provider_requests' => 0,
                ], JSON_THROW_ON_ERROR)];
            }

            // A refused request must not invalidate a legitimate form (for
            // example, a cross-origin POST preceding its actual submission).
            // Consume only after every predicate passes, under the session lock.
            unset($session['csrf']);
            return [303, ['Location' => $profile->begin($session, time())], ''];
        }
        if ($path === '/oauth/callback') {
            unset($session['pending']);
        }

        return [404, [], 'request_refused'];
    }
}
