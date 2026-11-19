<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use App\Services\SuperPdp\SellerEmissionQualification;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Tests\TestCase;

// SDK response objects are built in memory: no sender, SQL, credentials or invoice creation.
uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00+00:00'));
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.client_id' => 'unit-client',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->connection = (new SuperPdpConnection)->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'status' => 'connected',
        'environment' => 'sandbox', 'oauth_client_id' => 'unit-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'directory_identifier' => '000000002', 'access_token_expires_at' => now()->addHour(),
    ]);
    $this->connection->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $this->qualifier = new SellerEmissionQualification;
    $remote = ['id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002', 'country' => 'FR', 'env' => 'sandbox'];
    $this->data = [
        'company' => $remote,
        'session' => ['client_id' => 'unit-client', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'],
        'directory' => ['data' => [
            ['company' => $remote, 'identifier' => '0225:315143296_18172', 'directory' => 'peppol', 'status' => 'created'],
            ['company' => $remote, 'identifier' => '0225:315143296_18172_replyto', 'directory' => 'peppol', 'status' => 'created', 'is_replyto' => true],
        ]],
    ];
    $this->response = function (string $endpoint, array|string $body, string $source = 'simulated', int $status = 200, string $method = 'GET', ?string $url = null, string $authorization = 'Bearer unit-access') {
        $paths = ['company' => 'companies/me', 'session' => 'oauth2_sessions/me', 'directory' => 'directory_entries'];
        $request = new Request($method, $url ?? 'https://api.superpdp.tech/v1.beta/'.$paths[$endpoint], ['Authorization' => $authorization]);
        $response = new Response(new PsrResponse($status, [], is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body), $this->createMock(PendingRequest::class), $request);
        if ($source === 'simulated') {
            $response->setMocked(true);
        } elseif ($source === 'cached') {
            $response->setCached(true);
        }

        return $response;
    };
    $this->proof = fn (string $endpoint, string $source = 'simulated') => SandboxSellerEvidence::fromResponse(($this->response)($endpoint, $this->data[$endpoint], $source));
    $this->assess = fn (array $proofs = []) => $this->qualifier->assessSandbox($this->context, $this->company, $this->connection,
        $proofs['company'] ?? ($this->proof)('company'), $proofs['session'] ?? ($this->proof)('session'), $proofs['directory'] ?? ($this->proof)('directory'));
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
    Http::assertNothingSent();
});

it('qualifies the approved sandbox identity without a SIRET and checks Peppol independently', function () {
    $before = [$this->company->getAttributes(), $this->connection->getAttributes()];
    $result = ($this->assess)();
    expect($result['checks_passed_for_simulation'])->toBeTrue()->and($result['checks']['seller_identity'])->toBeTrue()
        ->and($result['checks']['seller_peppol_address'])->toBeTrue()->and($result['reasons'])->toBe([])
        ->and($result['status'])->toBe('not_verified')->and($result['verified'])->toBeFalse()
        ->and($result['ready_for_emission'])->toBeFalse()->and($result)->not->toHaveKey('seller_siren')
        ->and([$this->company->getAttributes(), $this->connection->getAttributes()])->toBe($before);
});

it('applies live SDK provenance semantics using only synthetic response objects without performing HTTP', function () {
    $result = ($this->assess)([
        'company' => ($this->proof)('company', 'sdk_response'),
        'session' => ($this->proof)('session', 'sdk_response'),
        'directory' => ($this->proof)('directory', 'sdk_response'),
    ]);
    expect($result['status'])->toBe('passed')->and($result['verified'])->toBeTrue()
        ->and($result['pending_evidence'])->toBe([])->and($result['ready_for_emission'])->toBeFalse();
});

it('never promotes cached or mixed evidence to a verified qualification', function (string $source) {
    $result = ($this->assess)(['company' => ($this->proof)('company', $source)]);
    expect($result['status'])->toBe('not_verified')->and($result['verified'])->toBeFalse()
        ->and($result['pending_evidence'])->toBe(['real_authenticated_checks']);
})->with(['cached', 'sdk_response']);

