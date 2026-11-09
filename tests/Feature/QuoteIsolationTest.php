<?php

use App\Http\Controllers\QuoteController;
use App\Models\Quote;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    foreach (['Alpha', 'Beta'] as $name) {
        $user = User::factory()->create(['name' => 'Artisan '.$name]);
        $user->company()->create(['company_name' => 'Atelier '.$name]);
        $client = $user->clients()->create([
            'first_name' => 'Client', 'last_name' => $name,
            'email' => strtolower($name).'-prive@example.test', 'address' => 'Adresse privée '.$name,
        ]);
        $quote = Quote::create([
            'user_id' => $user->id, 'client_id' => $client->id, 'quote_number' => 'DEV-LOCAL-'.$name,
            'date' => '2026-11-09', 'status' => 'brouillon',
            'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00', 'notes' => 'Note '.$name,
        ]);
        $quote->items()->create([
            'description' => 'Prestation '.$name, 'quantity' => '2', 'unit_price_ht' => '50',
            'tva_rate' => '20', 'line_total_ht' => '100',
        ]);
        $this->accounts[$name] = compact('user', 'client', 'quote');
    }
    $this->payload = ['client_id' => $this->accounts['Alpha']['client']->id, 'tva_rate' => '20',
        'items' => [['description' => 'Prestation normale', 'quantity' => '2', 'price' => '50']],
    ];
});

afterEach(function () {
    Http::assertNothingSent();
});

