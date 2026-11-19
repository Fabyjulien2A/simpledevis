<?php

use App\Models\Company;
use App\Models\User;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePartySnapshotException;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\InvoiceEmissionContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Unsaved models and in-memory configuration only: no database, server or provider.
uses(TestCase::class);

beforeEach(function () {
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox',
        'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    // Represent a previously loaded row without saving or querying a model.
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
});

it('captures the exact approved identities and Peppol addresses separately without inventing legal identifiers', function () {
    $before = $this->company->getAttributes();
    $context = SandboxEmissionContext::forCompany($this->company);
    $data = $context->toArray();
    expect($data)->toMatchArray(['schema_version' => 1, 'profile' => SandboxEmissionContext::PROFILE,
        'environment' => 'sandbox', 'owner_user_id' => 3, 'ready_for_emission' => false]);
    expect($data['seller'])->toBe([
        'local_company_id' => 3, 'name' => 'Burger Queen',
        'superpdp_identity' => ['number_scheme' => 'sandbox', 'number' => '000000002'],
        'legal_registration_identity' => null,
        'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18172'],
    ])->and($data['buyer'])->toBe([
        'name' => 'Tricatel', 'superpdp_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
        'legal_registration_identity' => null,
        'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18171'],
        'routing_confirmation' => ['source' => 'superpdp_interface', 'status' => 'OK', 'verification' => 'operator_confirmed', 'api_verified' => false],
    ])->and($this->company->getAttributes())->toBe($before);
    expect(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('"siret"', '"siren"', '_replyto');
    $context->assertFor($this->company);
    $context->assertMatches($data);
});

it('rejects production and other application environments even when SUPER PDP is configured as sandbox', function (string $environment) {
    $this->app->instance('env', $environment);
    config(['app.env' => $environment]);
    expect(fn () => SandboxEmissionContext::forCompany($this->company))->toThrow(RuntimeException::class);
})->with(['production', 'local', 'staging', 'testing']);

it('rejects inconsistent environment database or explicit identity configuration', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(fn () => SandboxEmissionContext::forCompany($this->company))->toThrow(RuntimeException::class);
})->with([
    ['app.env', 'production'],
    ['services.superpdp.environment', 'production'],
    ['services.superpdp.environment', null],
    ['services.superpdp.api_url', 'https://foreign.invalid/v1.beta'],
    ['database.default', 'mysql'],
    ['database.connections.superpdp_sandbox.database', 'production'],
    ['database.connections.mysql', ['database' => 'production']],
    ['services.superpdp.sandbox_identity', null],
    ['services.superpdp.sandbox_identity.company_id', 4],
    ['services.superpdp.sandbox_identity.company_id', true],
    ['services.superpdp.sandbox_identity.company_id', '03'],
    ['services.superpdp.sandbox_identity.number_scheme', 'fr_siren'],
    ['services.superpdp.sandbox_identity.number', '000000001'],
    ['services.superpdp.sandbox_identity.number', 2],
]);

it('rejects unauthenticated foreign or inconsistent local companies', function (string $change) {
    match ($change) {
        'guest' => auth()->logout(),
        'owner' => $this->company->user_id = 4,
        'company' => $this->company->id = 4,
        'name' => $this->company->company_name = 'Tricatel',
        'siret' => $this->company->siret = '00123456700012',
        'unsaved' => $this->company->exists = false,
        'connection' => $this->company->setConnection('mysql'),
    };
    expect(fn () => SandboxEmissionContext::forCompany($this->company))->toThrow(RuntimeException::class);
})->with(['guest', 'owner', 'company', 'name', 'siret', 'unsaved', 'connection']);

it('does not take identities addresses or provenance from form input', function () {
    request()->merge([
        'environment' => 'sandbox', 'company_id' => 99, 'seller' => ['number' => '999999999'],
        'buyer' => ['electronic_address' => ['scheme' => '0225', 'value' => '315143296_18171_replyto'],
            'routing_confirmation' => ['source' => 'live', 'api_verified' => true]],
    ]);
    $data = SandboxEmissionContext::forCompany($this->company)->toArray();
    expect($data['seller']['local_company_id'])->toBe(3)
        ->and($data['seller']['superpdp_identity']['number'])->toBe('000000002')
        ->and($data['buyer']['electronic_address']['value'])->toBe('315143296_18171')
        ->and($data['buyer']['routing_confirmation']['api_verified'])->toBeFalse();
});

