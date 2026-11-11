<?php

declare(strict_types=1);

namespace SimpleDevis\Tools\SuperPdp;

use App\Services\SuperPdp\Emission\EmissionJson;
use Bluerock\SuperPdp\OAuth\SuperPdpProvider;
use Bluerock\SuperPdp\SuperPdpConnector;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Saloon\Contracts\Sender;
use Saloon\Http\Senders\GuzzleSender;
use Throwable;

/** No Laravel boot, dotenv environment loading, models, database or token persistence. */
final readonly class DiagnosticProfile
{
    public const ORIGIN = 'https://api.superpdp.tech';

    public const TOKEN = self::ORIGIN.'/oauth2/token';

    public const COMPANY = self::ORIGIN.'/v1.beta/companies/me';

    public const API = self::ORIGIN.'/v1.beta';

    private function __construct(
        #[\SensitiveParameter] private string $clientId,
        #[\SensitiveParameter] private string $clientSecret,
    ) {}

    public static function fromFile(string $path): self
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 65536) {
            throw new RuntimeException('profile_invalid');
        }
        $text = file_get_contents($path);
        if (! is_string($text)) {
            throw new RuntimeException('profile_invalid');
        }

        return self::fromText($text);
    }

    public static function fromText(#[\SensitiveParameter] string $text): self
    {
        $required = ['APP_ENV', 'SUPERPDP_ENVIRONMENT', 'SUPERPDP_CLIENT_ID', 'SUPERPDP_CLIENT_SECRET', 'SUPERPDP_API_URL', 'SUPERPDP_TOKEN_URL'];
        $selected = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) || ! in_array($match[1], $required, true)) {
                continue;
            }
            if (isset($selected[$match[1]]) || str_contains($line, '${')) {
                throw new RuntimeException('profile_invalid');
            }
            $selected[$match[1]] = $line;
        }
        try {
            $values = Dotenv::parse(implode("\n", $selected));
        } catch (Throwable) {
            throw new RuntimeException('profile_invalid');
        }
        if (($values['APP_ENV'] ?? null) !== 'sandbox' || ($values['SUPERPDP_ENVIRONMENT'] ?? null) !== 'sandbox'
            || ($values['SUPERPDP_TOKEN_URL'] ?? null) !== self::TOKEN || ($values['SUPERPDP_API_URL'] ?? null) !== self::API) {
            throw new RuntimeException('profile_invalid');
        }
        foreach (['SUPERPDP_CLIENT_ID', 'SUPERPDP_CLIENT_SECRET'] as $key) {
            if (! is_string($values[$key] ?? null) || trim($values[$key]) === '' || preg_match('/[\x00-\x1f\x7f]/', $values[$key])) {
                throw new RuntimeException('profile_invalid');
            }
        }

        return new self($values['SUPERPDP_CLIENT_ID'], $values['SUPERPDP_CLIENT_SECRET']);
    }

    public function providerOptions(): array
    {
        return ['baseUrl' => self::ORIGIN, 'clientId' => $this->clientId, 'clientSecret' => $this->clientSecret];
    }

    public function matchesCredentials(#[\SensitiveParameter] array $body): bool
    {
        return ($body['client_id'] ?? null) === $this->clientId && ($body['client_secret'] ?? null) === $this->clientSecret;
    }

    public function __debugInfo(): array
    {
        return ['environment' => 'sandbox', 'credentials' => '[redacted]'];
    }
}

final class DiagnosticFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct('Diagnostic request refused.');
    }
}

/** Final transport boundary shared by League OAuth and the Saloon SDK. */
final class RestrictedDiagnosticHandler
{
    public int $requests = 0;

    public string $phase = 'token';

    private ?string $issuedToken = null;

    private readonly \Closure $backend;

    public function __construct(private readonly DiagnosticProfile $profile, callable $backend)
    {
        $this->backend = \Closure::fromCallable($backend);
    }

    public function __invoke(#[\SensitiveParameter] RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        $tokenRequest = $this->requests === 0 && $request->getMethod() === 'POST' && $url === DiagnosticProfile::TOKEN;
        $companyRequest = $this->requests === 1 && $this->issuedToken !== null
            && $request->getMethod() === 'GET' && $url === DiagnosticProfile::COMPANY;
        if ((! $tokenRequest && ! $companyRequest) || ($options['allow_redirects'] ?? null) !== false
            || ($options['verify'] ?? null) !== true || ($options['debug'] ?? false) !== false) {
            throw new DiagnosticFailure('request_guard_refused');
        }
        if ($tokenRequest) {
            parse_str((string) $request->getBody(), $body);
            if ($request->hasHeader('Authorization') || ($body['grant_type'] ?? null) !== 'client_credentials'
                || ! $this->profile->matchesCredentials($body)
                || array_diff(array_keys($body), ['grant_type', 'client_id', 'client_secret', 'scope', 'redirect_uri']) !== []
                || ! in_array($body['scope'] ?? '', ['', null], true) || ! in_array($body['redirect_uri'] ?? '', ['', null], true)
                || ! str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
                throw new DiagnosticFailure('request_guard_refused');
            }
        } elseif ($request->getHeaderLine('Authorization') !== 'Bearer '.$this->issuedToken || (string) $request->getBody() !== '') {
            throw new DiagnosticFailure('request_guard_refused');
        }
        // Responses remain in RAM, even if an endpoint returns an oversized body.
        $options['sink'] = \GuzzleHttp\Psr7\Utils::streamFor(fopen('php://memory', 'w+'));
        $this->phase = $tokenRequest ? 'token' : 'company';
        $this->requests++;

        return ($this->backend)($request, $options)->then(function (ResponseInterface $response) use ($tokenRequest) {
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                throw new DiagnosticFailure($tokenRequest ? 'token_redirect_refused' : 'company_redirect_refused', $status);
            }
            if ($status !== 200) {
                $code = $tokenRequest ? 'token_request_failed' : 'company_request_failed';
                if ($tokenRequest && in_array($status, [400, 401, 403], true)) {
                    $code = 'authentication_refused';
                }
                throw new DiagnosticFailure($code, $status);
            }
            if (strlen((string) $response->getBody()) > 65536) {
                throw new DiagnosticFailure($tokenRequest ? 'token_response_invalid' : 'company_response_invalid', $status);
            }
            try {
                // This existing utility is pure PHP: no Laravel services or container.
                $data = EmissionJson::object((string) $response->getBody());
            } catch (Throwable) {
                throw new DiagnosticFailure($tokenRequest ? 'token_response_invalid' : 'company_response_invalid', $status);
            }
            if ($tokenRequest) {
                if (! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
                    || preg_match('/[\x00-\x20\x7f]/', $data['access_token'])
                    || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0
                    || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0 || $data['expires_in'] > PHP_INT_MAX - time()) {
                    throw new DiagnosticFailure('token_response_invalid', $status);
                }
                $this->issuedToken = $data['access_token'];
            }

            return $response;
        });
    }

    public function __debugInfo(): array
    {
        return ['requests' => $this->requests, 'phase' => $this->phase, 'credentials' => '[redacted]'];
    }
}

