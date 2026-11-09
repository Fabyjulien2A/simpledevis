<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\InvoiceLinePreparationService;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePartySnapshotCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Storage::fake('public');
    Storage::fake('invoice_logos');
    $logo = UploadedFile::fake()->image('logo.png');
    Storage::disk('public')->put('logos/fictif.png', file_get_contents($logo->getRealPath()));
    $this->owner = User::factory()->create(['name' => 'Nom utilisateur non repris']);
    $this->company = $this->owner->company()->create([
        'company_name' => 'Entreprise fictive Élodie & Fils', 'siret' => '00123456700012',
        'vat_number' => null, 'tva_number' => 'Alias distinct conservé', 'vat_regime' => 'franchise',
        'address' => "  Adresse fictive\nBâtiment A  ", 'postal_code' => '00001',
        'city' => 'Ville fictive', 'email' => 'vendeur@example.invalid', 'phone' => null,
        'legal_status' => 'SARL', 'iban' => 'IBAN fictif', 'bic' => 'BIC fictif',
        'payment_terms' => 'Conditions explicites', 'logo' => 'logos/fictif.png',
        'invoice_recovery_indemnity_text' => 'PMT fictive',
        'invoice_late_payment_penalties_text' => "  PMD fictive.\nDeuxième ligne.  ",
        'invoice_early_payment_discount_text' => null,
    ]);
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Client fictif <B2B>', 'first_name' => 'Zoë', 'last_name' => 'Exemple',
        'client_type' => 'professional', 'country_code' => 'FR', 'siret' => '00012345600078',
        'vat_number' => null, 'address' => 'Autre adresse fictive', 'postal_code' => '00002',
    ]);
    $this->actingAs($this->owner);
    $this->payload = [
        'action' => 'draft', 'french_business_process_type' => 'S1', 'client_id' => $this->client->id,
        'date' => '2026-11-08', 'items' => [[
            'description' => 'Prestation fictive', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
        ]],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

afterEach(function () {
    Http::assertNothingSent();
});

it('captures raw owner parties in the issuing transaction on both paths', function (string $path) {
    $writes = [];
    Event::listen('eloquent.updating: '.Invoice::class, function (Invoice $invoice) use (&$writes) {
        if ($invoice->isDirty('party_snapshot')) {
            $writes[] = [DB::transactionLevel(), $invoice->document_status];
        }
    });
    if ($path === 'direct') {
        $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->first();
        expect($this->invoice->fresh()->party_snapshot)->toBeNull();
    } else {
        $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
        $invoice = $this->invoice->fresh();
    }
    $snapshot = $invoice->party_snapshot;
    expect($invoice->document_status)->toBe('issued')
        ->and($snapshot['schema_version'])->toBe(InvoicePartySnapshot::PRIVATE_LOGO_SCHEMA_VERSION)
        ->and($snapshot['seller']['company_name'])->toBe($this->company->company_name)
        ->and($snapshot['seller']['address'])->toBe($this->company->address)
        ->and($snapshot['seller']['siret'])->toBe('00123456700012')
        ->and($snapshot['seller']['vat_number'])->toBeNull()
        ->and($snapshot['seller']['tva_number'])->toBe('Alias distinct conservé')
        ->and($snapshot['seller']['iban'])->toBe('IBAN fictif')
        ->and($snapshot['seller']['invoice_late_payment_penalties_text'])->toBe($this->company->invoice_late_payment_penalties_text)
        ->and($snapshot['seller']['invoice_early_payment_discount_text'])->toBeNull()
        ->and($snapshot['seller'])->not->toHaveKeys(['country_code', 'user_name', 'name', 'electronic_address'])
        ->and($snapshot['buyer']['siret'])->toBe('00012345600078')
        ->and($snapshot['buyer']['company_name'])->toBe($this->client->company_name)
        ->and($snapshot['buyer'])->not->toHaveKeys(['full_name', 'electronic_address'])
        ->and($snapshot['electronic_qualification'])->toBe(['status' => 'not_assessed'])
        ->and($writes)->toHaveCount(1)
        ->and($writes[0][0])->toBeGreaterThan(1)
        ->and($writes[0][1])->toBe('draft');
})->with(['direct', 'draft']);

it('rereads parties instead of using stale attributes relations or posted snapshots', function (string $path) {
    $this->invoice->load('client', 'user.company');
    $this->company->update(['company_name' => 'Nom enregistré après chargement']);
    $this->client->update(['address' => 'Adresse enregistrée après chargement']);
    $this->invoice->client->address = 'Adresse locale non enregistrée';
    $this->invoice->user->company->company_name = 'Nom local non enregistré';
    $request = ['party_snapshot' => ['seller' => ['company_name' => 'Injection']], 'seller' => ['company_name' => 'Injection']];
    if ($path === 'direct') {
        $this->post(route('invoices.store'), ['action' => 'issue'] + $request + $this->payload)->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->first();
    } else {
        $this->post(route('invoices.finalize', $this->invoice), $request)->assertSessionHas('success');
        $invoice = $this->invoice->fresh();
    }
    expect($invoice->party_snapshot['seller']['company_name'])->toBe('Nom enregistré après chargement')
        ->and($invoice->party_snapshot['buyer']['address'])->toBe('Adresse enregistrée après chargement');
})->with(['direct', 'draft']);

it('does not copy a snapshot posted when creating a draft', function () {
    $this->post(route('invoices.store'), ['party_snapshot' => ['schema_version' => 999]] + $this->payload)->assertSessionHasNoErrors();
    expect(Invoice::latest('id')->first()->party_snapshot)->toBeNull();
});

it('captures an absent company as an empty JSON object without falling back to User', function () {
    $this->company->delete();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $stored = $this->invoice->fresh();
    $json = json_decode($stored->getRawOriginal('party_snapshot'), flags: JSON_THROW_ON_ERROR);
    expect($json->seller)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($json->seller))->toBe([])
        ->and($stored->party_snapshot['seller'])->toBe([]);
});

