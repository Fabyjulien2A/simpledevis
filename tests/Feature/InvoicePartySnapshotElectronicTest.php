<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoicePartySnapshotException;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->data = SuperPdpPayloadFixture::data();
    $this->data['company_vat_regime'] = 'subject_to_vat';
    $this->data['company_invoice_mentions'] = array_column(SuperPdpPayloadFixture::frenchContext()['notes'], 'note', 'subject_code');
    $this->data['seller']['tva_number'] = $this->data['seller']['vat_number'];
    $this->data['buyer'] = array_replace($this->data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Client historique fictif', 'siret' => '55210055400013',
    ]);
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Entreprise actuelle', 'siret' => '00123456700012',
        'vat_number' => 'FRXX001234567', 'vat_regime' => 'franchise',
        'address' => 'Adresse actuelle', 'postal_code' => '00001', 'city' => 'Ville actuelle',
        'invoice_recovery_indemnity_text' => 'PMT actuelle', 'invoice_late_payment_penalties_text' => 'PMD actuelle', 'invoice_early_payment_discount_text' => 'AAB actuelle',
    ]);
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Client actuel', 'client_type' => 'professional', 'country_code' => 'FR',
        'siret' => '00123456700012', 'address' => 'Adresse actuelle', 'postal_code' => '00001', 'city' => 'Ville actuelle',
    ]);
    $this->invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $this->client->id,
        'invoice_number' => $this->data['invoice_number'], 'date' => $this->data['date'], 'due_date' => $this->data['due_date'],
        'document_status' => 'issued', 'french_business_process_type' => 'S1',
        'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00', ...$this->data['totals'],
    ]);
    $this->invoice->items()->create(array_diff_key($this->data['items'][0], ['id' => true]));
    // Instantané explicite fictif, uniquement dans SQLite ; le pays n'est pas inféré.
    $this->snapshot = SuperPdpPayloadFixture::partySnapshot($this->data);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $this->snapshot]);
    $this->context = [
        'document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR',
        'seller_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
        'buyer_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ];
    $this->builder = app(SuperPdpInvoicePayloadBuilder::class);
    $this->diagnostic = app(SuperPdpEmissionEligibilityDiagnostic::class);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('keeps electronic identities notes and regime unchanged after Company and Client updates', function () {
    $before = $this->builder->build($this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address']);
    $this->invoice->load('client', 'user.company');
    $this->company->update(['company_name' => 'Encore un nom', 'vat_regime' => null, 'vat_number' => null, 'invoice_recovery_indemnity_text' => null]);
    $this->client->update(['company_name' => 'Encore un client', 'address' => null, 'country_code' => 'BE', 'client_type' => 'individual']);
    $after = $this->builder->build($this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address']);
    expect($after)->toBe($before)
        ->and($after['seller']['name'])->toBe($this->data['seller']['name'])
        ->and($after['buyer']['name'])->toBe($this->data['buyer']['company_name'])
        ->and(array_column($after['notes'], 'note', 'subject_code'))->toBe($this->data['company_invoice_mentions']);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['eligible'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable']);
    // Le lecteur historique utilisé par l'export existant garde ses sources actuelles.
    expect(app(InvoiceExportDataBuilder::class)->build($this->invoice->fresh())['seller']['name'])->toBe('Encore un nom');
    $this->company->delete();
    expect($this->builder->build($this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address']))->toBe($before);
});

it('does not query Company Client or User and performs no writes when using a snapshot', function () {
    $this->invoice->party_snapshot = ['schema_version' => 999];
    $beforeCaller = $this->invoice->getAttributes();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql) || preg_match('/\bfrom\s+["`]?\b(companies|clients|users)\b/i', $sql)) {
            throw new RuntimeException('Une fiche actuelle ne doit pas être consultée.');
        }
    });
    $this->builder->build($this->invoice, $this->context['seller_electronic_address']);
    expect($this->diagnostic->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeTrue()
        ->and($this->invoice->getAttributes())->toBe($beforeCaller);
});

it('prepares identical electronic payloads and diagnostics for version one and private-logo version two without accessing the archive', function () {
    $before = $this->builder->build($this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address']);
    $diagnostic = $this->diagnostic->diagnose($this->invoice, $this->context);
    $snapshot = json_decode($this->snapshot, false, 512, JSON_THROW_ON_ERROR);
    $snapshot->schema_version = 2;
    $snapshot->seller->logo = 'invoice-logos/v1/'.$this->owner->id.'/'.str_repeat('a', 64).'.png';
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode($snapshot)]);
    $this->mock(\App\Services\InvoiceLogoArchive::class)->shouldNotReceive('resolve');
    expect($this->builder->build($this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address']))->toBe($before)
        ->and($this->diagnostic->diagnose($this->invoice, $this->context))->toBe($diagnostic);
});

it('never repairs absent or null snapshot fields from current records', function (string $party, string $field, string $code, bool $null) {
    $snapshot = json_decode($this->snapshot, false, 512, JSON_THROW_ON_ERROR);
    if ($null) {
        $snapshot->{$party}->{$field} = null;
    } else {
        unset($snapshot->{$party}->{$field});
    }
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()->and(array_column($result['blockers'], 'code'))->toContain($code);
    $exception = in_array($field, ['address', 'country_code'], true) ? InvalidArgumentException::class : ValidationException::class;
    expect(fn () => $this->builder->build($this->invoice, $this->context['seller_electronic_address']))->toThrow($exception);
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    if (in_array($field, ['address', 'country_code', 'siret'], true)) {
        expect(array_key_exists($field, $data[$party]))->toBe($null);
        if ($null) {
            expect($data[$party][$field])->toBeNull();
        }
    }
})->with([
    ['seller', 'address', 'seller.address.missing'], ['buyer', 'address', 'buyer.address.missing'],
    ['seller', 'country_code', 'seller.country_code.missing'], ['buyer', 'country_code', 'buyer.country_code.missing'],
    ['seller', 'vat_regime', 'context.vat_regime.missing'],
    ['seller', 'invoice_recovery_indemnity_text', 'company.mentions.PMT.missing'],
    ['seller', 'invoice_late_payment_penalties_text', 'company.mentions.PMD.missing'],
    ['seller', 'invoice_early_payment_discount_text', 'company.mentions.AAB.missing'],
])->with([false, true]);

it('blocks invalid snapshots consistently without a profile fallback', function (string $json, string $reason) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $json]);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable', 'invoice.party_snapshot.'.$reason]);
    try {
        $this->builder->build($this->invoice, $this->context['seller_electronic_address']);
        $this->fail('Invalid storage must not fall back to current profiles.');
    } catch (InvoicePartySnapshotException $exception) {
        expect($exception->reason)->toBe($reason);
    }
    expect(fn () => $this->builder->buildFromData(['party_snapshot' => $json] + $this->data, $this->context['seller_electronic_address']))
        ->toThrow(InvoicePartySnapshotException::class);
})->with([
    ['', 'invalid'], ['null', 'invalid'], ['[]', 'invalid'], ['{"schema_version":1}', 'invalid'],
    ['{"schema_version":3,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'unsupported_version'],
    ['{"schema_version":1,"seller":[],"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
]);

it('retains legacy readers but blocks persisted invoices with no snapshot', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]);
    $source = app(InvoiceExportDataBuilder::class);
    expect($source->buildElectronic($this->invoice)['seller']['name'])->toBe($this->company->company_name);
    $this->company->update(['company_name' => 'Profil historique relu']);
    expect($source->build($this->invoice->fresh())['seller']['name'])->toBe('Profil historique relu')
        ->and($source->buildElectronic($this->invoice)['seller']['name'])->toBe('Profil historique relu');
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('invoice.party_snapshot.missing', 'routing.verification_unavailable');
    expect(fn () => $this->builder->build($this->invoice, $this->context['seller_electronic_address']))->toThrow(InvoicePartySnapshotException::class);
    expect(fn () => $this->builder->buildFromData($source->buildElectronic($this->invoice), $this->context['seller_electronic_address']))->toThrow(InvoicePartySnapshotException::class);
    expect($this->invoice->fresh()->party_snapshot)->toBeNull();
});

