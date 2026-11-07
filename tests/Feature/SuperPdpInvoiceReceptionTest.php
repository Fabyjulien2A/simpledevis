<?php

use App\Models\SuperPdpConnection;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\SuperPdp\SuperPdpInvoiceService;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function receptionConnection(string $token = 'reception-test-token'): SuperPdpConnection
{
    $user = User::factory()->create();
    $company = $user->company()->create(['company_name' => 'Entreprise destinataire']);

    return $company->superPdpConnection()->create([
        'superpdp_company_id' => 901, 'access_token' => $token, 'refresh_token' => 'reception-test-refresh',
        'access_token_expires_at' => now()->addHour(), 'status' => 'connected', 'reception_enabled' => true,
        'last_invoice_id' => 10, 'last_sync_at' => '2026-11-01 12:00:00',
    ]);
}

function receptionInvoice(int $id = 11): array
{
    return [
        'id' => $id, 'company_id' => 901, 'direction' => 'in', 'created_at' => '2026-11-05T10:00:00Z',
        'en_invoice' => [
            'number' => 'FOURNISSEUR-'.$id, 'issue_date' => '2026-11-05', 'payment_due_date' => '2026-12-05',
            'type_code' => 380, 'currency_code' => 'EUR',
            'seller' => [
                'name' => 'Fournisseur Martin', 'legal_registration_identifier' => ['value' => '73282932000074', 'scheme' => '0009'],
                'vat_identifier' => 'FR44732829320', 'electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
                'postal_address' => ['street_name' => '12 rue des Artisans', 'post_code' => '75012', 'city' => 'Paris', 'country_code' => 'FR'],
            ],
            'totals' => [
                'total_without_vat' => '100.00', 'total_vat_amount' => ['value' => '20.00', 'currency_code' => 'EUR'],
                'total_with_vat' => '120.00', 'amount_due_for_payment' => '120.00',
            ],
            'lines' => [[
                'identifier' => '1', 'invoiced_quantity' => '2.00', 'invoiced_quantity_code' => 'HUR', 'net_amount' => '100.00',
                'price_details' => ['item_net_price' => '50.00'], 'item_information' => ['name' => 'Prestation reçue'],
                'vat_information' => ['invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => '20.00'],
            ]],
        ],
        'events' => [[
            'id' => 1000 + $id, 'status_code' => 'fr:202', 'status_text' => 'Reçue', 'created_at' => '2026-11-05T11:00:00Z',
        ]],
    ];
}

function receptionPage(array $ids, bool $hasAfter = false): array
{
    return [
        'data' => array_map(fn ($id) => ['id' => $id, 'direction' => 'in', 'company_id' => 901, 'created_at' => '2026-11-05T10:00:00Z'], $ids),
        'count' => count($ids), 'has_after' => $hasAfter, 'has_before' => true,
    ];
}

function fakeReception(array $pages, array $details = []): void
{
    // Fresh fake factory also permits independent attempts within a single retry test.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($pages, $details) {
        expect($request->method())->toBe('GET');
        expect(parse_url($request->url(), PHP_URL_HOST))->toBe('superpdp.test');
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1.beta/invoices') {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            expect($query['direction'] ?? null)->toBe('in')->and($query['order'] ?? null)->toBe('asc');
            $cursor = (int) ($query['starting_after_id'] ?? 0);
            if (array_key_exists($cursor, $pages)) {
                return Http::response($pages[$cursor]);
            }
        } elseif (preg_match('~^/v1.beta/invoices/([0-9]+)$~', $path, $matches) && array_key_exists((int) $matches[1], $details)) {
            return Http::response($details[(int) $matches[1]]);
        }
        throw new RuntimeException('Requête simulée inattendue : '.$request->url());
    });
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['services.superpdp.api_url' => 'https://superpdp.test/v1.beta']);
    Http::preventStrayRequests();
    $oauth = $this->createMock(SuperPdpOAuthService::class);
    $oauth->method('ensureValidAccessToken')->willReturnCallback(fn (SuperPdpConnection $connection) => $connection);
    $this->app->instance(SuperPdpOAuthService::class, $oauth);
    $this->connection = receptionConnection();
    $this->service = app(SuperPdpInvoiceService::class);
});

