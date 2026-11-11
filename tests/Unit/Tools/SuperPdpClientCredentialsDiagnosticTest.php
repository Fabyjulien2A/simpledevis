<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use Saloon\Config;
use SimpleDevis\Tools\SuperPdp\ClientCredentialsDiagnostic;
use SimpleDevis\Tools\SuperPdp\DiagnosticFailure;
use SimpleDevis\Tools\SuperPdp\DiagnosticProfile;
use SimpleDevis\Tools\SuperPdp\RestrictedDiagnosticHandler;

require_once dirname(__DIR__, 3).'/tools/support/SuperPdpClientCredentialsDiagnostic.php';

// Intentionally PHPUnit's plain TestCase: never Tests\TestCase, app(), DB or migrations.
beforeEach(function () {
    // Laravel tests run earlier may leave a flushed facade application behind.
    // Prove this standalone diagnostic works without one, regardless of test order.
    $this->previousFacadeApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);
    Config::allowStrayRequests(); // The SDK uses the test's locked Guzzle MockHandler sender.
    $this->text = implode("\n", [
        'APP_ENV=sandbox', 'SUPERPDP_ENVIRONMENT=sandbox', 'SUPERPDP_API_URL='.DiagnosticProfile::API,
        'SUPERPDP_TOKEN_URL='.DiagnosticProfile::TOKEN, 'SUPERPDP_CLIENT_ID=TEST-CLIENT-NOT-REAL',
        'SUPERPDP_CLIENT_SECRET=SECRET-DO-NOT-DISCLOSE', 'DB_DATABASE=DO-NOT-LOAD', 'DB_PASSWORD=DATABASE-SECRET',
        'UNRELATED="${DO_NOT_RESOLVE}"',
    ]);
    $this->profile = DiagnosticProfile::fromText($this->text);
    $this->tokenData = ['access_token' => 'TOKEN-DO-NOT-DISCLOSE', 'token_type' => 'Bearer', 'expires_in' => 900];
    $this->tokenResponse = fn (?array $data = null) => new Response(200, ['Content-Type' => 'application/json'], json_encode($data ?? $this->tokenData));
    $this->companyResponse = fn (array $override = []) => new Response(200, ['Content-Type' => 'application/json'], json_encode($override + ['id' => 123, 'env' => 'sandbox']));
});

afterEach(function () {
    Facade::setFacadeApplication($this->previousFacadeApplication);
    Config::allowStrayRequests();
});

it('checks configuration offline without authentication or Laravel application boot', function () {
    expect(Facade::getFacadeApplication())->toBeNull();
    $beforeDatabase = getenv('DB_DATABASE');
    $beforeSecret = getenv('SUPERPDP_CLIENT_SECRET');
    $result = ClientCredentialsDiagnostic::offline($this->profile);
    expect($result)->toMatchArray(['mode' => 'offline', 'authentication' => 'not_attempted', 'sandbox_environment' => 'not_checked', 'requests' => 0])
        ->and(getenv('DB_DATABASE'))->toBe($beforeDatabase)->and(getenv('SUPERPDP_CLIENT_SECRET'))->toBe($beforeSecret);
    expect(json_encode($result))->not->toContain('SECRET-', 'TOKEN-', 'DATABASE-', 'TEST-CLIENT');
});

it('requires explicit live authorization before choosing any native transport', function () {
    $result = ClientCredentialsDiagnostic::live($this->profile);
    expect($result['error'])->toBe('explicit_confirmation_required')->and($result['requests'])->toBe(0);
});