it('refuses altered declarations including replyto addresses and forged API evidence', function (string $path, mixed $value) {
    $context = SandboxEmissionContext::forCompany($this->company);
    $candidate = $context->toArray();
    data_set($candidate, $path, $value);
    expect(fn () => $context->assertMatches($candidate))->toThrow(InvalidArgumentException::class);
})->with([
    ['schema_version', 2], ['schema_version', '1'], ['profile', InvoiceEmissionContext::PROFILE],
    ['environment', 'production'], ['owner_user_id', 4], ['seller.local_company_id', 4],
    ['seller.name', 'Tricatel'], ['buyer.name', 'Burger Queen'],
    ['seller.superpdp_identity.number_scheme', 'fr_siren'], ['seller.superpdp_identity.number', '000000001'],
    ['buyer.superpdp_identity.number_scheme', 'fr_siren'], ['buyer.superpdp_identity.number', '000000002'],
    ['buyer.superpdp_identity.number', 1],
    ['seller.electronic_address.value', '315143296_18172_replyto'],
    ['buyer.electronic_address.value', '315143296_18171_REPLYTO'],
    ['buyer.electronic_address.value', '315143296_18172'], ['buyer.electronic_address.scheme', 'EM'],
    ['buyer.electronic_address.value', '000000001'],
    ['buyer.routing_confirmation.source', 'live'], ['buyer.routing_confirmation.api_verified', true],
    ['buyer.routing_confirmation.status', 'pending'],
    ['seller.legal_registration_identity', ['scheme' => '0009', 'value' => '00000000200000']],
    ['seller.siret', '00000000200000'], ['buyer.siren', '000000001'], ['ready_for_emission', true],
    ['buyer', 'Tricatel'], ['seller', null], ['buyer.electronic_address', '0225:315143296_18171'],
    ['buyer.electronic_address.value', null], ['buyer.electronic_address.value', []],
    ['buyer.routing_confirmation.api_verified', 'false'], ['seller.local_company_id', '3'],
]);

it('rejects missing fields and accepts reordered JSON object keys without coercing scalar types', function () {
    $context = SandboxEmissionContext::forCompany($this->company);
    $data = $context->toArray();
    $context->assertMatches(array_reverse($data, true));
    unset($data['buyer']['routing_confirmation']);
    expect(fn () => $context->assertMatches($data))->toThrow(InvalidArgumentException::class);
});

it('is immutable and returned array copies cannot modify the approved declaration', function () {
    $context = SandboxEmissionContext::forCompany($this->company);
    $copy = $context->toArray();
    $copy['buyer']['superpdp_identity']['number'] = '999999999';
    expect($context->toArray()['buyer']['superpdp_identity']['number'])->toBe('000000001')
        ->and((new ReflectionClass($context))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($context))->getConstructor()->isPrivate())->toBeTrue();
    expect(fn () => $context->snapshot = [])->toThrow(Error::class);
});

it('rechecks the current scope and owner when exporting a previously created context', function (string $change) {
    $context = SandboxEmissionContext::forCompany($this->company);
    match ($change) {
        'production' => $this->app->instance('env', 'production'),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
        'user' => $this->actingAs((new User)->forceFill(['id' => 4])),
        'guest' => auth()->logout(),
    };
    expect(fn () => $context->toArray())->toThrow(RuntimeException::class);
})->with(['production', 'binding', 'user', 'guest']);

it('rechecks a changed local owner before any future consumption', function () {
    $context = SandboxEmissionContext::forCompany($this->company);
    $this->company->user_id = 4;
    expect(fn () => $context->assertFor($this->company))->toThrow(RuntimeException::class);
});

it('cannot be interpreted as a production emission context or an existing party snapshot', function () {
    $context = SandboxEmissionContext::forCompany($this->company);
    $json = json_encode($context, JSON_THROW_ON_ERROR);
    expect(fn () => InvoiceEmissionContext::fromJson($json, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InvoicePartySnapshot::fromJson($json))->toThrow(InvoicePartySnapshotException::class);
});