it('always requests incoming invoices in ascending order and only sends positive cursors', function (?int $cursor) {
    fakeReception([$cursor !== null && $cursor > 0 ? $cursor : 0 => receptionPage([])]);
    $this->service->fetchInvoices($this->connection, $cursor);
    Http::assertSent(function (Request $request) use ($cursor) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($query['direction'])->toBe('in')->and($query['order'])->toBe('asc');
        if ($cursor !== null && $cursor > 0) {
            expect($query['starting_after_id'])->toBe((string) $cursor);
        } else {
            expect($query)->not->toHaveKey('starting_after_id');
        }

        return true;
    });
    Http::assertSentCount(1);
})->with([null, 0, -1, 10]);

it('receives an incoming invoice with the existing line event and amount mapping', function () {
    $detail = receptionInvoice();
    fakeReception([10 => receptionPage([11])], [11 => $detail]);
    expect($this->service->synchronize($this->connection))->toBe(1);
    $invoice = SupplierInvoice::sole();
    expect($invoice->company_id)->toBe($this->connection->company_id)
        ->and($invoice->direction)->toBe('in')->and($invoice->invoice_number)->toBe('FOURNISSEUR-11')
        ->and($invoice->supplier_name)->toBe('Fournisseur Martin')->and($invoice->supplier_siren)->toBe('732829320')
        ->and($invoice->supplier_address)->toBe('12 rue des Artisans')->and($invoice->supplier_city)->toBe('Paris')
        ->and($invoice->total_ht)->toBe('100.00')->and($invoice->total_vat)->toBe('20.00')
        ->and($invoice->total_ttc)->toBe('120.00')->and($invoice->amount_due)->toBe('120.00')
        ->and($invoice->payload)->toBe($detail);
    $line = $invoice->items()->sole();
    expect($line->description)->toBe('Prestation reçue')->and($line->quantity)->toBe('2.0000')
        ->and($line->unit_code)->toBe('HUR')->and($line->unit_price_ht)->toBe('50.000000')
        ->and($line->line_total_ht)->toBe('100.00')->and($line->vat_rate)->toBe('20.00')
        ->and($line->vat_amount)->toBe('20.00')->and($line->payload)->toBe($detail['en_invoice']['lines'][0]);
    $event = $invoice->events()->sole();
    expect($event->superpdp_event_id)->toBe(1011)->and($event->status)->toBe('fr:202')
        ->and($event->payload)->toBe($detail['events'][0])
        ->and($this->connection->fresh()->last_invoice_id)->toBe(11)
        ->and($this->connection->fresh()->last_sync_at->gt('2026-11-01 12:00:00'))->toBeTrue();
    Http::assertSentCount(2);
});

it('refuses invalid directions before any transaction creation or data mutation', function (bool $present, mixed $direction, bool $existing) {
    if ($existing) {
        $invoice = SupplierInvoice::create([
            'company_id' => $this->connection->company_id, 'superpdp_invoice_id' => 11, 'direction' => 'in',
            'invoice_number' => 'EXISTANTE', 'invoice_date' => '2026-11-01', 'supplier_name' => 'À conserver',
            'total_ht' => '5.00', 'total_vat' => '1.00', 'total_ttc' => '6.00',
        ]);
        $invoice->items()->create(['line_number' => 1, 'description' => 'Ligne à conserver', 'unit_price_ht' => '5.00', 'line_total_ht' => '5.00']);
        $invoice->events()->create(['superpdp_event_id' => 1011, 'event_type' => 'À conserver']);
    }
    $tables = ['supplier_invoices', 'supplier_invoice_items', 'electronic_invoice_events', 'superpdp_connections'];
    $before = array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) {
        $transactions++;
    });
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Écriture interdite pour un détail refusé.');
        }
    });
    $detail = receptionInvoice();
    if ($present) {
        $detail['direction'] = $direction;
    } else {
        unset($detail['direction']);
    }
    fakeReception([10 => receptionPage([11])], [11 => $detail]);
    expect(fn () => $this->service->synchronize($this->connection))
        ->toThrow(RuntimeException::class, 'Seules les factures entrantes SUPER PDP peuvent être importées.');
    expect(array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables))->toEqual($before)
        ->and($transactions)->toBe(0)->and($this->connection->last_invoice_id)->toBe(10);
    Http::assertSentCount(2);
})->with([
    'out' => [true, 'out'], 'absent' => [false, null], 'null' => [true, null],
    'unknown' => [true, 'unknown'], 'uppercase' => [true, 'IN'], 'numeric' => [true, 1], 'array' => [true, []],
])->with([false, true]);