it('uses the bundled provider and SDK for the exact two requests and only reports safe fields', function () {
    $requests = [];
    $optionsSeen = [];
    $handler = null;
    $handler = new MockHandler([
        ($this->tokenResponse)(),
        new Response(200, ['Content-Type' => 'application/json'], '{"id":123,"env":"sandbox","number_scheme":"sandbox"}'),
    ], function () use (&$handler, &$requests, &$optionsSeen) {
        $requests[] = $handler->getLastRequest();
        $optionsSeen[] = $handler->getLastOptions();
    });
    $result = ClientCredentialsDiagnostic::simulate($this->profile, $handler);
    expect($result)->toMatchArray(['mode' => 'simulated', 'authentication' => 'succeeded', 'sandbox_environment' => 'confirmed', 'error' => null, 'requests' => 2])
        ->and(count($requests))->toBe(2)->and(Facade::getFacadeApplication())->toBeNull();
    expect((string) $requests[0]->getUri())->toBe(DiagnosticProfile::TOKEN)->and($requests[0]->getMethod())->toBe('POST')
        ->and($requests[0]->hasHeader('Authorization'))->toBeFalse();
    parse_str((string) $requests[0]->getBody(), $body);
    expect($body['grant_type'])->toBe('client_credentials')->and($body['client_id'])->toBe('TEST-CLIENT-NOT-REAL')
        ->and($body['client_secret'])->toBe('SECRET-DO-NOT-DISCLOSE')->and($body)->not->toHaveKeys(['code', 'code_verifier', 'refresh_token']);
    expect((string) $requests[1]->getUri())->toBe(DiagnosticProfile::COMPANY)->and($requests[1]->getMethod())->toBe('GET')
        ->and($requests[1]->getHeaderLine('Authorization'))->toBe('Bearer TOKEN-DO-NOT-DISCLOSE')->and((string) $requests[1]->getBody())->toBe('');
    foreach ($optionsSeen as $options) {
        expect($options['allow_redirects'])->toBeFalse()->and($options['verify'])->toBeTrue()->and($options['debug'])->toBeFalse()
            ->and($options['proxy'])->toBe('');
    }
    expect(json_encode($result))->not->toContain('TOKEN-', 'SECRET-', 'TEST-CLIENT');
});

it('rejects foreign endpoints production profiles absent credentials and ambiguous configuration', function (string $case) {
    $text = match ($case) {
        'app_production' => str_replace('APP_ENV=sandbox', 'APP_ENV=production', $this->text),
        'oauth_production' => str_replace('SUPERPDP_ENVIRONMENT=sandbox', 'SUPERPDP_ENVIRONMENT=production', $this->text),
        'foreign_api' => str_replace(DiagnosticProfile::API, 'https://other.invalid/v1.beta', $this->text),
        'foreign_token' => str_replace(DiagnosticProfile::TOKEN, 'https://other.invalid/oauth2/token', $this->text),
        'token_query' => str_replace(DiagnosticProfile::TOKEN, DiagnosticProfile::TOKEN.'?extra=true', $this->text),
        'token_fragment' => str_replace(DiagnosticProfile::TOKEN, '"'.DiagnosticProfile::TOKEN.'#secret"', $this->text),
        'api_slash' => str_replace('SUPERPDP_API_URL='.DiagnosticProfile::API, 'SUPERPDP_API_URL='.DiagnosticProfile::API.'/', $this->text),
        'secret_empty' => str_replace('SECRET-DO-NOT-DISCLOSE', '', $this->text),
        'id_empty' => str_replace('TEST-CLIENT-NOT-REAL', '', $this->text),
        'secret_missing' => str_replace('SUPERPDP_CLIENT_SECRET=SECRET-DO-NOT-DISCLOSE', '', $this->text),
        'duplicate' => $this->text."\nSUPERPDP_ENVIRONMENT=sandbox",
        'interpolation' => str_replace('SECRET-DO-NOT-DISCLOSE', '${PRODUCTION_SECRET}', $this->text),
        'malformed_quote' => str_replace('SECRET-DO-NOT-DISCLOSE', '"unterminated', $this->text),
    };
    try {
        DiagnosticProfile::fromText($text);
        $this->fail('Configuration unexpectedly accepted: '.$case);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('profile_invalid');
    }
})->with(['app_production', 'oauth_production', 'foreign_api', 'foreign_token', 'token_query', 'token_fragment', 'api_slash',
    'secret_empty', 'id_empty', 'secret_missing', 'duplicate', 'interpolation', 'malformed_quote']);

