<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationClaim;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationJournal;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\ReadOnlyDraftPreviewDatabase;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(static fn () => throw new LogicException('Network forbidden'));
    Carbon::setTestNow('2026-11-14 12:00:00');
    Storage::fake('invoice_cii');
    config(['database.default' => 'offline', 'database.connections' => ['offline' => ['driver' => 'sqlite', 'database' => ':memory:']],
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta', 'superpdp_preparation.sandbox_client_id' => 'offline-client']);
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new ReadOnlyDraftPreviewDatabase;
    DB::extend('offline', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $data['buyer'] += ['company_name' => 'Acheteur figé', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554'];
    $data['buyer']['client_type'] = 'professional';
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00',
        'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])->pluck('note', 'subject_code')->all();
    $this->memory->rows = [
        'users' => [1 => ['id' => 1], 2 => ['id' => 2]],
        'companies' => [1 => ['id' => 1, 'user_id' => 1, 'company_name' => 'Current profile']],
        'invoices' => [99 => ['id' => 99, 'user_id' => 1, 'client_id' => 45,
            ...array_intersect_key($data, array_flip(['invoice_number', 'date', 'due_date', 'document_status', 'calculation_version', 'status', 'amount_paid'])),
            ...$data['totals'], 'french_business_process_type' => 'S1', 'emission_profile' => InvoiceEmissionContext::PROFILE,
            'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($data),
            'emission_context' => json_encode(InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
                ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], $data))]],
        'invoice_items' => [42 => $data['items'][0] + ['invoice_id' => 99]],
        'payments' => [], 'credit_notes' => [],
        'superpdp_connections' => [1 => ['id' => 1, 'company_id' => 1, 'environment' => 'sandbox',
            'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'superpdp_company_id' => 123, 'directory_identifier' => '732829320', 'status' => 'connected',
            'access_token' => Crypt::encryptString('ACCESS-SECRET'), 'refresh_token' => Crypt::encryptString('REFRESH-SECRET'),
            'access_token_expires_at' => now()->addHour()->toDateTimeString()]],
    ];
    $this->actor = User::findOrFail(1);
    $this->actingAs($this->actor);
    $this->invoice = Invoice::findOrFail(99);
    $this->registry = app(CiiPreparationRegistry::class);
    $this->beforeRows = $this->memory->rows;
    $this->reserve = fn () => $this->registry->reserve($this->actor, $this->invoice, 'simulation');
    // Construct only in-memory fixture bytes using the existing test helper, never a converter.
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $this->archiveFixture = function ($claim, string $validation = 'passed_simulation') {
        $binding = $claim->binding();
        $manifest = array_intersect_key($binding, array_flip(['user_id', 'invoice_id', 'source_sha256', 'payload_sha256',
            'party_snapshot_sha256', 'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id']))
            + ['schema_version' => 1, 'mode' => 'offline_simulation', 'ready_for_emission' => false,
                'cii_sha256' => hash('sha256', $this->xml),
                'validation' => ['status' => $validation, 'report' => PreparedCiiFixture::report($this->xml)]];

        return app(PreparedCiiArchive::class)->store($this->actor, $this->invoice, $this->xml, $manifest);
    };
});

afterEach(function () {
    expect($this->memory->rows)->toBe($this->beforeRows);
    Http::assertNothingSent();
    Model::setConnectionResolver($this->originalResolver);
    Carbon::setTestNow();
});

it('claims once binds all scopes and never discloses the completion capability', function () {
    $first = ($this->reserve)();
    $again = ($this->reserve)();
    expect($first['claim'])->toBeInstanceOf(CiiPreparationClaim::class)
        ->and($again['state'])->toBe('running')->and($again)->not->toHaveKey('claim')
        ->and($again['reservation_id'])->toBe($first['reservation_id']);
    $binding = $first['claim']->binding();
    expect($binding)->toHaveKeys(['user_id', 'company_id', 'invoice_id', 'connection_id', 'provider_company_id',
        'source_sha256', 'payload_sha256', 'party_snapshot_sha256', 'emission_context_sha256', 'database_scope'])
        ->and(json_encode($first['claim']))->toBe(json_encode(['id' => $first['reservation_id']]))
        ->and(json_encode($binding))->not->toContain('ACCESS-SECRET', 'REFRESH-SECRET');
    expect(fn () => serialize($first['claim']))->toThrow(LogicException::class);
});