it('refuses production scope or a changed local owner even after context construction', function (string $change) {
    match ($change) {
        'app_production' => $this->app->instance('env', 'production'),
        'config_production' => config(['app.env' => 'production']),
        'provider_production' => config(['services.superpdp.environment' => 'production']),
        'owner' => $this->company->user_id = 4,
        'company' => $this->company->id = 4,
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
        'guest' => auth()->logout(),
    };
    expect(fn () => ($this->assess)())->toThrow(RuntimeException::class);
})->with(['app_production', 'config_production', 'provider_production', 'owner', 'company', 'binding', 'guest']);

it('refuses an inconsistent local OAuth connection or unusable expiry without reading tokens', function (string $field, mixed $value) {
    $this->connection->setAttribute($field, $value);
    expect(fn () => ($this->assess)())->toThrow(RuntimeException::class);
})->with([
    ['company_id', 4], ['superpdp_company_id', null], ['superpdp_company_id', 0], ['status', 'disconnected'],
    ['environment', 'production'], ['oauth_client_id', 'another-client'], ['api_url', 'https://foreign.invalid'],
    ['directory_identifier', '000000001'], ['access_token_expires_at', null],
    ['access_token_expires_at', '2026-11-13 16:59:00'], ['access_token_expires_at', '2026-11-13 17:04:59'],
]);

it('compares every remote company identity field exactly', function (string $field, mixed $value) {
    $this->data['company'][$field] = $value;
    $result = ($this->assess)();
    expect($result['status'])->toBe('refused')->and($result['checks']['seller_identity'])->toBeFalse()
        ->and($result['checks']['seller_peppol_address'])->toBeTrue()->and($result['verified'])->toBeFalse();
})->with([
    ['id', 18171], ['id', '18172'], ['number_scheme', 'fr_siren'], ['number', '000000001'],
    ['number', 2], ['env', 'production'], ['country', 'BE'], ['number_scheme', null],
]);

it('requires the expected OAuth client and both verified statuses', function (string $field, mixed $value) {
    $this->data['session'][$field] = $value;
    expect(($this->assess)()['status'])->toBe('refused');
})->with([
    ['client_id', 'another-client'], ['client_id', null],
    ['company_verification_status', 'needs_review'], ['company_verification_status', 'failed'], ['company_verification_status', null],
    ['user_identity_verification_status', 'not_verified'], ['user_identity_verification_status', 'failed'], ['user_identity_verification_status', null],
]);

it('refuses missing technical foreign inactive or ambiguous Peppol evidence independently of identity', function (string $change) {
    $entry = &$this->data['directory']['data'][0];
    match ($change) {
        'replyto_flag' => $entry['is_replyto'] = true,
        'replyto_only' => $this->data['directory']['data'] = [$this->data['directory']['data'][1]],
        'wrong_address' => $entry['identifier'] = '0225:315143296_18171',
        'wrong_scheme' => $entry['identifier'] = 'EM:sender@example.invalid',
        'ppf' => $entry['directory'] = 'ppf',
        'pending' => $entry['status'] = 'pending',
        'error' => $entry['status'] = 'error',
        'foreign_id' => $entry['company']['id'] = 18171,
        'foreign_identity' => $entry['company']['number'] = '000000001',
        'production' => $entry['company']['env'] = 'production',
        'invalid_flag' => $entry['is_replyto'] = 'false',
        'duplicate' => $this->data['directory']['data'][] = $entry,
        'future_effective' => $entry['effective_date'] = '2026-11-14T00:00:00+01:00',
        'invalid_effective' => $entry['effective_date'] = '2026-02-30',
        'invalid_effective_suffix' => $entry['effective_date'] = '2026-11-13-forged',
        'incomplete' => $this->data['directory']['has_more'] = true,
        'empty' => $this->data['directory']['data'] = [],
    };
    unset($entry);
    $result = ($this->assess)();
    expect($result['status'])->toBe('refused')->and($result['checks']['seller_peppol_address'])->toBeFalse()
        ->and($result['checks']['seller_identity'])->toBeTrue();
})->with(['replyto_flag', 'replyto_only', 'wrong_address', 'wrong_scheme', 'ppf', 'pending', 'error',
    'foreign_id', 'foreign_identity', 'production', 'invalid_flag', 'duplicate', 'future_effective', 'invalid_effective', 'invalid_effective_suffix', 'incomplete', 'empty']);