it('never follows token redirects or leaks remote error descriptions', function (int $status, string $error) {
    $handler = new MockHandler([new Response($status, ['Location' => 'https://forbidden.invalid'], '{"error_description":"SECRET-DO-NOT-DISCLOSE"}')]);
    $result = ClientCredentialsDiagnostic::simulate($this->profile, $handler);
    expect($result['requests'])->toBe(1)->and($result['error'])->toBe($error)->and($result['sandbox_environment'])->not->toBe('confirmed')
        ->and(json_encode($result))->not->toContain('SECRET-', 'forbidden.invalid', 'error_description');
})->with([[302, 'token_redirect_refused'], [400, 'authentication_refused'], [401, 'authentication_refused'], [403, 'authentication_refused'],
    [429, 'token_request_failed'], [500, 'token_request_failed']]);

it('rejects malformed ambiguous expired or non-bearer token responses before reading the company', function (string $case) {
    $data = $this->tokenData;
    match ($case) {
        'empty' => $data['access_token'] = '',
        'array' => $data['access_token'] = ['token'],
        'header_injection' => $data['access_token'] = "TOKEN\r\nInjected: secret",
        'type' => $data['token_type'] = 'MAC',
        'type_array' => $data['token_type'] = [],
        'expiry_missing' => $data = array_diff_key($data, ['expires_in' => true]),
        'expiry_string' => $data['expires_in'] = '900',
        'expired' => $data['expires_in'] = 0,
        default => null,
    };
    $body = match ($case) {
        'malformed' => 'TOKEN-DO-NOT-DISCLOSE',
        'duplicate' => '{"access_token":"one","access_token":"two","token_type":"Bearer","expires_in":900}',
        default => json_encode($data),
    };
    $result = ClientCredentialsDiagnostic::simulate($this->profile, new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]));
    expect($result['error'])->toBe('token_response_invalid')->and($result['requests'])->toBe(1)
        ->and($result['authentication'])->not->toBe('succeeded')->and(json_encode($result))->not->toContain('TOKEN-', 'SECRET-');
})->with(['empty', 'array', 'header_injection', 'type', 'type_array', 'expiry_missing', 'expiry_string', 'expired', 'malformed', 'duplicate']);

it('refuses production unknown or malformed company identities even after successful authentication', function (string $case, string $error) {
    $body = match ($case) {
        'production' => '{"id":123,"env":"production"}',
        'missing' => '{"id":123}',
        'unknown' => '{"id":123,"env":"SECRET-DO-NOT-DISCLOSE"}',
        'uppercase' => '{"id":123,"env":"SANDBOX"}',
        'id_missing' => '{"env":"sandbox"}',
        'id_invalid' => '{"id":0,"env":"sandbox"}',
        'malformed' => 'TOKEN-DO-NOT-DISCLOSE',
        'duplicate' => '{"id":123,"env":"production","env":"sandbox"}',
    };
    $result = ClientCredentialsDiagnostic::simulate($this->profile, new MockHandler([($this->tokenResponse)(), new Response(200, [], $body)]));
    expect($result['authentication'])->toBe('succeeded')->and($result['sandbox_environment'])->toBe('not_confirmed')
        ->and($result['error'])->toBe($error)->and($result['requests'])->toBe(2)
        ->and(json_encode($result))->not->toContain('TOKEN-', 'SECRET-');
})->with([['production', 'production_company_refused'], ['missing', 'sandbox_not_confirmed'], ['unknown', 'sandbox_not_confirmed'],
    ['uppercase', 'sandbox_not_confirmed'], ['id_missing', 'company_response_invalid'], ['id_invalid', 'company_response_invalid'],
    ['malformed', 'company_response_invalid'], ['duplicate', 'company_response_invalid']]);

