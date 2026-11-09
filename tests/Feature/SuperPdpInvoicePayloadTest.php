<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $data = SuperPdpPayloadFixture::data();
    $this->user = User::factory()->create();
    $this->user->company()->create(array_diff_key($data['seller'], array_flip(['name', 'country_code'])) + ['company_name' => 'Atelier Martin']);
    $notes = array_column(SuperPdpPayloadFixture::frenchContext()['notes'], 'note', 'subject_code');
    $this->user->company()->update([
        'invoice_recovery_indemnity_text' => $notes['PMT'],
        'invoice_late_payment_penalties_text' => $notes['PMD'],
        'invoice_early_payment_discount_text' => $notes['AAB'],
    ]);
    $client = $this->user->clients()->create(array_diff_key($data['buyer'], ['full_name' => true]) + [
        'first_name' => 'Marie', 'last_name' => 'Dupont',
    ]);
    $this->invoice = Invoice::create([
        'user_id' => $this->user->id, 'client_id' => $client->id,
        'french_business_process_type' => 'S1',
        'invoice_number' => $data['invoice_number'], 'date' => $data['date'], 'due_date' => $data['due_date'],
        'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
        ...$data['totals'],
    ]);
    $this->invoice->items()->create(array_diff_key($data['items'][0], ['id' => true]));
    $this->address = ['scheme' => '0225', 'value' => '732829320_ATELIER'];
    $this->builder = app(SuperPdpInvoicePayloadBuilder::class);
    $captured = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $captured['company_vat_regime'] = 'subject_to_vat';
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($captured)]);
});

it('reads fresh decimal data and performs only SELECTs without altering the caller or storage', function () {
    $this->invoice->load('items', 'payments', 'creditNotes', 'client', 'user.company');
    $this->invoice->items[0]->unit_price_ht = '999.00';
    $this->invoice->total_ttc = '999.00';
    $this->invoice->user->company->company_name = 'Nom non enregistré';
    $beforeCaller = $this->invoice->toArray();
    $beforeStored = $this->invoice->fresh()->getRawOriginal();
    $beforeItems = DB::table('invoice_items')->get()->toArray();
    $queries = [];
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Toute écriture est interdite pendant la construction électronique.');
        }
    });
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect($payload['seller']['name'])->toBe('Atelier Martin')
        ->and($payload['seller']['legal_registration_identifier'])->toBe(['value' => '732829320', 'scheme' => '0002'])
        ->and($payload['seller']['identifiers'])->toBe([['value' => '73282932000074', 'scheme' => '0009']])
        ->and($payload['seller']['electronic_address'])->toBe($this->address)
        ->and($payload['lines'][0]['price_details']['item_net_price'])->toBe('40.00')
        ->and($payload['totals']['amount_due_for_payment'])->toBe('72.00')
        ->and($this->invoice->toArray())->toBe($beforeCaller)
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($beforeStored)
        ->and(DB::table('invoice_items')->get()->toArray())->toEqual($beforeItems)
        ->and($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->toMatch('/^\s*select\b/i');
    }
    Http::assertNothingSent();
});

it('refuses even a zero or later payment hidden by an already loaded empty relation', function (string $amount, string $paidAt) {
    $this->invoice->load('payments');
    $this->invoice->payments()->create(['amount' => $amount, 'paid_at' => $paidAt]);
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Les factures avec paiements ou acomptes');
    Http::assertNothingSent();
})->with([['10.00', '2026-11-01'], ['0.00', '2026-11-01'], ['10.00', '2026-12-01']]);

it('refuses an issued credit hidden by an already loaded empty relation', function () {
    $this->invoice->load('creditNotes');
    $this->invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001', 'date' => '2026-11-06',
        'status' => 'issued', 'reason' => 'Annulation', 'subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00',
    ]);
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Les factures avec avoirs définitifs');
});

it('does not treat a draft credit as an applied credit', function () {
    $this->invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001', 'date' => '2026-11-06',
        'status' => 'draft', 'reason' => 'Projet', 'subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00',
    ]);
    expect($this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext())['totals']['amount_due_for_payment'])->toBe('72.00');
});

it('refuses stored settlement indicators even without payment rows', function (array $attributes) {
    $this->invoice->newQuery()->whereKey($this->invoice->id)->update($attributes);
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'L’absence de paiement anticipé');
})->with([
    [['amount_paid' => '10.00']], [['status' => 'payee']], [['status' => 'partiellement_payee']],
]);