it('accepts the optional false replyto flag and a valid effective date with the approved exact address', function () {
    $this->data['directory']['data'][0]['is_replyto'] = false;
    $this->data['directory']['data'][0]['effective_date'] = '2026-11-13T00:00:00+01:00';
    expect(($this->assess)()['checks_passed_for_simulation'])->toBeTrue();
});

it('refuses expired or future evidence for each independent endpoint', function (string $endpoint, int $age) {
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00+00:00')->subSeconds($age));
    $proof = ($this->proof)($endpoint);
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00+00:00'));
    $result = ($this->assess)([$endpoint => $proof]);
    expect($result['status'])->toBe('refused')->and($result['checks'][$endpoint.'_evidence'])->toBeFalse();
})->with([['company', 901], ['session', 901], ['directory', 901], ['company', -1], ['session', -1], ['directory', -1]]);

it('accepts evidence exactly fifteen minutes old but never grants deposit permission', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-13T16:45:00+00:00'));
    $proofs = ['company' => ($this->proof)('company'), 'session' => ($this->proof)('session'), 'directory' => ($this->proof)('directory')];
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00+00:00'));
    $result = ($this->assess)($proofs);
    expect($result['checks_passed_for_simulation'])->toBeTrue()->and($result['ready_for_emission'])->toBeFalse();
});

it('refuses endpoint swapping and credentials from different observations', function (string $change) {
    $proof = $change === 'endpoint' ? ($this->proof)('company') : SandboxSellerEvidence::fromResponse(
        ($this->response)('session', $this->data['session'], 'simulated', 200, 'GET', null, 'Bearer another-unit-access'),
    );
    expect(($this->assess)(['session' => $proof])['status'])->toBe('refused');
})->with(['endpoint', 'credentials']);

it('rejects non-read non-official non-200 or unauthenticated receipts without a request', function (string $change) {
    $response = ($this->response)('company', $this->data['company'], 'simulated',
        $change === 'http' ? 403 : 200, $change === 'method' ? 'POST' : 'GET',
        match ($change) {
            'origin' => 'https://foreign.invalid/v1.beta/companies/me',
            'query' => 'https://api.superpdp.tech/v1.beta/companies/me?company_id=18171',
            default => null,
        }, $change === 'auth' ? '' : 'Bearer unit-access');
    expect(fn () => SandboxSellerEvidence::fromResponse($response))->toThrow(InvalidArgumentException::class);
})->with(['http', 'method', 'origin', 'query', 'auth']);

it('rejects malformed or ambiguous JSON receipts without accepting separate caller data', function (string $body, string $exception) {
    $response = ($this->response)('company', $body);
    expect(fn () => SandboxSellerEvidence::fromResponse($response))->toThrow($exception);
})->with([['[]', RuntimeException::class], ['{', JsonException::class], ['{"id":18172,"id":18171}', RuntimeException::class]]);

it('keeps tokens out of reports and debug output', function () {
    $proof = ($this->proof)('company');
    ob_start();
    var_dump($proof);
    $debug = ob_get_clean();
    expect(json_encode(($this->assess)(), JSON_THROW_ON_ERROR).$debug)->not->toContain('unit-access', 'Bearer');
});

it('preserves the legacy French SIRET and fr_siren qualification in production', function () {
    $this->app->instance('env', 'production');
    config(['app.env' => 'production', 'services.superpdp.environment' => 'production']);
    $session = $this->data['session'];
    $company = ['id' => 18172, 'number_scheme' => 'fr_siren', 'number' => '001234567', 'country' => 'FR', 'env' => 'production'];
    $result = $this->qualifier->assess(['seller' => ['siret' => '00123456700012']], $company, $session, 'unit-client', 'production');
    expect($result['checks_passed_for_simulation'])->toBeTrue()->and($result['seller_siren'])->toBe('001234567')
        ->and($result['status'])->toBe('not_verified');
    $company['number_scheme'] = 'sandbox';
    expect($this->qualifier->assess(['seller' => ['siret' => '00123456700012']], $company, $session, 'unit-client', 'production')['checks_passed_for_simulation'])->toBeFalse();
    expect(fn () => $this->qualifier->assess(['seller' => ['siret' => null]], $company, $session, 'unit-client', 'production'))->toThrow(InvalidArgumentException::class);
});
