<?php

use App\Http\Controllers\ClientController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->client = Client::create(['user_id' => $this->user->id, 'first_name' => 'Marie']);
    $this->actingAs($this->user);
});

function deletionInvoice(User $user, Client $client, array $attributes = []): Invoice
{
    return Invoice::create(array_replace([
        'user_id' => $user->id, 'client_id' => $client->id,
        'date' => '2026-11-09', 'document_status' => 'issued', 'status' => 'non_payee',
        'subtotal_ht' => 100, 'total_tva' => 20, 'total_ttc' => 120,
    ], $attributes));
}

function deletionData(): array
{
    return collect(['users', 'clients', 'quotes', 'quote_items', 'invoices', 'invoice_items',
        'payments', 'credit_notes', 'electronic_invoices', 'electronic_invoice_statuses'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
}

function requestProtectedDeletion($test, string $target)
{
    return $target === 'client'
        ? $test->delete(route('clients.destroy', $test->client))
        : $test->delete(route('profile.destroy'), ['password' => 'password']);
}

function assertDeletionRefused($test, $response, string $target): void
{
    $message = $target === 'client'
        ? 'Ce client ne peut pas être supprimé car il possède au moins une facture définitive.'
        : 'Votre compte ne peut pas être supprimé car il possède au moins une facture définitive.';
    if ($target === 'client') {
        $response->assertRedirect(route('clients.index'))->assertSessionHas('error', $message);
        $test->get(route('clients.index'))->assertOk()->assertSeeText($message);
    } else {
        $response->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrorsIn('userDeletion', ['account' => $message]);
        $test->get(route('profile.edit'))->assertOk()->assertSeeText($message);
    }
    $test->assertAuthenticatedAs($test->user);
    Http::assertNothingSent();
}

it('refuses issued invoices regardless of payment status and snapshot without relying on restrictive foreign keys', function (string $target, string $status, bool $snapshot) {
    $invoice = deletionInvoice($this->user, $this->client, ['status' => $status]);
    if ($snapshot) {
        DB::table('invoices')->where('id', $invoice->id)->update([
            'party_snapshot' => json_encode(['version' => 1, 'seller' => ['name' => 'Artisan'], 'buyer' => ['first_name' => 'Marie']]),
        ]);
    }
    $before = deletionData();
    $this->withSession(['deletion_marker' => 'preserved']);
    $response = requestProtectedDeletion($this, $target);
    $response->assertSessionHas('deletion_marker', 'preserved');
    assertDeletionRefused($this, $response, $target);
    expect(deletionData())->toEqual($before);
})->with(['client', 'account'])->with(['non_payee', 'partiellement_payee', 'payee'])->with([false, true]);

it('preserves the complete document graph on refusal', function (string $target) {
    $quote = Quote::create(['user_id' => $this->user->id, 'client_id' => $this->client->id,
        'quote_number' => 'DEV-2026-0001', 'date' => '2026-11-09']);
    $quote->items()->create(['description' => 'Devis', 'quantity' => 1, 'unit_price_ht' => 100, 'line_total_ht' => 100]);
    // A draft first also checks that the guard examines more than the first invoice.
    deletionInvoice($this->user, $this->client, ['document_status' => 'draft', 'quote_id' => $quote->id]);
    $invoice = deletionInvoice($this->user, $this->client, ['quote_id' => $quote->id, 'status' => 'payee', 'amount_paid' => 120]);
    $invoice->items()->create(['description' => 'Facture', 'quantity' => 1, 'unit_price_ht' => 100, 'line_total_ht' => 100]);
    $invoice->payments()->create(['amount' => 120, 'paid_at' => '2026-11-09']);
    $invoice->creditNotes()->create(['user_id' => $this->user->id, 'date' => '2026-11-09',
        'reason' => 'Remboursement', 'subtotal_ht' => 100, 'total_tva' => 20, 'total_ttc' => 120]);
    $electronic = $invoice->electronicInvoices()->create(['status' => 'sent',
        'request_payload' => ['invoice' => 'preserved'], 'response_payload' => ['id' => 'local-only']]);
    $electronic->statuses()->create(['status' => 'accepted', 'payload' => ['detail' => 'preserved']]);
    $before = deletionData();

    assertDeletionRefused($this, requestProtectedDeletion($this, $target), $target);

    expect(deletionData())->toEqual($before);
})->with(['client', 'account']);

it('keeps deletion working with no invoice or only drafts', function (string $target, bool $draft) {
    if ($draft) {
        $quote = Quote::create(['user_id' => $this->user->id, 'client_id' => $this->client->id,
            'quote_number' => 'DEV-2026-0001', 'date' => '2026-11-09']);
        $quote->items()->create(['description' => 'Devis']);
        $invoice = deletionInvoice($this->user, $this->client, ['document_status' => 'draft', 'quote_id' => $quote->id]);
        $invoice->items()->create(['description' => 'Brouillon']);
    }
    // A different owner's definitive invoice must not block this deletion.
    $other = User::factory()->create();
    $otherClient = Client::create(['user_id' => $other->id]);
    $otherInvoice = deletionInvoice($other, $otherClient);
    $response = requestProtectedDeletion($this, $target);
    $response->assertSessionHasNoErrors()->assertRedirect($target === 'client' ? route('clients.index') : '/');
    $this->assertDatabaseMissing('clients', ['id' => $this->client->id]);
    if ($draft) {
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('quotes', ['id' => $quote->id]);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('quote_items', 0);
    }
    $this->assertDatabaseHas('invoices', ['id' => $otherInvoice->id]);
    if ($target === 'client') {
        $this->assertAuthenticatedAs($this->user);
    } else {
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }
})->with(['client', 'account'])->with([false, true]);

it('does not let another user delete a client', function () {
    $this->actingAs(User::factory()->create())
        ->delete(route('clients.destroy', $this->client))->assertForbidden();
    $this->assertDatabaseHas('clients', ['id' => $this->client->id]);
});

it('rechecks ownership after rereading a stale client under lock', function () {
    $other = User::factory()->create();
    Client::whereKey($this->client->id)->update(['user_id' => $other->id]);
    try {
        app(ClientController::class)->destroy($this->client);
        $this->fail('Expected ownership refusal.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    $this->assertDatabaseHas('clients', ['id' => $this->client->id, 'user_id' => $other->id]);
});

it('reads current invoice status inside the deletion transaction after the user query', function (string $target) {
    $invoice = deletionInvoice($this->user, $this->client, ['document_status' => 'draft']);
    $observed = [];
    DB::listen(function ($query) use (&$observed, $invoice) {
        if (! str_starts_with($query->sql, 'select')) {
            return;
        }
        $observed[] = $query->sql;
        if (str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'limit 1')) {
            // Simulate a finalization committed before the invoice re-read.
            DB::table('invoices')->where('id', $invoice->id)->update(['document_status' => 'issued']);
        }
        if (str_contains($query->sql, '"document_status"')) {
            expect(DB::transactionLevel())->toBeGreaterThan(1);
            expect(collect($observed)->contains(fn ($sql) => str_contains($sql, 'from "users"')))->toBeTrue();
        }
    });
    assertDeletionRefused($this, requestProtectedDeletion($this, $target), $target);
})->with(['client', 'account']);

it('rolls back a failed deletion and keeps the user authenticated', function (string $target) {
    $class = $target === 'client' ? Client::class : User::class;
    Event::listen('eloquent.deleted: '.$class, function () {
        throw new RuntimeException('Local deletion failure');
    });
    $this->withoutExceptionHandling();
    $before = deletionData();
    try {
        requestProtectedDeletion($this, $target);
        $this->fail('Expected deletion failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Local deletion failure');
    }
    expect(deletionData())->toEqual($before);
    $this->assertAuthenticatedAs($this->user);
})->with(['client', 'account']);
