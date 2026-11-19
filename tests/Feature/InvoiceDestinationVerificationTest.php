<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\Verification\InvoiceDestinationVerification;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    config(['services.superpdp.directory_base_url' => 'https://api.superpdp.tech',
        'superpdp_verification.destination_live_reads_enabled' => false,
        'superpdp_verification.destination_demo' => null, 'superpdp_verification.destination_validity_minutes' => 15]);
    $this->owner = User::factory()->create();
    $data = SuperPdpPayloadFixture::data();
    $data['buyer'] = array_replace($data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Client figé', 'siret' => '55210055400013',
    ]);
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])->pluck('note', 'subject_code')->all();
    $this->client = $this->owner->clients()->create(['client_type' => 'professional', 'company_name' => 'Client actuel', 'siret' => '11111111111111']);
    $this->invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $this->client->id,
        ...array_intersect_key($data, array_flip(['invoice_number', 'date', 'due_date', 'document_status', 'calculation_version', 'status', 'amount_paid'])),
        ...$data['totals'], 'french_business_process_type' => 'S1', 'emission_profile' => InvoiceEmissionContext::PROFILE,
    ]);
    DB::table('invoices')->where('id', $this->invoice->id)->update([
        'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($data),
        'emission_context' => json_encode(InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
            ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], $data)),
    ]);
    $this->invoice->refresh();
    $this->invoice->items()->create(array_diff_key($data['items'][0], ['id' => true]));
    $this->actingAs($this->owner);
    $this->service = app(InvoiceDestinationVerification::class);
    $this->audit = app(VerificationAuditStore::class);
    $this->postCheck = fn () => $this->post(route('invoices.verify-destination', $this->invoice));
    $this->storedReport = fn () => $this->audit->latestDestination($this->owner, $this->invoice);
    // Fixture de rapport représentant une observation réelle, sans transport réseau.
    // Les MockResponse restent simulées dans tous les tests de transport.
    $this->liveReport = function () {
        config(['superpdp_verification.destination_demo' => 'match']);
        $report = $this->service->verify($this->owner, $this->invoice);
        unset($report['audit_reference']);
        $report['source'] = 'live';
        $report['status'] = 'qualified';
        $report['verified'] = true;

        return $report;
    };
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
});

it('displays the explicit POST button and records disabled verification without SQL writes', function () {
    $before = $this->invoice->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture SQL pendant la vérification.');
        }
    });
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Vérifier la destination électronique')->assertSee(route('invoices.verify-destination', $this->invoice), false);
    ($this->postCheck)()->assertRedirect(route('invoices.electronic-preparation', $this->invoice));
    $report = ($this->storedReport)();
    expect($report['reason'])->toBe('live_reads_disabled')->and($report['source'])->toBe('not_called')
        ->and($report['status'])->toBe('unavailable')->and($report['buyer_siren'])->toBe('552100554')
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->assertSee('Les consultations réelles sont désactivées');
});

it('records simulated observations and never removes the routing blocker', function () {
    config(['superpdp_verification.destination_demo' => 'match']);
    ($this->postCheck)()->assertRedirect();
    $report = ($this->storedReport)();
    expect($report['source'])->toBe('simulated')->and($report['verified'])->toBeFalse()
        ->and($report['status'])->toBe('unavailable')->and($report['assessment'])->toBe('matched')
        ->and($report['request'])->toBe(['method' => 'GET', 'endpoint' => InvoiceDestinationVerification::ENDPOINT,
            'query' => ['number' => '552100554'], 'http_status' => 200])
        ->and($report['response_sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($report['party_snapshot_sha256'])->toBe(hash('sha256', $this->invoice->getRawOriginal('party_snapshot')));
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->assertSee('Simulation — aucune qualification réelle')
        ->assertViewHas('emissionDiagnostic', fn ($result) => in_array('routing.verification_unavailable', array_column($result['blockers'], 'code'), true));
});

it('distinguishes directory outcomes including duplicate active rows', function (string $scenario, string $status) {
    config(['superpdp_verification.destination_demo' => $scenario]);
    ($this->postCheck)()->assertRedirect();
    expect(($this->storedReport)()['status'])->toBe($status)
        ->and($this->service->latest($this->owner, $this->invoice)['status'])->toBe($status);
})->with([
    ['absent', 'absent'], ['inactive', 'inactive'], ['ambiguous', 'ambiguous'],
    ['incompatible', 'incompatible'], ['api_error', 'unavailable'],
]);

it('uses the snapshot even when the current client identity changes and ignores HTTP claims', function () {
    config(['superpdp_verification.destination_demo' => 'match']);
    $this->client->update(['siret' => '73282932000074']);
    $this->post(route('invoices.verify-destination', $this->invoice), [
        'buyer_siren' => '732829320', 'source' => 'live', 'verified' => true, 'scenario' => 'absent',
        'reply' => ['data' => []], 'routing_verified' => true,
    ])->assertRedirect();
    $report = ($this->storedReport)();
    expect($report['buyer_siren'])->toBe('552100554')->and($report['assessment'])->toBe('matched')
        ->and($report['verified'])->toBeFalse()->and($report['source'])->toBe('simulated');
});

it('refuses absent snapshots or unsupported frozen addresses before any directory call', function (string $mode) {
    config(['superpdp_verification.destination_demo' => 'match']);
    if ($mode === 'snapshot') {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]);
    } else {
        $context = $this->invoice->emission_context;
        $context['buyer_electronic_address'] = $mode === 'email'
            ? ['scheme' => 'EM', 'value' => 'billing@example.invalid'] : ['scheme' => '0225', 'value' => '552100554_SERVICE'];
        DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => json_encode($context)]);
    }
    ($this->postCheck)()->assertRedirect();
    $report = ($this->storedReport)();
    expect($report['source'])->toBe('not_called')->and($report['status'])->toBe('incompatible');
})->with(['snapshot', 'email', 'suffix']);