it('keeps an existing snapshot unchanged on both finalization paths', function (string $path) {
    $original = json_encode(InvoicePartySnapshot::capture(['company_name' => 'Capture antérieure'], []), JSON_THROW_ON_ERROR);
    if ($path === 'direct') {
        Event::listen('eloquent.created: '.Invoice::class, function (Invoice $invoice) use ($original) {
            DB::table('invoices')->where('id', $invoice->id)->update(['party_snapshot' => $original]);
        });
        $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->first();
    } else {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $original]);
        $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
        $invoice = $this->invoice->fresh();
    }
    expect($invoice->getRawOriginal('party_snapshot'))->toBe($original);
})->with(['direct', 'draft']);

it('preserves a snapshot after profile changes and repeated finalization', function () {
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $before = $this->invoice->fresh()->getRawOriginal('party_snapshot');
    $this->company->update(['company_name' => 'Autre identité', 'vat_regime' => 'subject_to_vat']);
    $this->client->update(['address' => 'Autre adresse']);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('error');
    expect($this->invoice->fresh()->getRawOriginal('party_snapshot'))->toBe($before);
});

it('never fills an issued historical invoice after the fact', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['document_status' => 'issued', 'invoice_number' => 'HISTORICAL']);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('error');
    $stored = $this->invoice->fresh();
    expect($stored->party_snapshot)->toBeNull();
    expect(fn () => DB::transaction(fn () => app(InvoicePartySnapshotCapture::class)->captureForFinalization($stored)))
        ->toThrow(LogicException::class, 'Seul un brouillon');
    $stored->status = 'partiellement_payee';
    $stored->save();
    expect($stored->fresh()->party_snapshot)->toBeNull();
    $stored->party_snapshot = InvoicePartySnapshot::capture([], [])->jsonSerialize();
    expect(fn () => $stored->save())->toThrow(ValidationException::class);
});