it('reuses a completed immutable result through the producer without conversion or validation requests', function () {
    $first = ($this->reserve)();
    $artifact = ($this->archiveFixture)($first['claim']);
    app(CiiDocumentChecks::class)->assertMatches($this->xml, $this->payload);
    $done = $this->registry->complete($this->actor, $this->invoice, $first['claim'], $artifact);
    expect($done['state'])->toBe('completed');
    $second = ($this->reserve)();
    expect($second['state'])->toBe('completed')->and($second)->not->toHaveKey('claim')
        ->and($second['result']['artifact']['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and($second['result']['ready_for_emission'])->toBeFalse();
    $source = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $check = ['status' => 'passed', 'source' => 'simulated', 'verified' => false,
        'response_sha256' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
    $report = ['status' => 'passed', 'expected_environment' => 'sandbox', 'expected_provider_company_id' => 123,
        'expected_oauth_client_id' => 'offline-client', 'expected_api_url' => 'https://api.superpdp.tech/v1.beta',
        'source_sha256' => CiiPreparationEvidence::hash($source), 'party_snapshot_sha256' => hash('sha256', $source['party_snapshot']),
        'emission_context_sha256' => hash('sha256', $source['emission_context']), 'checked_at' => now()->toIso8601String(),
        'checks' => array_fill_keys(['buyer_destination', 'seller_identity', 'seller_session'], $check)];
    $audit = $this->createMock(VerificationAuditStore::class);
    $audit->method('read')->willReturn($report);
    $this->app->instance(VerificationAuditStore::class, $audit);
    $gateway = SandboxCiiPreparationGateway::forInvoice($this->actor, $this->invoice, CiiPreparationEvidence::hash($source), 'proof', []);
    Carbon::setTestNow(now()->addMinutes(16));
    $result = app(InvoiceCiiPreparation::class)->produce($this->actor, $this->invoice, 'proof', $gateway);
    expect($result['reason'])->toBe('reservation_reused')->and($gateway->requests())->toBe([]);
    expect($this->registry->result($this->actor, $this->invoice, $first['reservation_id'], 'simulation')['state'])->toBe('completed');
});

it('does not create a slot when only looking up a result and ignores token or approval rotation in the key', function () {
    expect($this->registry->existing($this->actor, $this->invoice, 'simulation'))->toBeNull();
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
    $first = ($this->reserve)();
    $this->memory->rows['superpdp_connections'][1]['access_token'] = Crypt::encryptString('ROTATED');
    config(['superpdp_preparation.authorization' => ['approved_at' => 1, 'verification_reference' => 'another-proof']]);
    $this->beforeRows = $this->memory->rows;
    expect(($this->reserve)()['reservation_id'])->toBe($first['reservation_id']);
});

it('separates API and simulation slots and refuses results for another invoice', function () {
    $first = ($this->reserve)();
    $api = $this->registry->reserve($this->actor, $this->invoice, 'api');
    expect($api['reservation_id'])->not->toBe($first['reservation_id']);
    $this->memory->rows['invoices'][100] = array_replace($this->memory->rows['invoices'][99], ['id' => 100]);
    $this->memory->rows['invoice_items'][43] = array_replace($this->memory->rows['invoice_items'][42], ['id' => 43, 'invoice_id' => 100]);
    $this->beforeRows = $this->memory->rows;
    expect(fn () => $this->registry->result($this->actor, Invoice::findOrFail(100), $first['reservation_id'], 'simulation'))
        ->toThrow(HttpException::class);
});

it('rejects incomplete invoices and production without creating a journal', function (string $case) {
    if ($case === 'production') {
        $this->app->instance('env', 'production');
    } elseif ($case === 'draft') {
        $this->memory->rows['invoices'][99]['document_status'] = 'draft';
    } else {
        $this->memory->rows['invoices'][99]['party_snapshot'] = null;
    }
    $this->beforeRows = $this->memory->rows;
    expect(fn () => $this->registry->reserve($this->actor, $this->invoice, 'api'))->toThrow(Exception::class);
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
})->with(['production', 'draft', 'missing snapshot']);

it('preserves interrupted uncertain and expired operations without automatically reclaiming them', function (string $case) {
    $first = ($this->reserve)();
    if ($case === 'uncertain') {
        $this->registry->uncertain($this->actor, $this->invoice, $first['claim']);
    } else {
        Carbon::setTestNow(now()->addMinutes(16));
    }
    $again = ($this->reserve)();
    expect($again['state'])->toBe('review_required')->and($again)->not->toHaveKey('claim');
    expect(fn () => $this->registry->complete($this->actor, $this->invoice, $first['claim'], []))->toThrow(RuntimeException::class);
})->with(['interrupted', 'uncertain']);

it('keeps a known but nonvalidated result in review without a fresh conversion', function () {
    $first = ($this->reserve)();
    $artifact = ($this->archiveFixture)($first['claim'], 'unavailable');
    $done = $this->registry->complete($this->actor, $this->invoice, $first['claim'], $artifact);
    expect($done['state'])->toBe('review_required')->and(($this->reserve)()['state'])->toBe('review_required');
});

it('prevents a competing claim from completing another operation', function () {
    $first = ($this->reserve)();
    $impostor = CiiPreparationClaim::issue($first['claim']->binding());
    expect(fn () => $this->registry->complete($this->actor, $this->invoice, $impostor, []))->toThrow(RuntimeException::class);
    expect(($this->reserve)()['state'])->toBe('running');
});

it('refuses foreign readers and changed company or provider context', function (string $case) {
    $first = ($this->reserve)();
    if ($case === 'user') {
        $this->actingAs(User::findOrFail(2));
        $this->actor = User::findOrFail(2);
    } elseif ($case === 'company') {
        $this->memory->rows['companies'][1]['id'] = 2;
    } else {
        $this->memory->rows['superpdp_connections'][1]['superpdp_company_id'] = 456;
    }
    $this->beforeRows = $this->memory->rows;
    expect(fn () => $this->registry->result($this->actor, $this->invoice, $first['reservation_id'], 'simulation'))->toThrow(Exception::class);
})->with(['user', 'company', 'provider']);

it('fails closed for incomplete or tampered journals and never recreates them', function (string $case) {
    $first = ($this->reserve)();
    $path = app(PreparedCiiArchive::class)->reservationDirectory().DIRECTORY_SEPARATOR.$first['reservation_id'].'.jsonl';
    $bytes = file_get_contents($path);
    $changed = match ($case) {
        'empty' => '', 'truncated' => substr($bytes, 0, -3), default => str_replace('running', 'completed', $bytes),
    };
    file_put_contents($path, $changed);
    expect(fn () => ($this->reserve)())->toThrow(RuntimeException::class);
    expect(file_get_contents($path))->toBe($changed);
})->with(['empty', 'truncated', 'tampered']);

it('rejects changed archive bytes and permanently quarantines the result', function () {
    $first = ($this->reserve)();
    $artifact = ($this->archiveFixture)($first['claim']);
    $this->registry->complete($this->actor, $this->invoice, $first['claim'], $artifact);
    $path = Storage::disk('invoice_cii')->path(str_replace('invoice-cii/', '', $artifact['reference']).'/document.xml');
    file_put_contents($path, 'TAMPERED');
    expect(fn () => ($this->reserve)())->toThrow(RuntimeException::class);
    $journal = new CiiPreparationJournal(app(PreparedCiiArchive::class)->reservationDirectory());
    expect($journal->read($first['claim']->binding())['state'])->toBe('review_required');
});

it('allows exactly one winner across independent PHP processes', function () {
    $directory = app(PreparedCiiArchive::class)->reservationDirectory();
    $binding = ['concurrency_test' => true, 'user_id' => 1, 'invoice_id' => 99];
    $barrier = $directory.DIRECTORY_SEPARATOR.'test-barrier';
    $script = <<<'PHP'
require $argv[1];
$deadline = microtime(true) + 10;
while (!is_file($argv[3]) && microtime(true) < $deadline) { usleep(1000); }
try {
    $journal = new \App\Services\SuperPdp\CiiPreparationJournal($argv[2]);
    $row = $journal->reserve(json_decode($argv[4], true), 1000);
    echo isset($row['claim']) ? 'claimed' : $row['state'];
} catch (RuntimeException $e) { echo 'blocked'; }
PHP;
    $processes = [];
    for ($i = 0; $i < 8; $i++) {
        $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $directory, $barrier, json_encode($binding)]);
        $process->setTimeout(15);
        $process->start();
        $processes[] = $process;
    }
    file_put_contents($barrier, 'go');
    $outcomes = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0);
        $outcomes[] = $process->getOutput();
    }
    expect(count(array_filter($outcomes, fn ($value) => $value === 'claimed')))->toBe(1);
    foreach ($outcomes as $outcome) {
        expect($outcome)->toBeIn(['claimed', 'running', 'blocked']);
    }
    $journal = new CiiPreparationJournal($directory);
    expect($journal->reserve($binding, 2000)['state'])->toBe('review_required');
});