it('reads the latest report without calling a gateway and does not fall back to an older success', function () {
    $this->audit->storeDestination($this->owner, $this->invoice, ($this->liveReport)());
    config(['superpdp_verification.destination_demo' => 'api_error']);
    ($this->postCheck)()->assertRedirect();
    $mock = MockClient::global([]);
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertViewHas('destinationReport', fn ($report) => $report['status'] === 'unavailable' && $report['request']['http_status'] === 503)
        ->assertViewHas('emissionDiagnostic', fn ($result) => ! $result['eligible']);
    $mock->assertNothingSent();
});

it('accepts only a coherent fresh archived live proof and keeps emission disabled', function () {
    $this->audit->storeDestination($this->owner, $this->invoice, ($this->liveReport)());
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Destination qualifiée')->assertSee('Non — ce contrôle de destination')
        ->assertViewHas('destinationReport', fn ($report) => $report['verified'] === true)
        ->assertViewHas('emissionDiagnostic', fn ($result) => $result['eligible'] && $result['blockers'] === []);
    expect(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
});

it('expires evidence at the configured validity boundary without network', function () {
    $report = ($this->liveReport)();
    $this->audit->storeDestination($this->owner, $this->invoice, $report);
    $this->travel(15)->minutes();
    expect($this->service->latest($this->owner, $this->invoice)['status'])->toBe('expired');
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->assertSee('Preuve expirée')
        ->assertViewHas('emissionDiagnostic', fn ($result) => ! $result['eligible']);
    config(['superpdp_verification.destination_validity_minutes' => 20]);
    expect($this->service->latest($this->owner, $this->invoice)['status'])->toBe('qualified');
});

it('rejects inconsistent stored evidence', function (string $field, mixed $value) {
    $report = ($this->liveReport)();
    data_set($report, $field, $value);
    $this->audit->storeDestination($this->owner, $this->invoice, $report);
    expect($this->service->latest($this->owner, $this->invoice)['verified'])->toBeFalse();
})->with([
    ['source_sha256', str_repeat('0', 64)], ['party_snapshot_sha256', str_repeat('0', 64)],
    ['emission_context_sha256', str_repeat('0', 64)], ['buyer_siren', '732829320'],
    ['endpoint', 'https://example.invalid'], ['request.query.number', '732829320'],
    ['observed_at', '2099-01-01T00:00:00+00:00'], ['observed_at', '2026-99-99Tgarbage'],
    ['source', 'cached'], ['request.http_status', 503], ['response_sha256', 'invalid'],
    ['source', 'simulated'], ['verified', false], ['status', 'unavailable'],
    ['declared_address.value', '732829320'], ['assessment', 'absent'],
    ['checked_at', null], ['checked_at', '2099-01-01T00:00:00+00:00'],
    ['request.method', 'POST'], ['contract_version', 'unknown'],
    ['directory_reply.data.0.is_active', false], ['directory_reply.has_more', true],
]);

it('protects authentication ownership and the definitive-only scope', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    ($this->postCheck)()->assertForbidden();
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertForbidden();
    auth()->logout();
    ($this->postCheck)()->assertRedirect(route('login'));
    $this->actingAs($this->owner);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['document_status' => 'draft']);
    ($this->postCheck)()->assertNotFound();
});