it('rereads the invoice snapshot independently of the current user and dirty owner attributes', function () {
    $other = User::factory()->create();
    $other->company()->create(['company_name' => 'Autre entreprise']);
    $this->actingAs($other);
    $this->invoice->user_id = $other->id;
    $this->invoice->party_snapshot = ['schema_version' => 999];
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    expect($data['party_snapshot'])->toBe($this->snapshot)
        ->and($data['seller']['name'])->toBe($this->data['seller']['name']);
    $this->get(route('invoices.show', $this->invoice->id))->assertForbidden();
});

it('reprojects buildFromData exclusively from the raw snapshot', function () {
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $data['seller']['name'] = 'Nom manuel';
    $data['buyer']['company_name'] = 'Acheteur manuel';
    $data['company_vat_regime'] = 'franchise';
    $data['company_invoice_mentions']['PMT'] = 'PMT manuelle';
    $before = $data;
    $payload = $this->builder->buildFromData($data, $this->context['seller_electronic_address']);
    expect($payload['seller']['name'])->toBe($this->data['seller']['name'])
        ->and($payload['buyer']['name'])->toBe($this->data['buyer']['company_name'])
        ->and($payload['notes'][0]['note'])->toBe($this->data['company_invoice_mentions']['PMT'])
        ->and($data)->toBe($before);
});