it('paginates with the last processed id and retains direction and order on every page', function () {
    fakeReception([10 => receptionPage([11, 12], true), 12 => receptionPage([15])], [
        11 => receptionInvoice(11), 12 => receptionInvoice(12), 15 => receptionInvoice(15),
    ]);
    expect($this->service->synchronize($this->connection))->toBe(3)
        ->and($this->connection->fresh()->last_invoice_id)->toBe(15);
    $this->assertDatabaseCount('supplier_invoices', 3);
    $this->assertDatabaseCount('supplier_invoice_items', 3);
    $this->assertDatabaseCount('electronic_invoice_events', 3);
    Http::assertSentCount(5);
});

it('accepts an empty final response without resetting the cursor', function () {
    fakeReception([10 => receptionPage([])]);
    expect($this->service->synchronize($this->connection))->toBe(0)
        ->and($this->connection->fresh()->last_invoice_id)->toBe(10);
    $this->assertDatabaseCount('supplier_invoices', 0);
    Http::assertSentCount(1);
});

it('accepts an empty final page after a page which advanced the cursor', function () {
    fakeReception([10 => receptionPage([11], true), 11 => receptionPage([])], [11 => receptionInvoice()]);
    expect($this->service->synchronize($this->connection))->toBe(1)
        ->and($this->connection->fresh()->last_invoice_id)->toBe(11);
    Http::assertSentCount(3);
});

