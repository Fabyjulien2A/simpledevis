<?php

use App\Models\User;
use App\Services\SuperPdp\DeclaredBillingAddress;
use App\Services\SuperPdp\InvoiceEmissionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Saloon\Config;
use Tests\Support\InMemorySuperPdpOAuthDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(static fn () => throw new LogicException('Network forbidden'));
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new InMemorySuperPdpOAuthDatabase;
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.client_id' => 'offline-client', 'session.driver' => 'array',
        'database.default' => 'superpdp_sandbox', 'database.connections' => ['superpdp_sandbox' => [
            'driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test',
        ]]]);
    DB::extend('superpdp_sandbox', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $this->memory->rows['users'] = [4 => ['id' => 4], 5 => ['id' => 5]];
    $this->memory->rows['companies'] = [
        4 => ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null, 'billing_electronic_address' => null],
        5 => ['id' => 5, 'user_id' => 5, 'company_name' => 'Other', 'siret' => null, 'billing_electronic_address' => null],
    ];
    $this->memory->rows['superpdp_connections'] = [2 => [
        'id' => 2, 'company_id' => 4, 'environment' => 'sandbox', 'oauth_client_id' => 'offline-client',
        'api_url' => 'https://api.superpdp.tech/v1.beta', 'directory_identifier' => '000000001',
        'superpdp_company_id' => 18171, 'status' => 'connected',
    ]];
    $this->memory->rows['invoices'] = [99 => ['id' => 99, 'immutable' => 'unchanged']];
    $this->actor = User::findOrFail(4);
    $this->actingAs($this->actor);
    $this->withSession(['_token' => str_repeat('a', 40)]);
    $this->withHeader('X-CSRF-TOKEN', str_repeat('a', 40));
    $this->address = ['scheme' => '0225', 'value' => '315143296_18171'];
    $this->saveAddress = fn (array $extra = []) => $this->post(route('company.update'),
        array_replace(['billing_electronic_address' => $this->address], $extra));
});

afterEach(function () {
    Model::setConnectionResolver($this->originalResolver);
    Http::assertNothingSent();
    expect($this->memory->rows['invoices'])->toBe([99 => ['id' => 99, 'immutable' => 'unchanged']]);
});

it('stores only the explicit declaration without a SIRET or qualification and allows clearing', function () {
    $before = $this->memory->rows;
    ($this->saveAddress)()->assertSessionHasNoErrors()->assertRedirect();
    $saved = $this->memory->rows['companies'][4];
    expect(json_decode($saved['billing_electronic_address'], true))->toBe($this->address)
        ->and($saved['siret'])->toBeNull()
        ->and($this->memory->rows['companies'][5])->toBe($before['companies'][5])
        ->and($this->memory->rows['superpdp_connections'])->toBe($before['superpdp_connections']);
    // The generic invoice/business validator must still reject this declaration.
    expect(fn () => DeclaredBillingAddress::validate($this->address, null))->toThrow(ValidationException::class);
    foreach (['seller', 'buyer'] as $party) {
        expect(fn () => InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
            $party === 'seller' ? $this->address : null,
            $party === 'buyer' ? $this->address : null,
            ['seller' => ['siret' => null], 'buyer' => ['siret' => null]]))
            ->toThrow(ValidationException::class);
    }
    $this->post(route('company.update'), ['siret' => null])->assertSessionHasNoErrors();
    ($this->saveAddress)(['billing_electronic_address' => ['scheme' => null, 'value' => null]])->assertSessionHasNoErrors();
    expect($this->memory->rows['companies'][4]['billing_electronic_address'])->toBeNull();
});

it('fails closed for every altered scope or identity', function (string $case) {
    switch ($case) {
        case 'app production': $this->app->instance('env', 'production');
            config(['app.env' => 'production']);
            break;
        case 'config production': config(['app.env' => 'production']);
            break;
        case 'provider production': config(['services.superpdp.environment' => 'production']);
            break;
        case 'database': config(['database.connections.superpdp_sandbox.database' => 'other']);
            break;
        case 'api': config(['services.superpdp.api_url' => 'https://other.invalid']);
            break;
        case 'missing client': config(['services.superpdp.client_id' => null]);
            break;
        case 'connection production': $this->memory->rows['superpdp_connections'][2]['environment'] = 'production';
            break;
        case 'connection api': $this->memory->rows['superpdp_connections'][2]['api_url'] = 'https://other.invalid';
            break;
        case 'connection client': $this->memory->rows['superpdp_connections'][2]['oauth_client_id'] = 'other';
            break;
        case 'identity': $this->memory->rows['superpdp_connections'][2]['directory_identifier'] = '000000002';
            break;
        case 'missing connection': $this->memory->rows['superpdp_connections'] = [];
            break;
        case 'foreign connection': $this->memory->rows['superpdp_connections'][2]['company_id'] = 5;
            break;
        case 'disconnected': $this->memory->rows['superpdp_connections'][2]['status'] = 'disconnected';
            break;
        case 'remote id': $this->memory->rows['superpdp_connections'][2]['superpdp_company_id'] = null;
            break;
        case 'name': $this->memory->rows['companies'][4]['company_name'] = 'Real company';
            break;
        case 'existing siret': $this->memory->rows['companies'][4]['siret'] = '73282932000074';
            break;
        case 'submitted siret': break;
        case 'suffix': $this->address['value'] .= '_replyto';
            break;
        case 'other suffix': $this->address['value'] = '315143296_18172';
            break;
        case 'bare siren': $this->address['value'] = '315143296';
            break;
        case 'scheme': $this->address['scheme'] = '0009';
            break;
        case 'qualification': $this->address['verified'] = true;
            break;
        case 'other owner': $this->actingAs(User::findOrFail(5));
            break;
        case 'reassigned company': $this->memory->rows['companies'][4]['user_id'] = 5;
            $this->actingAs(User::findOrFail(5));
            break;
    }
    $before = $this->memory->rows;
    ($this->saveAddress)(['company_id' => 4, 'user_id' => 4, 'number_scheme' => 'sandbox', 'number' => '000000001']
        + ($case === 'submitted siret' ? ['siret' => '73282932000074'] : []))
        ->assertSessionHasErrors();
    expect($this->memory->rows)->toBe($before);
})->with(['app production', 'config production', 'provider production', 'database', 'api', 'missing client',
    'connection production', 'connection api', 'connection client', 'identity', 'missing connection',
    'foreign connection', 'disconnected', 'remote id', 'name', 'existing siret', 'suffix', 'other suffix',
    'bare siren', 'scheme', 'qualification', 'other owner', 'reassigned company', 'submitted siret']);

it('ignores a forged target company and owner while saving only the authenticated company', function () {
    $before = $this->memory->rows['companies'][5];
    ($this->saveAddress)(['company_id' => 5, 'user_id' => 5])->assertSessionHasNoErrors();
    expect($this->memory->rows['companies'][5])->toBe($before)
        ->and(json_decode($this->memory->rows['companies'][4]['billing_electronic_address'], true))->toBe($this->address);
});

it('preserves the real company SIREN validation including in production', function () {
    $this->app->instance('env', 'production');
    config(['app.env' => 'production', 'services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][4]['siret'] = '73282932000074';
    ($this->saveAddress)()->assertSessionHasErrors();
    $this->address['value'] = '732829320_ATELIER';
    ($this->saveAddress)()->assertSessionHasNoErrors();
    expect(json_decode($this->memory->rows['companies'][4]['billing_electronic_address'], true))->toBe($this->address);
});
