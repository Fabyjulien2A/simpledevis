<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create(['company_name' => 'Atelier', 'siret' => '73282932000074']);
    $this->other = User::factory()->create();
    $this->otherCompany = $this->other->company()->create(['company_name' => 'Autre', 'country_code' => 'BE']);
    $this->client = $this->owner->clients()->create(['client_type' => 'professional', 'country_code' => 'FR', 'siret' => '55210055400013']);
    $this->actingAs($this->owner);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('saves explicit assigned ISO countries without changing another account', function (string $country) {
    $this->post(route('company.update'), ['country_code' => $country])->assertSessionHasNoErrors();
    expect($this->company->fresh()->country_code)->toBe($country)->and($this->otherCompany->fresh()->country_code)->toBe('BE');
    $this->get(route('company.edit'))->assertOk()->assertSee('name="country_code"', false)->assertSee('BT-34');
})->with(['FR', 'BE', 'US', 'AX', 'BQ', 'SS', 'ZW']);

it('rejects non ISO countries rather than checking only two letters', function (mixed $country) {
    $this->post(route('company.update'), ['country_code' => $country])->assertSessionHasErrors('country_code');
    expect($this->company->fresh()->country_code)->toBeNull();
})->with(['ZZ', 'XX', 'UK', 'EU', 'FRA', 'fr', '12', [['FR']], 12]);

it('does not infer a country from SIRET and preserves omitted fields', function () {
    $this->post(route('company.update'), ['company_name' => 'Nouveau'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->country_code)->toBeNull();
    $this->post(route('company.update'), ['country_code' => 'FR'])->assertSessionHasNoErrors();
    $this->post(route('company.update'), ['company_name' => 'Nouveau bis'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->country_code)->toBe('FR');
    $this->post(route('company.update'), ['country_code' => null])->assertSessionHasNoErrors();
    expect($this->company->fresh()->country_code)->toBeNull();
});

it('stores billing addresses separately from contact emails for both parties', function (string $scheme, string $value) {
    $address = ['scheme' => $scheme, 'value' => $value];
    $this->post(route('company.update'), ['email' => 'contact@example.invalid', 'billing_electronic_address' => $address])->assertSessionHasNoErrors();
    expect($this->company->fresh()->billing_electronic_address)->toBe($address)->and($this->company->fresh()->email)->toBe('contact@example.invalid');
    $buyerAddress = ['scheme' => $scheme, 'value' => $scheme === '0225' ? '552100554_ACHATS' : $value];
    $this->put(route('clients.update', $this->client), ['client_type' => 'professional', 'country_code' => 'FR', 'billing_electronic_address' => $buyerAddress])->assertSessionHasNoErrors();
    expect($this->client->fresh()->billing_electronic_address)->toBe($buyerAddress);
    $this->get(route('clients.edit', $this->client))->assertOk()->assertSee('BT-49');
})->with([['0225', '732829320'], ['0225', '732829320_ATELIER'], ['EM', 'facturation@example.invalid']]);

it('validates both address components its scheme and its SIREN identity', function (mixed $address) {
    $this->post(route('company.update'), ['billing_electronic_address' => $address])->assertSessionHasErrors();
    expect($this->company->fresh()->billing_electronic_address)->toBeNull();
})->with([
    [['scheme' => '0225']], [['value' => '732829320']], [['scheme' => 'OTHER', 'value' => 'x']],
    [['scheme' => 'EM', 'value' => 'not-an-email']], [['scheme' => '0225', 'value' => '552100554']],
    [['scheme' => '0225', 'value' => '732829320/../../x']], [['scheme' => '0225', 'value' => '732829320', 'verified' => true]],
    ['invalid'],
]);

it('revalidates a declared address when SIRET changes and allows explicit clearing', function () {
    $address = ['scheme' => '0225', 'value' => '732829320'];
    $this->post(route('company.update'), ['billing_electronic_address' => $address])->assertSessionHasNoErrors();
    $this->post(route('company.update'), ['siret' => '55210055400013'])->assertSessionHasErrors('billing_electronic_address.value');
    expect($this->company->fresh()->siret)->toBe('73282932000074');
    $this->post(route('company.update'), ['siret' => '55210055400013', 'billing_electronic_address' => ['scheme' => null, 'value' => null]])->assertSessionHasNoErrors();
    expect($this->company->fresh()->billing_electronic_address)->toBeNull();
});

it('prevents reading or changing another users billing address and never infers it from email', function () {
    $otherClient = $this->other->clients()->create(['client_type' => 'professional', 'country_code' => 'FR']);
    $this->get(route('clients.edit', $otherClient))->assertForbidden();
    $this->put(route('clients.update', $otherClient), ['client_type' => 'professional', 'country_code' => 'FR', 'billing_electronic_address' => ['scheme' => 'EM', 'value' => 'x@example.invalid']])->assertForbidden();
    $this->post(route('clients.store'), ['client_type' => 'professional', 'country_code' => 'FR', 'email' => 'contact@example.invalid'])->assertSessionHasNoErrors();
    expect($this->owner->clients()->latest('id')->first()->billing_electronic_address)->toBeNull();
    $this->get(route('clients.create'))->assertOk()->assertSee('BT-49');
});