it('limits repeated explicit checks per user', function () {
    foreach (range(1, 5) as $attempt) {
        ($this->postCheck)()->assertRedirect();
    }
    ($this->postCheck)()->assertStatus(429);
});

it('rejects a cross-invoice private reference and altered archived content', function () {
    config(['superpdp_verification.destination_demo' => 'match']);
    $report = $this->service->verify($this->owner, $this->invoice);
    $other = User::factory()->create();
    expect(fn () => $this->audit->read($other, $this->invoice, $report['audit_reference']))->toThrow(HttpException::class);
    $path = str_replace('invoice-party-checks/v1/', 'party-checks/v1/', $report['audit_reference']);
    Storage::disk('invoice_cii')->put($path, '{}');
    expect($this->service->latest($this->owner, $this->invoice)['reason'])->toBe('report_unreadable');
});

it('blocks local demo mode in production even if live reads are enabled', function () {
    config(['app.env' => 'production', 'superpdp_verification.destination_demo' => 'match',
        'superpdp_verification.destination_live_reads_enabled' => true]);
    $this->app->instance('env', 'production');
    $report = $this->service->verify($this->owner, $this->invoice);
    expect($report['reason'])->toBe('demo_not_allowed');
});

it('handles partial malformed and foreign API responses without qualification', function (string $case, string $status) {
    $row = ['company' => ['number' => '552100554', 'formal_name' => 'Annuaire', 'address' => '1 rue Test',
        'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR'], 'identifier' => '0225:552100554', 'is_active' => true];
    $body = match ($case) {
        'partial' => ['data' => [$row], 'has_more' => true],
        'foreign' => ['data' => [array_replace_recursive($row, ['company' => ['number' => '732829320']])]],
        'replyto' => ['data' => [array_replace($row, ['identifier' => '0225:552100554_replyto'])]],
        'malformed' => 'invalid JSON',
        'duplicate_keys' => '{"data":[],"data":[]}',
        default => ['data' => []],
    };
    $mock = MockClient::global([ListFrenchDirectoryEntriesRequest::class => MockResponse::make($body, $case === 'auth_error' ? 403 : 200)]);
    config(['superpdp_verification.destination_live_reads_enabled' => true]);
    $report = $this->service->verify($this->owner, $this->invoice);
    expect($report['status'])->toBe($status, $report['reason'])->and($report['source'])->toBe('simulated')->and($report['verified'])->toBeFalse();
    $mock->assertSentCount(1, ListFrenchDirectoryEntriesRequest::class);
    expect($this->service->latest($this->owner, $this->invoice)['verified'])->toBeFalse();
})->with([
    ['partial', 'ambiguous'], ['foreign', 'ambiguous'], ['replyto', 'incompatible'],
    ['malformed', 'ambiguous'], ['duplicate_keys', 'ambiguous'], ['auth_error', 'unavailable'],
]);

it('does not read current party profiles or write SQL in the simulated POST', function () {
    config(['superpdp_verification.destination_demo' => 'match']);
    $before = $this->invoice->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql) || preg_match('/\b(?:clients|companies|superpdp_connections)\b/i', $sql)) {
            throw new RuntimeException('Seules les sources figées et les lectures SQL sont autorisées.');
        }
    });
    ($this->postCheck)()->assertRedirect();
    expect(($this->storedReport)()['assessment'])->toBe('matched')
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
});

it('invalidates a report when invoice data has changed and refuses a substituted invoice reference', function () {
    $this->audit->storeDestination($this->owner, $this->invoice, ($this->liveReport)());
    DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid' => '1.00']);
    expect($this->service->latest($this->owner, $this->invoice)['reason'])->toBe('invoice_source_mismatch');
    $report = ($this->storedReport)();
    Storage::disk('invoice_cii')->put('party-checks/v1/'.$this->owner->id.'/'.$this->invoice->id.'/destination.latest',
        str_replace('/'.$this->invoice->id.'/', '/999999/', $report['audit_reference']));
    expect($this->service->latest($this->owner, $this->invoice)['reason'])->toBe('report_unreadable');
});

it('displays saved evidence without writing files or calling an API on GET', function () {
    config(['superpdp_verification.destination_demo' => 'inactive']);
    ($this->postCheck)()->assertRedirect();
    $snapshot = fn () => collect(Storage::disk('invoice_cii')->allFiles())->mapWithKeys(fn ($file) => [$file => hash('sha256', Storage::disk('invoice_cii')->get($file))])->all();
    $before = $snapshot();
    $mock = MockClient::global([]);
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Destination inactive')->assertHeader('Cache-Control', 'no-store, private');
    $mock->assertNothingSent();
    expect($snapshot())->toBe($before);
});
