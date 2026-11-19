<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\InvoiceRoutingQualification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// No persisted models, database, provider requests, or invoice creation.
uses(TestCase::class);

beforeEach(function () {
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $this->buyer = $this->context->toArray()['buyer'];
    $this->qualifier = new InvoiceRoutingQualification;
    $this->assess = fn () => $this->qualifier->assessSandbox($this->context, $this->company, $this->buyer);
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
});

it('matches the exact approved Tricatel identity and address without claiming a live API proof', function () {
    $before = [$this->buyer, $this->company->getAttributes(), $this->context->toArray()];
    $result = ($this->assess)();
    expect($result['manual_confirmation_matched'])->toBeTrue()
        ->and($result['routing_status'])->toBe('operator_confirmed')->and($result['status'])->toBe('not_verified')
        ->and($result['buyer_identity'])->toBe(['number_scheme' => 'sandbox', 'number' => '000000001'])
        ->and($result['destination'])->toBe(['directory' => 'peppol', 'identifier' => '0225:315143296_18171', 'scheme' => '0225', 'value' => '315143296_18171'])
        ->and($result['source'])->toBe('superpdp_interface')->and($result['confirmation_status'])->toBe('OK')
        ->and($result['api_verified'])->toBeFalse()->and($result['verified'])->toBeFalse()
        ->and($result['observed_at'])->toBeNull()->and($result['response_sha256'])->toBeNull()
        ->and($result['qualified_for_emission'])->toBeFalse()->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['pending_evidence'])->toBe(['orchestrator_live_buyer_destination_evidence'])
        ->and($result)->not->toHaveKeys(['buyer_siret', 'buyer_siren'])
        ->and([$this->buyer, $this->company->getAttributes(), $this->context->toArray()])->toBe($before);
});

it('refuses divergent identities addresses legal identifiers or forged provenance', function (string $path, mixed $value) {
    data_set($this->buyer, $path, $value);
    $result = ($this->assess)();
    expect($result['status'])->toBe('refused')->and($result['manual_confirmation_matched'])->toBeFalse()
        ->and($result['destination'])->toBeNull()->and($result['verified'])->toBeFalse()
        ->and($result['api_verified'])->toBeFalse()->and($result['qualified_for_emission'])->toBeFalse()
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['reasons'])->not->toBe([]);
})->with([
    ['name', 'Burger Queen'], ['name', 'tricatel'], ['superpdp_identity', null],
    ['superpdp_identity.number_scheme', 'fr_siren'], ['superpdp_identity.number_scheme', 'production'],
    ['superpdp_identity.number', '000000002'], ['superpdp_identity.number', 1], ['superpdp_identity.number', '1'],
    ['superpdp_identity.number', '315143296_18171'], ['superpdp_identity.siret', '00000000100000'],
    ['electronic_address', null], ['electronic_address', '0225:315143296_18171'],
    ['electronic_address.scheme', 'EM'], ['electronic_address.scheme', 225],
    ['electronic_address.value', '315143296_18171_replyto'], ['electronic_address.value', '315143296_18171_REPLYTO'],
    ['electronic_address.value', '315143296_18172'], ['electronic_address.value', '000000001'],
    ['electronic_address.value', '0225:315143296_18171'], ['electronic_address.value', '315143296_18171 '],
    ['electronic_address.value', []], ['routing_confirmation', null],
    ['routing_confirmation.source', 'live'], ['routing_confirmation.source', 'cached'],
    ['routing_confirmation.verification', 'api_confirmed'], ['routing_confirmation.status', 'pending'],
    ['routing_confirmation.api_verified', true], ['routing_confirmation.api_verified', 'false'],
    ['routing_confirmation.observed_at', '2026-11-13T17:00:00Z'],
    ['legal_registration_identity', ['scheme' => '0002', 'value' => '000000001']],
    ['siret', '00000000100000'], ['siren', '000000001'], ['directory', 'ppf'],
    ['api_verified', true], ['ready_for_emission', true],
]);

it('keeps identity and electronic-address failures independent', function (string $part) {
    if ($part === 'identity') {
        $this->buyer['superpdp_identity']['number'] = '000000002';
    } else {
        $this->buyer['electronic_address']['value'] = '315143296_18172';
    }
    $result = ($this->assess)();
    expect($result['checks']['buyer_identity'])->toBe($part !== 'identity')
        ->and($result['checks']['buyer_peppol_address'])->toBe($part !== 'address');
})->with(['identity', 'address']);

it('does not treat a matching directory API entry as a sandbox reception-capability proof', function (string $status, bool $replyto) {
    // An API-shaped row alone cannot supply the missing cross-company lookup
    // contract, live SDK provenance, or document/process reception capabilities.
    $this->buyer = [
        'id' => 987, 'created_at' => '2026-11-13T12:00:00Z',
        'company' => ['id' => 18171, 'formal_name' => 'Tricatel', 'number_scheme' => 'sandbox',
            'number' => '000000001', 'country' => 'FR', 'env' => 'sandbox'],
        'directory' => 'peppol', 'identifier' => '0225:315143296_18171',
        'status' => $status, 'is_replyto' => $replyto,
    ];
    $result = ($this->assess)();
    expect($result['status'])->toBe('refused')->and($result['api_verified'])->toBeFalse()
        ->and($result['qualified_for_emission'])->toBeFalse()->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['observed_at'])->toBeNull()->and($result['response_sha256'])->toBeNull();
})->with([['created', false], ['pending', false], ['error', false], ['created', true]]);