final class ClientCredentialsDiagnostic
{
    public static function offline(DiagnosticProfile $profile): array
    {
        return self::result('offline', 'not_attempted', 'not_checked', null, 0);
    }

    /** Test-only entry point: the caller can supply only Guzzle's in-memory mock handler. */
    public static function simulate(DiagnosticProfile $profile, MockHandler $handler): array
    {
        return self::run($profile, $handler, 'simulated');
    }

    public static function live(DiagnosticProfile $profile, bool $explicitlyApproved = false): array
    {
        if (! $explicitlyApproved) {
            return self::result('offline', 'not_attempted', 'not_checked', 'explicit_confirmation_required', 0);
        }

        return self::run($profile, Utils::chooseHandler(), 'live');
    }

    private static function run(DiagnosticProfile $profile, callable $backend, string $mode): array
    {
        $guard = new RestrictedDiagnosticHandler($profile, $backend);
        $client = new Client([
            'handler' => HandlerStack::create($guard), 'allow_redirects' => false, 'verify' => true,
            'http_errors' => false, 'timeout' => 20, 'connect_timeout' => 5, 'cookies' => false, 'debug' => false, 'proxy' => '',
            'headers' => ['Accept' => 'application/json'],
            'on_headers' => static function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > 65536) {
                    throw new RuntimeException('response_too_large');
                }
            },
            'progress' => static function ($total, $downloaded): void {
                if ($downloaded > 65536) {
                    throw new RuntimeException('response_too_large');
                }
            },
        ]);
        $authenticated = false;
        try {
            $provider = new SuperPdpProvider($profile->providerOptions(), ['httpClient' => $client]);
            // The bundled provider uses client_secret_post. No callback, PKCE or scope grant.
            $token = $provider->getAccessToken('client_credentials', ['scope' => [], 'redirect_uri' => null]);
            $authenticated = true;
            $connector = new class($token->getToken(), $client) extends SuperPdpConnector
            {
                public function __construct(#[\SensitiveParameter] string $token, private readonly Client $diagnosticClient)
                {
                    parent::__construct(baseUrl: DiagnosticProfile::ORIGIN, accessToken: $token);
                    $this->tries = 1;
                    $this->config()->merge(['allow_redirects' => false, 'verify' => true, 'debug' => false, 'timeout' => 20, 'connect_timeout' => 5]);
                }

                protected function defaultSender(): Sender
                {
                    return new class($this->diagnosticClient) extends GuzzleSender
                    {
                        public function __construct(Client $client)
                        {
                            $this->client = $client;
                            $this->handlerStack = $client->getConfig('handler');
                        }
                    };
                }
            };
            $response = $connector->company()->me();
            $company = EmissionJson::object($response->body());
            if (($company['env'] ?? null) !== 'sandbox') {
                return self::result($mode, 'succeeded', 'not_confirmed',
                    ($company['env'] ?? null) === 'production' ? 'production_company_refused' : 'sandbox_not_confirmed', $guard->requests, 200);
            }
            if (! is_int($company['id'] ?? null) || $company['id'] <= 0) {
                return self::result($mode, 'succeeded', 'not_confirmed', 'company_response_invalid', $guard->requests, 200);
            }

            return self::result($mode, 'succeeded', 'confirmed', null, $guard->requests, 200);
        } catch (Throwable $exception) {
            $reason = $exception instanceof DiagnosticFailure ? $exception->reason : $guard->phase.'_request_failed';
            $status = $exception instanceof DiagnosticFailure ? $exception->httpStatus : null;

            return self::result($mode, $authenticated ? 'succeeded' : ($reason === 'authentication_refused' ? 'refused' : 'not_confirmed'),
                'not_confirmed', $reason, $guard->requests, $status);
        }
    }

    private static function result(string $mode, string $authentication, string $sandbox, ?string $error, int $requests, ?int $httpStatus = null): array
    {
        return ['mode' => $mode, 'authentication' => $authentication, 'sandbox_environment' => $sandbox,
            'error' => $error, 'http_status' => $httpStatus, 'requests' => $requests];
    }
}