it('rechecks the stored issuance and version even if the caller has stale data', function (array $attributes, string $exception) {
    $this->invoice->newQuery()->whereKey($this->invoice->id)->update($attributes);
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow($exception);
})->with([
    [['document_status' => 'draft'], InvalidArgumentException::class],
    [['calculation_version' => null], ValidationException::class],
    [['calculation_version' => 'decimal-v2'], InvalidArgumentException::class],
    [['total_ttc' => '72.01'], ValidationException::class],
]);

it('refuses unpersisted invoices instead of assuming that payments are absent', function () {
    $invoice = (new Invoice)->fill([
        'document_status' => 'issued', 'invoice_number' => 'FAC-2026-0002', 'date' => '2026-11-05',
    ]);
    expect(fn () => $this->builder->build($invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'La préparation électronique nécessite une facture enregistrée.');
});

it('keeps the existing legacy export contract while providing a separate electronic snapshot', function () {
    $source = app(InvoiceExportDataBuilder::class);
    $legacy = $source->build($this->invoice);
    $electronic = $source->buildElectronic($this->invoice);
    expect(array_keys($legacy))->toBe(['invoice_number', 'date', 'due_date', 'status', 'seller', 'buyer', 'items', 'totals'])
        ->and($legacy['totals'])->toBe([
            'subtotal_ht' => 60.0, 'total_tva' => 12.0, 'total_ttc' => 72.0, 'amount_paid' => 0.0, 'remaining_amount' => 72.0,
        ])->and($legacy['seller'])->toBe($electronic['seller'])
        ->and($legacy['buyer'])->toBe($electronic['buyer'])
        ->and($electronic['items'][0]['quantity'])->toBe('1.50')
        ->and($electronic['totals']['total_ttc'])->toBe('72.00');
});

it('never fills absent stored mentions from free text or manual context', function () {
    $pdfTexts = '40 € dus en cas de retard de paiement. 10% du montant TTC. Néant.';
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => 'B1']);
    $this->invoice->update(['notes' => $pdfTexts]);
    $this->user->company()->update([
        'payment_terms' => $pdfTexts, 'invoice_recovery_indemnity_text' => null,
        'invoice_late_payment_penalties_text' => null, 'invoice_early_payment_discount_text' => null,
    ]);
    $snapshot = json_decode($this->invoice->fresh()->getRawOriginal('party_snapshot'));
    foreach (['invoice_recovery_indemnity_text', 'invoice_late_payment_penalties_text', 'invoice_early_payment_discount_text'] as $field) {
        $snapshot->seller->{$field} = null;
    }
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    $beforeInvoice = $this->invoice->fresh()->getRawOriginal();
    $beforeCompany = $this->user->company()->sole()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Toute écriture est interdite pendant la préparation électronique.');
        }
    });
    expect(fn () => $this->builder->build($this->invoice, $this->address))->toThrow(ValidationException::class);
    $context = SuperPdpPayloadFixture::frenchContext();
    $context['business_process_type'] = 'B1';
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: $context))->toThrow(ValidationException::class);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)
        ->and($this->user->company()->sole()->getRawOriginal())->toBe($beforeCompany);
    Http::assertNothingSent();
});

it('keeps captured owner mentions despite profile edits without changing caller relations or storage', function () {
    $this->invoice->load('user.company');
    $this->invoice->user->company->invoice_recovery_indemnity_text = 'Texte local non enregistré';
    $this->user->company()->update(['invoice_recovery_indemnity_text' => "  Texte enregistré.\nSans reformulation.  "]);
    $other = User::factory()->create();
    $other->company()->create(['company_name' => 'Autre entreprise', 'invoice_recovery_indemnity_text' => 'Texte tiers']);
    $this->actingAs($other);
    $beforeCaller = $this->invoice->toArray();
    $beforeCompany = $this->user->company()->sole()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture pendant la préparation électronique.');
        }
    });
    $payload = $this->builder->build($this->invoice, $this->address);
    expect($payload['notes'][0]['note'])->toBe(SuperPdpPayloadFixture::frenchContext()['notes'][0]['note'])
        ->and($this->invoice->toArray())->toBe($beforeCaller)
        ->and($this->user->company()->sole()->getRawOriginal())->toBe($beforeCompany);
    Http::assertNothingSent();
});

it('rejects a contradictory stored invoice context without writing any data', function (int $index, string $code) {
    $context = SuperPdpPayloadFixture::frenchContext();
    $context['notes'][$index]['note'] = 'Mention manuelle contradictoire';
    $before = $this->user->company()->sole()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture pendant la préparation électronique.');
        }
    });
    try {
        $this->builder->build($this->invoice, $this->address, frenchContext: $context);
        $this->fail('Persisted mentions must not be replaced.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.notes.'.$code);
    }
    expect($this->user->company()->sole()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with([[0, 'PMT'], [1, 'PMD'], [2, 'AAB']]);