it('stops an abnormal page which promises more data without cursor progress', function (array $summaries) {
    fakeReception([10 => array_replace(receptionPage([], true), ['data' => $summaries])]);
    expect(fn () => $this->service->synchronize($this->connection))
        ->toThrow(RuntimeException::class, 'aucune progression du curseur');
    expect($this->connection->fresh()->last_invoice_id)->toBe(10)
        ->and($this->connection->fresh()->last_sync_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 12:00:00');
    $this->assertDatabaseCount('supplier_invoices', 0);
    Http::assertSentCount(1);
})->with(['empty' => [[]], 'old ids' => [[['id' => 9], ['id' => 10]]], 'missing id' => [[[]]], 'zero' => [[['id' => 0]]]]);

it('stops a repeated later page instead of looping or committing the final cursor', function () {
    fakeReception([10 => receptionPage([11], true), 11 => receptionPage([11], true)], [11 => receptionInvoice()]);
    expect(fn () => $this->service->synchronize($this->connection))->toThrow(RuntimeException::class, 'aucune progression');
    $this->assertDatabaseCount('supplier_invoices', 1);
    expect($this->connection->fresh()->last_invoice_id)->toBe(10);
    Http::assertSentCount(3);
});

it('rejects incoherent list responses without advancing the cursor', function (mixed $response) {
    fakeReception([10 => $response]);
    expect(fn () => $this->service->synchronize($this->connection))->toThrow(RuntimeException::class);
    expect($this->connection->fresh()->last_invoice_id)->toBe(10)
        ->and($this->connection->fresh()->last_sync_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 12:00:00');
    $this->assertDatabaseCount('supplier_invoices', 0);
    Http::assertSentCount(1);
})->with([
    ['not JSON'], [[]], [['data' => null, 'has_after' => false]], [['data' => 'incorrect', 'has_after' => false]],
    [['data' => ['unexpected' => ['id' => 11]], 'has_after' => false]],
    [['data' => [], 'has_after' => 'false']], [['data' => [], 'has_after' => 1]], [['data' => []]],
    [['data' => ['invalid summary'], 'has_after' => false]],
]);

it('keeps successful earlier imports on failure and retries without invoice or event duplicates', function () {
    $invalid = receptionInvoice(12);
    $invalid['direction'] = 'out';
    fakeReception([10 => receptionPage([11], true), 11 => receptionPage([12])], [11 => receptionInvoice(), 12 => $invalid]);
    expect(fn () => $this->service->synchronize($this->connection))->toThrow(RuntimeException::class, 'Seules les factures entrantes');
    $originalId = SupplierInvoice::sole()->id;
    expect($this->connection->fresh()->last_invoice_id)->toBe(10);
    $this->assertDatabaseCount('supplier_invoice_items', 1);
    $this->assertDatabaseCount('electronic_invoice_events', 1);
    fakeReception([10 => receptionPage([11], true), 11 => receptionPage([12])], [11 => receptionInvoice(), 12 => receptionInvoice(12)]);
    expect($this->service->synchronize($this->connection))->toBe(2)
        ->and(SupplierInvoice::where('superpdp_invoice_id', 11)->sole()->id)->toBe($originalId)
        ->and($this->connection->fresh()->last_invoice_id)->toBe(12);
    $this->assertDatabaseCount('supplier_invoices', 2);
    $this->assertDatabaseCount('supplier_invoice_items', 2);
    $this->assertDatabaseCount('electronic_invoice_events', 2);
    fakeReception([12 => receptionPage([])]);
    expect($this->service->synchronize($this->connection))->toBe(0);
    $this->assertDatabaseCount('supplier_invoices', 2);
    Http::assertSentCount(1);
});

it('rejects malformed detail responses without saving the invoice or final cursor', function (mixed $detail) {
    fakeReception([10 => receptionPage([11])], [11 => $detail]);
    expect(fn () => $this->service->synchronize($this->connection))->toThrow(RuntimeException::class);
    expect($this->connection->fresh()->last_invoice_id)->toBe(10);
    $this->assertDatabaseCount('supplier_invoices', 0);
    $this->assertDatabaseCount('supplier_invoice_items', 0);
    $this->assertDatabaseCount('electronic_invoice_events', 0);
    Http::assertSentCount(2);
})->with([['invalid JSON'], [null], [[]]]);

it('preserves the per-invoice transaction when a child record cannot be stored', function () {
    $detail = receptionInvoice();
    $detail['en_invoice']['lines'][] = $detail['en_invoice']['lines'][0];
    fakeReception([10 => receptionPage([11])], [11 => $detail]);
    expect(fn () => $this->service->synchronize($this->connection))->toThrow(QueryException::class);
    $this->assertDatabaseCount('supplier_invoices', 0);
    $this->assertDatabaseCount('supplier_invoice_items', 0);
    $this->assertDatabaseCount('electronic_invoice_events', 0);
    expect($this->connection->fresh()->last_invoice_id)->toBe(10);
});

it('runs the scheduled command using the protected real service with simulated HTTP', function () {
    fakeReception([10 => receptionPage([11])], [11 => receptionInvoice()]);
    $this->artisan('superpdp:sync-invoices')
        ->expectsOutputToContain('1 facture(s), 0 échec(s).')->assertExitCode(0);
    expect(SupplierInvoice::sole()->direction)->toBe('in');
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'superpdp:sync-invoices'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/15 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
    Http::assertSentCount(2);
});

it('reports a command failure but continues synchronizing the other connected company', function () {
    $other = receptionConnection('other-company-token');
    Http::fake(function (Request $request) {
        $other = $request->hasHeader('Authorization', 'Bearer other-company-token');
        if (parse_url($request->url(), PHP_URL_PATH) === '/v1.beta/invoices') {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            expect($query['direction'])->toBe('in')->and($query['order'])->toBe('asc');

            return Http::response(receptionPage([$other ? 12 : 11]));
        }
        $detail = receptionInvoice($other ? 12 : 11);
        $detail['direction'] = $other ? 'in' : 'out';

        return Http::response($detail);
    });
    $this->artisan('superpdp:sync-invoices')->expectsOutputToContain('1 facture(s), 1 échec(s).')->assertExitCode(1);
    expect($this->connection->fresh()->last_invoice_id)->toBe(10)->and($other->fresh()->last_invoice_id)->toBe(12)
        ->and(SupplierInvoice::sole()->company_id)->toBe($other->company_id);
    Http::assertSentCount(4);
});

it('does not call HTTP when the command has no eligible connection', function (array $attributes) {
    $this->connection->update($attributes);
    Http::fake();
    $this->artisan('superpdp:sync-invoices')->expectsOutputToContain('Aucune connexion SUPER PDP active.')->assertExitCode(0);
    Http::assertNothingSent();
})->with([[['status' => 'disconnected']], [['access_token' => null]]]);
