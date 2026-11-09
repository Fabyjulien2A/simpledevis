<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Event::fake([Logout::class]);
    $this->user = User::factory()->create();
    $this->company = $this->user->company()->create(['company_name' => 'Entreprise propriétaire']);
    $this->actingAs($this->user);
});

afterEach(function () {
    Http::assertNothingSent();
});

function supplierInvoiceForAccountDeletion(Company $company, array $attributes = []): SupplierInvoice
{
    return SupplierInvoice::create(array_replace([
        'company_id' => $company->id, 'direction' => 'in', 'invoice_number' => 'SUP-LOCAL-1',
        'invoice_date' => '2026-11-09', 'supplier_name' => 'Fournisseur conservé',
        'total_ht' => '100.00', 'total_vat' => '20.00', 'total_ttc' => '120.00',
        'amount_due' => '120.00', 'status' => 'received', 'payment_status' => 'unpaid',
        'payload' => ['document' => 'à conserver'],
    ], $attributes));
}

function supplierAccountDeletionData(): array
{
    return collect(['users', 'companies', 'superpdp_connections', 'supplier_invoices',
        'supplier_invoice_items', 'electronic_invoice_events', 'supplier_invoice_payments',
        'clients', 'quotes', 'quote_items', 'invoices', 'invoice_items', 'payments', 'credit_notes'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

function assertSupplierAccountDeletionRefused($test, $response): void
{
    $response->assertRedirect(route('profile.edit'))->assertSessionHasErrorsIn('userDeletion', [
        'account' => 'Votre compte ne peut pas être supprimé car il possède au moins une facture fournisseur reçue.',
    ]);
    $test->assertAuthenticatedAs($test->user);
    Event::assertNotDispatched(Logout::class);
    expect(User::whereKey($test->user->id)->exists())->toBeTrue();
}

it('refuses supplier-only accounts for every processing and payment status without logging out or changing data', function (string $status, string $paymentStatus) {
    supplierInvoiceForAccountDeletion($this->company, ['status' => $status, 'payment_status' => $paymentStatus]);
    $this->assertDatabaseCount('invoices', 0);
    $before = supplierAccountDeletionData();
    $response = $this->withSession(['supplier_marker' => 'preserved', '_token' => 'preserved-token'])
        ->delete(route('profile.destroy'), ['password' => 'password']);
    assertSupplierAccountDeletionRefused($this, $response);
    $response->assertSessionHas('supplier_marker', 'preserved')->assertSessionHas('_token', 'preserved-token');
    expect(supplierAccountDeletionData())->toBe($before);
    $this->get(route('profile.edit'))->assertOk()
        ->assertSeeText('Votre compte ne peut pas être supprimé car il possède au moins une facture fournisseur reçue.');
})->with(['received', 'processing', 'accepted', 'rejected', 'custom-status'])
    ->with(['unpaid', 'partial', 'paid']);

it('preserves supplier lines events payments payloads file references and associated files on refusal', function () {
    Storage::fake('local');
    Storage::fake('public');
    $files = [
        ['local', 'supplier-invoices/original.pdf', 'Local PDF fixture bytes'],
        ['local', 'supplier-invoices/original.xml', '<LocalFixture>preserved</LocalFixture>'],
        ['public', 'supplier-invoices/attachment.txt', 'Local attachment fixture'],
    ];
    foreach ($files as [$disk, $path, $contents]) {
        Storage::disk($disk)->put($path, $contents);
    }
    $invoice = supplierInvoiceForAccountDeletion($this->company, [
        'pdf_path' => $files[0][1], 'xml_path' => $files[1][1],
        'payload' => ['original' => 'preserved', 'attachment' => $files[2][1]],
    ]);
    $invoice->items()->create([
        'line_number' => 1, 'description' => 'Ligne conservée', 'quantity' => '1',
        'unit_price_ht' => '100', 'line_total_ht' => '100', 'vat_rate' => '20',
        'vat_amount' => '20', 'line_total_ttc' => '120', 'payload' => ['line' => 'preserved'],
    ]);
    $invoice->events()->create([
        'superpdp_event_id' => 17, 'event_type' => 'Réception', 'status' => 'received',
        'event_date' => '2026-11-09', 'payload' => ['event' => 'preserved'],
    ]);
    $invoice->payments()->create([
        'amount' => '20', 'paid_at' => '2026-11-09', 'method' => 'virement',
        'reference' => 'REF-LOCAL', 'notes' => 'Paiement conservé',
    ]);
    $this->company->superPdpConnection()->create([
        'status' => 'connected', 'access_token' => 'local-test-token',
        'refresh_token' => 'local-test-refresh', 'reception_enabled' => true,
    ]);
    $before = supplierAccountDeletionData();

    assertSupplierAccountDeletionRefused($this, $this->delete(route('profile.destroy'), ['password' => 'password']));

    expect(supplierAccountDeletionData())->toBe($before);
    foreach ($files as [$disk, $path, $contents]) {
        expect(Storage::disk($disk)->get($path))->toBe($contents);
    }
});

it('preserves the existing outgoing invoice protection when supplier invoices also exist', function (string $documentStatus) {
    supplierInvoiceForAccountDeletion($this->company);
    $client = $this->user->clients()->create(['first_name' => 'Client']);
    Invoice::create([
        'user_id' => $this->user->id, 'client_id' => $client->id, 'date' => '2026-11-09',
        'document_status' => $documentStatus, 'status' => 'non_payee',
        'subtotal_ht' => '100', 'total_tva' => '20', 'total_ttc' => '120',
    ]);
    $before = supplierAccountDeletionData();
    $response = $this->delete(route('profile.destroy'), ['password' => 'password']);
    if ($documentStatus === 'issued') {
        $response->assertRedirect(route('profile.edit'))->assertSessionHasErrorsIn('userDeletion', [
            'account' => 'Votre compte ne peut pas être supprimé car il possède au moins une facture définitive.',
        ]);
        $this->assertAuthenticatedAs($this->user);
        Event::assertNotDispatched(Logout::class);
    } else {
        assertSupplierAccountDeletionRefused($this, $response);
    }
    expect(supplierAccountDeletionData())->toBe($before);
})->with(['draft', 'issued']);

it('keeps account deletion working with no documents to preserve and ignores other owners supplier invoices', function (string $case) {
    $other = User::factory()->create();
    $otherCompany = $other->company()->create(['company_name' => 'Autre entreprise']);
    $otherInvoice = supplierInvoiceForAccountDeletion($otherCompany);
    $otherBefore = $otherInvoice->fresh()->getRawOriginal();
    if ($case === 'no company') {
        $this->company->delete();
    } elseif ($case === 'draft only') {
        $client = $this->user->clients()->create(['first_name' => 'Client']);
        Invoice::create([
            'user_id' => $this->user->id, 'client_id' => $client->id, 'date' => '2026-11-09',
            'document_status' => 'draft', 'status' => 'non_payee',
            'subtotal_ht' => '100', 'total_tva' => '20', 'total_ttc' => '120',
        ]);
    }

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/')->assertSessionHasNoErrors();

    $this->assertGuest();
    Event::assertDispatched(Logout::class);
    $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    $this->assertDatabaseMissing('companies', ['id' => $this->company->id]);
    $this->assertDatabaseCount('invoices', 0);
    expect($otherInvoice->fresh()->getRawOriginal())->toBe($otherBefore);
    $this->assertDatabaseHas('companies', ['id' => $otherCompany->id, 'user_id' => $other->id]);
})->with(['no company', 'empty company', 'draft only']);

it('checks all owned companies even if an older account has more than one company row', function () {
    $secondCompany = $this->user->company()->create(['company_name' => 'Deuxième entreprise']);
    supplierInvoiceForAccountDeletion($secondCompany);
    $before = supplierAccountDeletionData();
    assertSupplierAccountDeletionRefused($this, $this->delete(route('profile.destroy'), ['password' => 'password']));
    expect(supplierAccountDeletionData())->toBe($before);
});

it('does not trust a cached company relation or a posted company identifier', function () {
    supplierInvoiceForAccountDeletion($this->company);
    $other = User::factory()->create();
    $otherCompany = $other->company()->create(['company_name' => 'Autre entreprise']);
    $this->user->setRelation('company', $otherCompany);
    $before = supplierAccountDeletionData();
    $response = $this->delete(route('profile.destroy'), ['password' => 'password', 'company_id' => $otherCompany->id]);
    assertSupplierAccountDeletionRefused($this, $response);
    expect(supplierAccountDeletionData())->toBe($before);
});

it('requires the password even when supplier invoices prevent deletion', function () {
    supplierInvoiceForAccountDeletion($this->company);
    $before = supplierAccountDeletionData();
    $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertRedirect(route('profile.edit'))->assertSessionHasErrorsIn('userDeletion', 'password');
    $this->assertAuthenticatedAs($this->user);
    Event::assertNotDispatched(Logout::class);
    expect(supplierAccountDeletionData())->toBe($before);
});

it('reads current supplier invoices in the deletion transaction after rereading owned companies', function () {
    $arrived = false;
    $checked = false;
    DB::listen(function ($query) use (&$arrived, &$checked) {
        if (! str_starts_with($query->sql, 'select') || DB::transactionLevel() <= 1) {
            return;
        }
        if (! $arrived && str_contains($query->sql, 'from "companies"')) {
            // Arrivée simulée avant le contrôle ; ce test ne simule pas les verrous MariaDB.
            $arrived = true;
            supplierInvoiceForAccountDeletion($this->company);
        }
        if (str_contains($query->sql, 'from "supplier_invoices"')) {
            expect($arrived)->toBeTrue();
            $checked = true;
        }
    });
    assertSupplierAccountDeletionRefused($this, $this->delete(route('profile.destroy'), ['password' => 'password']));
    expect($arrived)->toBeTrue()->and($checked)->toBeTrue();
    $this->assertDatabaseCount('supplier_invoices', 1);
});