function quoteIsolationData(): array
{
    return collect(['clients', 'quotes', 'quote_items', 'invoices', 'invoice_items', 'invoice_counters'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

function quoteIsolationRequest($test, Quote $quote, string $action)
{
    return match ($action) {
        'show' => $test->get(route('quotes.show', $quote)),
        'edit' => $test->get(route('quotes.edit', $quote)),
        'update' => $test->put(route('quotes.update', $quote), ['status' => 'envoye']),
        'duplicate' => $test->post(route('quotes.duplicate', $quote)),
        'pdf' => $test->get(route('quotes.pdf', $quote)),
        'convert' => $test->post(route('quotes.convertToInvoice', $quote)),
    };
}

it('only lists the current owners quotes and clients for either account', function (string $name) {
    ['user' => $user, 'client' => $client, 'quote' => $quote] = $this->accounts[$name];
    $other = $this->accounts[$name === 'Alpha' ? 'Beta' : 'Alpha'];
    $this->actingAs($user)->get(route('quotes.index'))->assertOk()
        ->assertViewHas('quotes', fn ($quotes) => $quotes->modelKeys() === [$quote->id])
        ->assertDontSee($other['quote']->quote_number)->assertDontSee($other['client']->email);
    $this->get(route('quotes.create'))->assertOk()
        ->assertViewHas('clients', fn ($clients) => $clients->modelKeys() === [$client->id])
        ->assertDontSee($other['client']->email)->assertDontSee($other['client']->full_name);
})->with(['Alpha', 'Beta']);

it('creates a quote for an owned client and ignores a posted owner', function (string $name) {
    ['user' => $user, 'client' => $client] = $this->accounts[$name];
    $other = $this->accounts[$name === 'Alpha' ? 'Beta' : 'Alpha'];
    $this->actingAs($user)->post(route('quotes.store'), [
        'client_id' => $client->id, 'user_id' => $other['user']->id,
    ] + $this->payload)->assertRedirect(route('quotes.index'))->assertSessionHasNoErrors();
    $quote = Quote::latest('id')->first();
    expect($quote->user_id)->toBe($user->id)->and($quote->client_id)->toBe($client->id)
        ->and($quote->subtotal_ht)->toBe('100.00')->and($quote->total_tva)->toBe('20.00')
        ->and($quote->total_ttc)->toBe('120.00')->and($quote->items()->sole()->description)->toBe('Prestation normale');
    $this->get(route('quotes.show', $quote))->assertOk()->assertSee($client->full_name)
        ->assertDontSee($other['client']->email);
})->with(['Alpha', 'Beta']);

it('refuses a foreign client just like an unknown client without writing a quote or lines', function (string $name, bool $unknown) {
    $account = $this->accounts[$name];
    $other = $this->accounts[$name === 'Alpha' ? 'Beta' : 'Alpha'];
    $before = quoteIsolationData();
    $this->actingAs($account['user'])->postJson(route('quotes.store'), [
        'client_id' => $unknown ? 999999 : $other['client']->id,
    ] + $this->payload)->assertUnprocessable()->assertJsonValidationErrors('client_id')
        ->assertDontSee($other['client']->email)->assertDontSee($other['client']->address);
    expect(quoteIsolationData())->toBe($before);
})->with(['Alpha', 'Beta'])->with([false, true]);

it('refuses every foreign quote endpoint without rendering or changing data', function (string $name, string $action) {
    $account = $this->accounts[$name];
    $other = $this->accounts[$name === 'Alpha' ? 'Beta' : 'Alpha'];
    $before = quoteIsolationData();
    Pdf::shouldReceive('loadView')->never();
    quoteIsolationRequest($this->actingAs($account['user']), $other['quote'], $action)->assertForbidden()
        ->assertDontSee($other['client']->email)->assertDontSee($other['client']->address);
    expect(quoteIsolationData())->toBe($before);
})->with(['Alpha', 'Beta'])->with(['show', 'edit', 'update', 'duplicate', 'pdf', 'convert']);

it('refuses a historical quote linked to another accounts client on every endpoint', function (string $action) {
    $account = $this->accounts['Alpha'];
    $other = $this->accounts['Beta'];
    // Simule une association enregistrée avant la correction, sans toucher à la production.
    $account['quote']->update(['client_id' => $other['client']->id]);
    $before = quoteIsolationData();
    Pdf::shouldReceive('loadView')->never();
    quoteIsolationRequest($this->actingAs($account['user']), $account['quote'], $action)->assertForbidden()
        ->assertDontSee($other['client']->email)->assertDontSee($other['client']->address);
    expect(quoteIsolationData())->toBe($before);
})->with(['show', 'edit', 'update', 'duplicate', 'pdf', 'convert']);

it('hides historical cross-account associations from listing and searches without repairing them', function () {
    $account = $this->accounts['Alpha'];
    $other = $this->accounts['Beta'];
    $badQuote = $account['quote']->replicate();
    $badQuote->fill(['client_id' => $other['client']->id, 'quote_number' => 'DEV-INCORRECT'])->save();
    $before = quoteIsolationData();
    $this->actingAs($account['user'])->get(route('quotes.index'))->assertOk()
        ->assertViewHas('quotes', fn ($quotes) => $quotes->modelKeys() === [$account['quote']->id])
        ->assertDontSee('DEV-INCORRECT')->assertDontSee($other['client']->email);
    foreach (['Beta', 'DEV-INCORRECT'] as $search) {
        $this->get(route('quotes.index', ['search' => $search]))->assertOk()
            ->assertViewHas('quotes', fn ($quotes) => $quotes->isEmpty());
    }
    expect(quoteIsolationData())->toBe($before);
});

it('preserves all normal existing quote operations for either owner', function (string $name, string $action) {
    ['user' => $user, 'client' => $client, 'quote' => $quote] = $this->accounts[$name];
    $other = $this->accounts[$name === 'Alpha' ? 'Beta' : 'Alpha'];
    $before = $other['quote']->fresh()->getRawOriginal();
    $this->actingAs($user);
    $response = quoteIsolationRequest($this, $quote, $action);
    if (in_array($action, ['show', 'edit'], true)) {
        $response->assertOk();
    } elseif ($action === 'update') {
        $response->assertRedirect(route('quotes.show', $quote))->assertSessionHasNoErrors();
        expect($quote->fresh()->status)->toBe('envoye')->and($quote->fresh()->client_id)->toBe($client->id);
    } elseif ($action === 'pdf') {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($response->getContent())->getText();
        expect($text)->toContain($client->full_name, $client->email, $client->address)
            ->not->toContain($other['client']->email, $other['client']->address);
    } elseif ($action === 'duplicate') {
        $copy = Quote::latest('id')->first();
        $response->assertRedirect(route('quotes.show', $copy))->assertSessionHasNoErrors();
        expect($copy->id)->not->toBe($quote->id)->and($copy->user_id)->toBe($user->id)
            ->and($copy->client_id)->toBe($client->id)
            ->and($copy->only(['subtotal_ht', 'total_tva', 'total_ttc', 'notes']))
            ->toBe($quote->only(['subtotal_ht', 'total_tva', 'total_ttc', 'notes']))
            ->and($copy->items()->sole()->description)->toBe('Prestation '.$name);
    } else {
        $invoice = $quote->invoice()->sole();
        $response->assertRedirect(route('invoices.show', $invoice))->assertSessionHasNoErrors();
        expect($invoice->user_id)->toBe($user->id)->and($invoice->client_id)->toBe($client->id)
            ->and($invoice->document_status)->toBe('draft')->and($invoice->invoice_number)->toBeNull()
            ->and($invoice->only(['subtotal_ht', 'total_tva', 'total_ttc', 'notes']))
            ->toBe($quote->only(['subtotal_ht', 'total_tva', 'total_ttc', 'notes']))
            ->and($invoice->items()->sole()->description)->toBe('Prestation '.$name)
            ->and($quote->fresh()->status)->toBe('accepte');
        $this->post(route('quotes.convertToInvoice', $quote))->assertSessionHas('error');
        $this->assertDatabaseCount('invoices', 1);
    }
    expect($other['quote']->fresh()->getRawOriginal())->toBe($before);
})->with(['Alpha', 'Beta'])->with(['show', 'edit', 'update', 'duplicate', 'pdf', 'convert']);

it('does not let a status update reassign the quote client or owner', function () {
    $account = $this->accounts['Alpha'];
    $other = $this->accounts['Beta'];
    $this->actingAs($account['user'])->put(route('quotes.update', $account['quote']), [
        'status' => 'envoye', 'client_id' => $other['client']->id, 'user_id' => $other['user']->id,
    ])->assertSessionHasNoErrors();
    expect($account['quote']->fresh()->user_id)->toBe($account['user']->id)
        ->and($account['quote']->fresh()->client_id)->toBe($account['client']->id);
});

it('rechecks persisted ownership instead of trusting a stale model or loaded client', function (string $change) {
    $account = $this->accounts['Alpha'];
    $other = $this->accounts['Beta'];
    $stale = $account['quote']->load('client');
    DB::table('quotes')->where('id', $stale->id)->update($change === 'quote'
        ? ['user_id' => $other['user']->id] : ['client_id' => $other['client']->id]);
    $before = quoteIsolationData();
    $this->actingAs($account['user']);
    Pdf::shouldReceive('loadView')->never();
    try {
        app(QuoteController::class)->pdf($stale);
        $this->fail('Expected ownership refusal');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect(quoteIsolationData())->toBe($before);
})->with(['quote', 'client']);

it('rechecks the quote and client owner after the conversion lock and rolls back on refusal', function (string $change) {
    $account = $this->accounts['Alpha'];
    $other = $this->accounts['Beta'];
    $changed = false;
    Event::listen('eloquent.retrieved: '.Quote::class, function (Quote $quote) use (&$changed, $change, $account, $other) {
        if (! $changed && $quote->id === $account['quote']->id && DB::transactionLevel() > 1) {
            $changed = true;
            if ($change === 'quote') {
                DB::table('quotes')->where('id', $quote->id)->update(['user_id' => $other['user']->id]);
            } else {
                DB::table('clients')->where('id', $quote->client_id)->update(['user_id' => $other['user']->id]);
            }
        }
    });
    $before = quoteIsolationData();
    $this->actingAs($account['user'])->post(route('quotes.convertToInvoice', $account['quote']))->assertForbidden();
    expect($changed)->toBeTrue()->and(quoteIsolationData())->toBe($before);
})->with(['quote', 'client']);