it('rejects manual mentions and regimes contradicting captured values', function () {
    $context = SuperPdpPayloadFixture::frenchContext();
    $context['notes'][0]['note'] = 'PMT contradictoire';
    expect(fn () => $this->builder->build($this->invoice, $this->context['seller_electronic_address'], frenchContext: $context))->toThrow(ValidationException::class);
    $result = $this->diagnostic->diagnose($this->invoice, ['vat_regime' => 'franchise', 'french_context' => $context] + $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('tax.regime.context_conflict', 'company.mentions.PMT.context_conflict');
});

it('uses captured franchise identifiers instead of the current VAT profile', function () {
    $this->data['company_vat_regime'] = 'franchise';
    $this->data['seller']['vat_number'] = null;
    $this->data['seller']['tva_number'] = null;
    DB::table('invoices')->where('id', $this->invoice->id)->update([
        'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($this->data), 'total_tva' => '0.00', 'total_ttc' => '60.00',
    ]);
    $this->invoice->items()->update(['vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE']);
    $this->company->update(['vat_regime' => 'subject_to_vat']);
    $payload = $this->builder->build($this->invoice, $this->context['seller_electronic_address']);
    expect($payload['seller']['tax_registration_identifier'])->toBe('732829320')
        ->and($payload['seller'])->not->toHaveKey('vat_identifier')
        ->and($this->diagnostic->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeTrue();
});

it('applies the same captured tax blockers to the diagnostic and direct builder', function (string $regime, ?string $vat, string $code, string $field) {
    $this->data['company_vat_regime'] = $regime;
    $this->data['seller']['vat_number'] = $vat;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($this->data)]);
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain($code);
    try {
        $this->builder->build($this->invoice, $this->context['seller_electronic_address']);
        $this->fail('The same historical fiscal rule must block preparation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    ['subject_to_vat', null, 'seller.vat_number.missing', 'seller.vat_number'],
    ['franchise', 'FR44732829320', 'tax.franchise.line_invalid', 'items.0'],
    ['reverse_charge', 'FR44732829320', 'tax.regime.unsupported', 'vat_regime'],
]);

it('explains that editing current profiles does not complete the historical snapshot', function () {
    $snapshot = json_decode($this->snapshot);
    $snapshot->seller->vat_regime = null;
    $snapshot->seller->invoice_recovery_indemnity_text = null;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Modifier les fiches actuelles ne complète pas cet instantané.')
        ->assertSee('Régime de TVA non renseigné dans l’instantané de la facture.')
        ->assertDontSee('Régime de TVA non renseigné dans les paramètres de votre entreprise.');
});

it('does not invent a French seller country for a snapshot captured by real finalization', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['invoice_number' => 'FACTURE-FICTIVE-EXISTANTE']);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'french_business_process_type' => 'S1', 'client_id' => $this->client->id,
        'date' => '2026-11-08', 'items' => [[
            'description' => 'Ligne fictive', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->first();
    expect($invoice->party_snapshot)->not->toBeNull();
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
    expect($data['seller'])->not->toHaveKey('country_code')
        ->and(array_column($this->diagnostic->diagnose($invoice, $this->context)['blockers'], 'code'))->toContain('seller.country_code.missing');
});