it('does not turn a successful live documentary test into live routing evidence', function () {
    request()->merge([
        'conversion_source' => 'live', 'validation_source' => 'live', 'document_validation' => 'passed_live',
        'http_statuses' => ['conversion' => 200, 'validation' => 200], 'routing_verified' => true,
    ]);
    $result = ($this->assess)();
    expect($result['manual_confirmation_matched'])->toBeTrue()
        ->and($result['source'])->toBe('superpdp_interface')->and($result['confirmation_status'])->toBe('OK')
        ->and($result['api_verified'])->toBeFalse()->and($result['verified'])->toBeFalse()
        ->and($result['qualified_for_emission'])->toBeFalse()->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['observed_at'])->toBeNull()->and($result['response_sha256'])->toBeNull()
        ->and($result['pending_evidence'])->toContain('orchestrator_live_buyer_destination_evidence');
});

it('rejects missing declaration fields', function (string $path) {
    data_forget($this->buyer, $path);
    expect(($this->assess)()['status'])->toBe('refused');
})->with(['name', 'superpdp_identity', 'superpdp_identity.number', 'electronic_address.value', 'routing_confirmation',
    'routing_confirmation.api_verified', 'legal_registration_identity']);

it('accepts reordered declaration keys without weakening exact values or provenance', function () {
    $this->buyer = array_reverse($this->buyer, true);
    $this->buyer['electronic_address'] = array_reverse($this->buyer['electronic_address'], true);
    $this->buyer['routing_confirmation'] = array_reverse($this->buyer['routing_confirmation'], true);
    expect(($this->assess)()['manual_confirmation_matched'])->toBeTrue();
});

it('requires the validated context object rather than a form array', function () {
    expect(fn () => $this->qualifier->assessSandbox($this->context->toArray(), $this->company, $this->buyer))->toThrow(TypeError::class);
});

it('does not promote caller form claims to live evidence', function () {
    request()->merge(['source' => 'live', 'api_verified' => true, 'ready_for_emission' => true, 'directory' => 'ppf']);
    $result = ($this->assess)();
    expect($result['manual_confirmation_matched'])->toBeTrue()->and($result['source'])->toBe('superpdp_interface')
        ->and($result['api_verified'])->toBeFalse()->and($result['qualified_for_emission'])->toBeFalse();
});

it('rejects activation outside sandbox or for an unauthorized local company or owner', function (string $change) {
    match ($change) {
        'production' => $this->app->instance('env', 'production'),
        'local' => $this->app->instance('env', 'local'),
        'config_production' => config(['app.env' => 'production']),
        'provider_production' => config(['services.superpdp.environment' => 'production']),
        'database' => config(['database.default' => 'mysql']),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
        'company' => $this->company->id = 4,
        'owner' => $this->company->user_id = 4,
        'user' => $this->actingAs((new User)->forceFill(['id' => 4])),
        'guest' => auth()->logout(),
        'unsaved' => $this->company->exists = false,
    };
    expect(fn () => ($this->assess)())->toThrow(RuntimeException::class);
})->with(['production', 'local', 'config_production', 'provider_production', 'database', 'binding', 'company', 'owner', 'user', 'guest', 'unsaved']);

it('preserves the French production directory qualification including refusal cases', function (string $change, bool $passes) {
    $this->app->instance('env', 'production');
    config(['app.env' => 'production', 'services.superpdp.environment' => 'production']);
    $data = ['seller' => ['siret' => '73282932000074'],
        'buyer' => ['siret' => '55210055400013', 'country_code' => 'FR', 'client_type' => 'professional'],
        'party_snapshot' => 'unchanged-historical-fixture', 'emission_profile' => InvoiceEmissionContext::PROFILE];
    $context = InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
        ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], $data);
    $data['emission_context'] = json_encode($context, JSON_THROW_ON_ERROR);
    $reply = ['data' => [['company' => ['number' => '552100554', 'country' => 'FR'], 'identifier' => '0225:552100554', 'is_active' => true]]];
    match ($change) {
        'valid' => null,
        'inactive' => $reply['data'][0]['is_active'] = false,
        'foreign' => $reply['data'][0]['company']['number'] = '000000001',
        'replyto' => $reply['data'][0]['identifier'] = '0225:552100554_replyto',
        'composed' => $reply['data'][0]['identifier'] = '0225:552100554_18171',
        'partial' => $reply['has_more'] = true,
        'duplicate' => $reply['data'][] = $reply['data'][0],
        'sandbox_address' => $reply['data'][0]['identifier'] = '0225:315143296_18171',
    };
    $before = [$data, $reply];
    $result = $this->qualifier->assess($data, $reply);
    expect($result['qualified_for_simulation'])->toBe($passes)->and($result['status'])->toBe('not_verified')
        ->and($result['buyer_siren'])->toBe('552100554')->and([$data, $reply])->toBe($before);
})->with([['valid', true], ['inactive', false], ['foreign', false], ['replyto', false],
    ['composed', false], ['partial', false], ['duplicate', false], ['sandbox_address', false]]);

it('does not bypass the existing production SIRET requirement with the sandbox context', function () {
    $data = ['seller' => ['siret' => '73282932000074'], 'buyer' => ['siret' => null], 'party_snapshot' => 'historical'];
    expect(fn () => $this->qualifier->assess($data, ['data' => []]))->toThrow(InvalidArgumentException::class);
});