it('protects an already captured draft against model replacement', function () {
    DB::transaction(fn () => app(InvoicePartySnapshotCapture::class)->captureForFinalization($this->invoice));
    $stored = $this->invoice->fresh();
    $before = $stored->getRawOriginal('party_snapshot');
    $stored->party_snapshot = InvoicePartySnapshot::capture([], [])->jsonSerialize();
    expect(fn () => $stored->save())->toThrow(ValidationException::class)
        ->and($stored->fresh()->getRawOriginal('party_snapshot'))->toBe($before);
});

it('refuses replacement or clearing even through stale model instances', function (bool $clear) {
    $stale = $this->invoice->fresh();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $before = $this->invoice->fresh()->getRawOriginal('party_snapshot');
    $attempt = $clear ? $this->invoice->fresh() : $stale;
    $attempt->party_snapshot = $clear ? null : InvoicePartySnapshot::capture([], [])->jsonSerialize();
    expect(fn () => $attempt->save())->toThrow(ValidationException::class)
        ->and($this->invoice->fresh()->getRawOriginal('party_snapshot'))->toBe($before);
})->with([false, true]);

it('rolls back capture invoice lines and counter if the final issued write fails', function (string $path) {
    $beforeInvoice = $this->invoice->getRawOriginal();
    $captured = false;
    $fail = true;
    Event::listen('eloquent.updating: '.Invoice::class, function (Invoice $invoice) use (&$captured, &$fail) {
        if ($invoice->isDirty('party_snapshot')) {
            $captured = true;
        }
        if ($fail && $invoice->isDirty('document_status') && $invoice->document_status === 'issued') {
            throw new RuntimeException('Échec fictif après capture');
        }
    });
    $this->withoutExceptionHandling();
    try {
        if ($path === 'direct') {
            $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload);
        } else {
            app(InvoiceController::class)->finalize($this->invoice, app(InvoiceLinePreparationService::class));
        }
        $this->fail('Issuing must fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Échec fictif après capture')->and($captured)->toBeTrue();
    } finally {
        $fail = false;
    }
    expect($this->invoice->fresh()->getRawOriginal())->toBe($beforeInvoice);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('invoice_items', 1);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with(['direct', 'draft']);

it('refuses a client belonging to another owner before capturing', function () {
    $other = User::factory()->create();
    $this->client->update(['user_id' => $other->id]);
    $this->post(route('invoices.finalize', $this->invoice))->assertNotFound();
    expect($this->invoice->fresh()->document_status)->toBe('draft')
        ->and($this->invoice->fresh()->party_snapshot)->toBeNull();
    $this->assertDatabaseCount('invoice_counters', 0);
});

it('rejects another user before invoking capture', function () {
    $this->actingAs(User::factory()->create());
    $this->mock(InvoicePartySnapshotCapture::class)->shouldNotReceive('captureForFinalization');
    $this->post(route('invoices.finalize', $this->invoice))->assertForbidden();
    expect($this->invoice->fresh()->party_snapshot)->toBeNull();
});

it('does not propagate a snapshot into a duplicated invoice', function () {
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $before = $this->invoice->fresh()->getRawOriginal('party_snapshot');
    $this->post(route('invoices.duplicate', $this->invoice))->assertRedirect();
    $copy = Invoice::latest('id')->first();
    expect($copy->document_status)->toBe('draft')->and($copy->party_snapshot)->toBeNull()
        ->and($this->invoice->fresh()->getRawOriginal('party_snapshot'))->toBe($before);
});

it('keeps quote conversion as a draft without a snapshot', function () {
    $quote = Quote::create([
        'user_id' => $this->owner->id, 'client_id' => $this->client->id,
        'quote_number' => 'DEV-FICTIF', 'date' => '2026-11-08', 'status' => 'brouillon',
        'subtotal_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00',
    ]);
    $this->post(route('quotes.convertToInvoice', $quote))->assertRedirect();
    expect(Invoice::latest('id')->first()->document_status)->toBe('draft')
        ->and(Invoice::latest('id')->first()->party_snapshot)->toBeNull();
});