it('does not follow company redirects or retry HTTP errors', function (int $status) {
    $handler = new MockHandler([($this->tokenResponse)(), new Response($status, ['Location' => 'https://forbidden.invalid'], 'SECRET-DO-NOT-DISCLOSE')]);
    $result = ClientCredentialsDiagnostic::simulate($this->profile, $handler);
    expect($result['error'])->toBe($status === 302 ? 'company_redirect_refused' : 'company_request_failed')
        ->and($result['requests'])->toBe(2)->and(json_encode($result))->not->toContain('SECRET-', 'TOKEN-');
})->with([302, 401, 403, 500]);

it('sanitizes transport exceptions and performs no retry', function (bool $duringCompany) {
    $queue = $duringCompany ? [($this->tokenResponse)(), new RuntimeException('SECRET-DO-NOT-DISCLOSE')]
        : [new RuntimeException('SECRET-DO-NOT-DISCLOSE')];
    $result = ClientCredentialsDiagnostic::simulate($this->profile, new MockHandler($queue));
    expect($result['requests'])->toBe($duringCompany ? 2 : 1)->and($result['error'])->toBe($duringCompany ? 'company_request_failed' : 'token_request_failed')
        ->and(json_encode($result))->not->toContain('SECRET-');
})->with([true, false]);

it('bounds response bodies', function () {
    $result = ClientCredentialsDiagnostic::simulate($this->profile, new MockHandler([new Response(200, [], str_repeat('x', 65537))]));
    expect($result['error'])->toBe('token_response_invalid')->and($result['requests'])->toBe(1);
});

it('blocks deposits alternate URLs retries and insecure transport options at the final boundary', function (string $method, string $url, array $options) {
    $handler = new RestrictedDiagnosticHandler($this->profile, new MockHandler([]));
    $request = new Request($method, $url);
    expect(fn () => $handler($request, $options + ['allow_redirects' => false, 'verify' => true, 'debug' => false]))
        ->toThrow(DiagnosticFailure::class);
    expect($handler->requests)->toBe(0);
})->with([
    ['POST', DiagnosticProfile::API.'/invoices', []], ['GET', DiagnosticProfile::COMPANY, []],
    ['POST', 'https://forbidden.invalid/oauth2/token', []], ['POST', DiagnosticProfile::TOKEN.'?extra=1', []],
    ['POST', DiagnosticProfile::TOKEN, ['allow_redirects' => true]], ['POST', DiagnosticProfile::TOKEN, ['verify' => false]],
    ['POST', DiagnosticProfile::TOKEN, ['debug' => true]],
]);

it('blocks a third request after the two permitted operations', function () {
    $handler = new RestrictedDiagnosticHandler($this->profile, new MockHandler([($this->tokenResponse)(), ($this->companyResponse)()]));
    $options = ['allow_redirects' => false, 'verify' => true, 'debug' => false];
    $body = http_build_query(['grant_type' => 'client_credentials', 'client_id' => 'TEST-CLIENT-NOT-REAL', 'client_secret' => 'SECRET-DO-NOT-DISCLOSE']);
    $handler(new Request('POST', DiagnosticProfile::TOKEN, ['Content-Type' => 'application/x-www-form-urlencoded'], $body), $options)->wait();
    $handler(new Request('GET', DiagnosticProfile::COMPANY, ['Authorization' => 'Bearer TOKEN-DO-NOT-DISCLOSE']), $options)->wait();
    expect(fn () => $handler(new Request('GET', DiagnosticProfile::COMPANY, ['Authorization' => 'Bearer TOKEN-DO-NOT-DISCLOSE']), $options))
        ->toThrow(DiagnosticFailure::class);
    expect($handler->requests)->toBe(2);
});
