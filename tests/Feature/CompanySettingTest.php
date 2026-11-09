<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Atelier de test', 'siret' => '73282932000074',
        'tva_number' => 'FR44732829320', 'vat_number' => 'FR44732829320',
        'address' => '1 rue de test', 'postal_code' => '75001', 'city' => 'Paris',
        'legal_status' => 'EI', 'payment_terms' => 'Paiement à trente jours.',
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('normalizes the submitted number into both columns', function (string $input, string $expected) {
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), ['tva_number' => $input])
        ->assertRedirect(route('company.edit'))->assertSessionHasNoErrors();

    expect($this->company->fresh()->tva_number)->toBe($expected)
        ->and($this->company->fresh()->vat_number)->toBe($expected);
})->with([
    ['FR96552100554', 'FR96552100554'],
    ['fr96552100554', 'FR96552100554'],
    [' fr 96 552 100 554 ', 'FR96552100554'],
    ["fr\t96\n552\r100 554", 'FR96552100554'],
    ["fr\u{00A0}96\u{202F}552 100 554", 'FR96552100554'],
    ['fr a1 552100554', 'FRA1552100554'],
    // Le format est contrôlé, pas la clé fiscale ni l'existence de l'entreprise.
    ['FR00000000000', 'FR00000000000'],
]);

it('clears both columns when the visible field is explicitly emptied', function (mixed $input) {
    $this->actingAs($this->owner)->post(route('company.update'), ['tva_number' => $input])->assertSessionHasNoErrors();
    expect($this->company->fresh()->tva_number)->toBeNull()
        ->and($this->company->fresh()->vat_number)->toBeNull();
})->with([[''], [null], ['   '], ["\t\n"], ["\u{00A0}\u{202F}"]]);

it('keeps the number when the field is not submitted', function () {
    $this->actingAs($this->owner)->post(route('company.update'), ['phone' => '0102030405'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->tva_number)->toBe('FR44732829320')
        ->and($this->company->fresh()->vat_number)->toBe('FR44732829320')
        ->and($this->company->fresh()->phone)->toBe('0102030405');
});

it('does not accept independent writes to the internal VAT mirror', function () {
    $this->actingAs($this->owner)->post(route('company.update'), ['vat_number' => 'FR96552100554'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->tva_number)->toBe('FR44732829320')
        ->and($this->company->fresh()->vat_number)->toBe('FR44732829320');
});

it('uses the visible field even when a conflicting internal mirror is submitted', function (mixed $input, ?string $expected) {
    $this->actingAs($this->owner)->post(route('company.update'), [
        'tva_number' => $input, 'vat_number' => 'FR44732829320',
    ])->assertSessionHasNoErrors();
    expect($this->company->fresh()->tva_number)->toBe($expected)
        ->and($this->company->fresh()->vat_number)->toBe($expected);
})->with([['fr 96 552100554', 'FR96552100554'], ['', null]]);

it('rejects malformed or foreign numbers without updating either column or other settings', function (mixed $input) {
    $before = $this->company->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        'tva_number' => $input, 'company_name' => 'Ne doit pas être enregistré',
    ])->assertRedirect(route('company.edit'))->assertSessionHasErrors('tva_number');
    expect($this->company->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['FR1234567890'], ['FR123456789012'], [str_repeat('A', 256)], ['BE0123456789'],
    ['XX96552100554'], ['FR9-552100554'], ['FR9655210055A'], ['FR96-552100554'],
    ['TVA FR96552100554'], [96552100554], [false], [['FR96552100554']],
]);

it('accepts creating company settings without a VAT number and does not invent one', function (array $input) {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('company.update'), ['company_name' => 'Artisan sans numéro'] + $input)
        ->assertSessionHasNoErrors();
    $company = $user->company()->sole();
    expect($company->tva_number)->toBeNull()->and($company->vat_number)->toBeNull()
        ->and($company->vat_regime)->toBeNull();
})->with([[[]], [['tva_number' => '']], [['tva_number' => null]]]);

it('does not change company identity or qualify a regime when the number is cleared', function () {
    $before = $this->company->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->post(route('company.update'), ['tva_number' => ''])->assertSessionHasNoErrors();
    $after = $this->company->fresh()->getRawOriginal();
    foreach (['tva_number', 'vat_number', 'updated_at'] as $field) {
        unset($before[$field], $after[$field]);
    }
    expect($after)->toBe($before);
});

it('never exports a stale VAT number after clearing even with already loaded relations', function () {
    $client = $this->owner->clients()->create(['first_name' => 'Client', 'last_name' => 'de test']);
    $invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $client->id, 'invoice_number' => 'FAC-2026-TEST',
        'date' => '2026-11-07', 'document_status' => 'issued', 'calculation_version' => 'decimal-v1',
        'status' => 'non_payee', 'amount_paid' => '0.00', 'subtotal_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00',
    ]);
    $invoice->load('user.company');
    $export = app(InvoiceExportDataBuilder::class);
    expect($export->buildElectronic($invoice)['seller']['vat_number'])->toBe('FR44732829320');

    $this->actingAs($this->owner)->post(route('company.update'), ['tva_number' => ''])->assertSessionHasNoErrors();
    expect($invoice->user->company->vat_number)->toBe('FR44732829320')
        ->and($export->buildElectronic($invoice)['seller']['vat_number'])->toBeNull();
});

it('only updates the authenticated company even with another owner identifiers in the request', function () {
    $other = User::factory()->create();
    $otherCompany = $other->company()->create([
        'company_name' => 'Autre artisan', 'tva_number' => 'FR96552100554', 'vat_number' => 'FR96552100554',
    ]);
    $before = $otherCompany->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->post(route('company.update'), [
        'tva_number' => '', 'user_id' => $other->id, 'company_id' => $otherCompany->id, 'id' => $otherCompany->id,
    ])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_number)->toBeNull()
        ->and($otherCompany->fresh()->getRawOriginal())->toBe($before);
});

it('requires authentication to edit company VAT data', function () {
    $before = $this->company->fresh()->getRawOriginal();
    $this->post(route('company.update'), ['tva_number' => ''])->assertRedirect(route('login'));
    expect($this->company->fresh()->getRawOriginal())->toBe($before);
});

it('shows the format explanation and the existing export number if the visible alias is missing', function () {
    $this->company->update(['tva_number' => null]);
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()
        ->assertSee('Laissez vide si vous n’avez pas de numéro de TVA, notamment en franchise en base.')
        ->assertSee('Seul le format est contrôlé, pas l’existence fiscale du numéro.')
        ->assertSee('value="FR44732829320"', false);
});
