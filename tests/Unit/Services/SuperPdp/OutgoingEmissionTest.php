<?php

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\ElectronicInvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionDocument;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\OutgoingEmission;
use App\Services\SuperPdp\Emission\SdkTestEmissionTransport;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use Bluerock\SuperPdp\Requests\InvoiceEvents\ListInvoiceEventsRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Saloon\Config;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\EmissionTestTransport;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\TestCase;

// Test d'intégration sans transaction englobante : vérifier de vrais COMMIT avant le faux appel.
uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->owner->company()->create([
        'company_name' => 'Vendeur historique', 'siret' => '73282932000074', 'country_code' => 'FR',
        'address' => '1 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
        'vat_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat',
        'invoice_recovery_indemnity_text' => 'PMT historique', 'invoice_late_payment_penalties_text' => 'PMD historique',
        'invoice_early_payment_discount_text' => 'AAB historique', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ]);
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Acheteur historique', 'client_type' => 'professional', 'siret' => '55210055400013',
        'country_code' => 'FR', 'address' => '2 rue Test', 'postal_code' => '69001', 'city' => 'Lyon',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ]);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-10', 'due_date' => '2026-12-10',
        'items' => [['description' => 'Test orchestration', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->reference = 'invoice-cii/v1/validated/'.$this->owner->id.'/'.$this->invoice->id.'/'.str_repeat('a', 64);
    // Lecteur de documents/prérequis entièrement remplacé pour tester la mécanique.
    // Ce fichier fictif n'est jamais archivé ni considéré comme une preuve réelle.
    $this->document = new EmissionDocument($this->owner->id, $this->invoice->id, $this->reference,
        CapturedSandboxCii::xml(), 123, 'production', 'validated_private_document');
    $this->gate = new class($this->document) implements EmissionPrerequisites
    {
        public bool $blocked = false;

        public function __construct(public EmissionDocument $document) {}

        public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument
        {
            if ($this->blocked || $invoice->amount_paid !== '0.00') {
                throw ValidationException::withMessages(['emission' => 'Prérequis de test refusés.']);
            }

            return $this->document;
        }
    };
    $this->transport = new EmissionTestTransport;
    $this->service = new OutgoingEmission($this->gate, $this->transport);
    $this->reserve = function (bool $retry = false) {
        $attempt = $this->service->reserve($this->owner, $this->invoice, $this->reference, $retry);
        $this->record = ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);

        return $attempt;
    };
    $this->dispatch = fn ($attempt) => $this->service->dispatch($this->owner, $this->record, $attempt->id);
    $this->event = fn (int $id, string $code = 'fr:205') => ['id' => $id, 'invoice_id' => 900,
        'status_code' => $code, 'status_text' => 'Événement de test', 'created_at' => '2026-11-10T10:00:00+00:00'];
});

afterEach(function () {
    Http::assertNothingSent();
    Config::allowStrayRequests();
});

it('persists one reservation and freezes the exact bytes and local references without touching the invoice', function () {
    $before = $this->invoice->getRawOriginal();
    $attempt = ($this->reserve)();
    $again = ($this->reserve)();
    expect($again->id)->toBe($attempt->id)->and(ElectronicInvoice::count())->toBe(1)->and(ElectronicInvoiceAttempt::count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)->and(DB::getPdo()->inTransaction())->toBeFalse()
        ->and($this->record->cii_sha256)->toBe(hash('sha256', $this->document->xml))
        ->and(strlen($attempt->reference))->toBe(36)->and(strlen($this->record->local_reference))->toBe(36)
        ->and($this->record->execution_mode)->toBe('test')->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
});

it('claims once and commits before dispatch even with reentrant clicks or a second worker', function () {
    $attempt = ($this->reserve)();
    $this->transport->onSend = function () use ($attempt) {
        expect(DB::transactionLevel())->toBe(0)->and($attempt->fresh()->transport_started_at)->not->toBeNull();
        $same = ($this->reserve)(true);
        $second = ($this->dispatch)($same);
        expect($second->id)->toBe($attempt->id)->and($second->status)->toBe('pending');

        return ['status' => 200, 'body' => json_encode($this->transport->remote)];
    };
    $result = ($this->dispatch)($attempt);
    ($this->dispatch)($attempt);
    expect($result->status)->toBe('accepted')->and($this->transport->calls)->toBe(1)
        ->and($this->record->fresh()->provider_invoice_id)->toBe(900)
        ->and($this->record->fresh()->business_status)->toBeNull()->and($this->invoice->fresh()->status)->toBe('non_payee');
});

it('keeps all ambiguous responses unknown and prohibits automatic or explicit retry', function (int $http, string $body) {
    $attempt = ($this->reserve)();
    $this->transport->onSend = fn () => ['status' => $http, 'body' => $body];
    $result = ($this->dispatch)($attempt);
    $again = ($this->reserve)(true);
    ($this->dispatch)($again);
    expect($result->status)->toBe('unknown')->and($again->id)->toBe($attempt->id)
        ->and(ElectronicInvoiceAttempt::count())->toBe(1)->and($this->transport->calls)->toBe(1)
        ->and($this->record->fresh()->provider_invoice_id)->toBeNull();
})->with([
    [200, '{}'], [200, 'invalid JSON'], [200, '{"id":900,"id":901}'],
    [200, '{"id":"900","company_id":123,"direction":"out"}'],
    [201, '{}'], [202, '{}'], [400, '<html>refused</html>'],
    [400, '{"http_status_code":500,"message":"error"}'],
    [401, '{"http_status_code":401,"message":"not authorized"}'], [403, '{}'], [409, '{}'], [429, '{}'], [500, '{}'], [503, '{}'],
]);

it('records a simulated timeout or connection error as unknown without retaining secrets', function (string $error) {
    $attempt = ($this->reserve)();
    $this->transport->onSend = fn () => throw new RuntimeException($error.' SECRET-TOKEN');
    $result = ($this->dispatch)($attempt);
    expect($result->status)->toBe('unknown')->and($result->error_code)->toBe('transport_uncertain')
        ->and(json_encode($result->toArray()))->not->toContain('SECRET-TOKEN');
    ($this->dispatch)(($this->reserve)(true));
    expect($this->transport->calls)->toBe(1);
})->with(['timeout_after_possible_upload', 'connection_reset']);

it('permits only an explicit new attempt after a documented certain refusal', function () {
    $first = ($this->reserve)();
    $this->transport->onSend = fn () => ['status' => 400, 'body' => json_encode(['http_status_code' => 400, 'code' => 10, 'message' => 'SECRET-BODY'])];
    expect(($this->dispatch)($first)->status)->toBe('rejected');
    expect(($this->reserve)()->id)->toBe($first->id);
    $second = ($this->reserve)(true);
    expect($second->number)->toBe(2)->and($second->reference)->not->toBe($first->reference)
        ->and($second->cii_sha256)->toBe($first->cii_sha256)->and($first->fresh()->history)->toHaveCount(3);
    $this->transport->onSend = null;
    expect(($this->dispatch)($second)->status)->toBe('accepted')->and($this->transport->calls)->toBe(2)
        ->and($first->fresh()->status)->toBe('rejected')->and(json_encode(ElectronicInvoice::sole()->toArray()))->not->toContain('SECRET-BODY');
});

it('does not accept a remote identity direction or correlation that does not match', function (string $field, mixed $value) {
    $attempt = ($this->reserve)();
    $this->transport->onSend = function () use ($field, $value) {
        return ['status' => 200, 'body' => json_encode(array_replace($this->transport->remote, [$field => $value]))];
    };
    expect(($this->dispatch)($attempt)->status)->toBe('unknown');
})->with([['company_id', 999], ['direction', 'in'], ['external_id', 'other'], ['id', -1], ['created_at', 'invalid']]);

it('recovers an interrupted claimed dispatch as unknown and never reclaims it', function () {
    $attempt = ($this->reserve)();
    $attempt->update(['transport_started_at' => now()->subMinutes(10), 'claim_token' => (string) Str::uuid()]);
    expect(($this->dispatch)($attempt)->status)->toBe('pending')->and($this->transport->calls)->toBe(0);
    $result = $this->service->recoverInterrupted($this->owner, $this->record, $attempt->id);
    expect($result->status)->toBe('unknown');
    ($this->dispatch)(($this->reserve)(true));
    expect($this->transport->calls)->toBe(0)->and(ElectronicInvoiceAttempt::count())->toBe(1);
});

it('reconciles only a positive scoped remote match including exact original bytes', function (string $case) {
    $attempt = ($this->reserve)();
    $this->transport->onSend = fn () => throw new RuntimeException('lost response');
    ($this->dispatch)($attempt);
    match ($case) {
        'accepted' => null,
        'company' => $this->transport->remote['company_id'] = 999,
        'external' => $this->transport->remote['external_id'] = 'other',
        'direction' => $this->transport->remote['direction'] = 'in',
        'hash' => $this->transport->bytes = 'other XML',
        'absent' => $this->transport->remote = [],
    };
    $result = $this->service->reconcile($this->owner, $this->record, $attempt->id, 900);
    expect($result->status)->toBe($case === 'accepted' ? 'accepted' : 'unknown')
        ->and($this->transport->calls)->toBe(1)->and(ElectronicInvoiceAttempt::count())->toBe(1);
    if ($case === 'accepted') {
        expect($result->reconciliation['result'])->toBe('positive_remote_match')->and(array_column($result->history, 'status'))->toBe(['pending', 'pending', 'unknown', 'accepted']);
    }
})->with(['accepted', 'company', 'external', 'direction', 'hash', 'absent']);

it('stores events idempotently and preserves payment states for all provider business events', function () {
    ($this->dispatch)(($this->reserve)());
    $before = $this->invoice->fresh()->getRawOriginal();
    $events = [($this->event)(3, 'fr:212'), ($this->event)(4, 'api:invalid'), ($this->event)(5, 'new:future-code')];
    expect($this->service->applyEvents($this->owner, $this->record, $events))->toBe(3)
        ->and($this->service->applyEvents($this->owner, $this->record, $events))->toBe(0)
        ->and(ElectronicInvoiceStatus::count())->toBe(3)->and($this->record->fresh()->business_status)->toBe('fr:212')
        ->and($this->record->fresh()->status)->toBe('accepted')->and($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($this->invoice->payments()->count())->toBe(0)->and($this->record->fresh()->event_cursor)->toBeNull();
    $this->service->applyEvents($this->owner, $this->record, [($this->event)(1, 'fr:200')]);
    expect($this->record->fresh()->business_status)->toBe('fr:212');
    ($this->dispatch)(($this->reserve)(true));
    expect($this->transport->calls)->toBe(1);
});

it('rolls back a whole event batch on foreign malformed or contradictory duplicates', function (string $case) {
    ($this->dispatch)(($this->reserve)());
    $event = ($this->event)(1);
    $this->service->applyEvents($this->owner, $this->record, [$event]);
    $bad = ($this->event)(2);
    match ($case) {
        'foreign' => $bad['invoice_id'] = 999,
        'malformed' => $bad['status_code'] = [],
        'duplicate' => $bad = array_replace($event, ['status_code' => 'fr:210']),
    };
    expect(fn () => $this->service->applyEvents($this->owner, $this->record, [($this->event)(3), $bad]))->toThrow(ValidationException::class);
    expect(ElectronicInvoiceStatus::count())->toBe(1)->and($this->record->fresh()->business_event_id)->toBe(1);
})->with(['foreign', 'malformed', 'duplicate']);

it('handles paginated outgoing events without advancing the cursor from out of order notifications', function () {
    ($this->dispatch)(($this->reserve)());
    $this->service->applyEvents($this->owner, $this->record, [($this->event)(100, 'fr:205')]);
    $this->transport->page = ['data' => [($this->event)(1, 'fr:200'), ($this->event)(2, 'fr:201')], 'has_after' => true];
    expect($this->service->syncEvents($this->owner, $this->record))->toBe(['processed' => 2, 'has_after' => true])
        ->and($this->record->fresh()->event_cursor)->toBe(2)->and($this->record->fresh()->business_status)->toBe('fr:205');
    $this->transport->page = ['data' => [($this->event)(100, 'fr:205')], 'has_after' => false];
    expect($this->service->syncEvents($this->owner, $this->record)['processed'])->toBe(0)
        ->and($this->record->fresh()->event_cursor)->toBe(100)->and($this->transport->eventQueries)->toBe([[900, null], [900, 2]]);
});

it('rejects invalid pages atomically and leaves the polling cursor unchanged', function (string $case) {
    ($this->dispatch)(($this->reserve)());
    $this->transport->page = match ($case) {
        'missing' => ['data' => [($this->event)(1)]],
        'partial_empty' => ['data' => [], 'has_after' => true],
        'reversed' => ['data' => [($this->event)(2), ($this->event)(1)], 'has_after' => false],
        'foreign' => ['data' => [($this->event)(1), array_replace(($this->event)(2), ['invoice_id' => 999])], 'has_after' => false],
    };
    expect(fn () => $this->service->syncEvents($this->owner, $this->record))->toThrow(ValidationException::class);
    expect(ElectronicInvoiceStatus::count())->toBe(0)->and($this->record->fresh()->event_cursor)->toBeNull();
})->with(['missing', 'partial_empty', 'reversed', 'foreign']);

it('keeps every real entry point closed and rejects step 2 simulation artifacts', function () {
    expect(app(EmissionPrerequisites::class))->toBeInstanceOf(ClosedEmissionPrerequisites::class)
        ->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
    expect(fn () => app(OutgoingEmission::class)->reserve($this->owner, $this->invoice, $this->reference))->toThrow(ValidationException::class);
    $simulation = str_replace('/validated/', '/simulations/', $this->reference);
    expect(fn () => $this->service->reserve($this->owner, $this->invoice, $simulation))->toThrow(InvalidArgumentException::class);
    expect(fn () => new EmissionDocument($this->owner->id, $this->invoice->id, $simulation, 'x', 123, 'production', 'offline_simulation'))->toThrow(InvalidArgumentException::class);
    expect(ElectronicInvoice::count())->toBe(0)->and(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice)['eligible'])->toBeFalse();
});

it('blocks another owner and revalidates document identity and prerequisites at dispatch', function () {
    expect(fn () => $this->service->reserve($this->other, $this->invoice, $this->reference))->toThrow(InvalidArgumentException::class);
    $attempt = ($this->reserve)();
    expect(fn () => $this->service->dispatch($this->other, $this->record, $attempt->id))->toThrow(HttpException::class);
    $this->gate->blocked = true;
    expect(($this->dispatch)($attempt)->status)->toBe('rejected')->and($this->transport->calls)->toBe(0);
});

it('refuses a changed CII context and legacy emission histories without rewriting them', function () {
    $attempt = ($this->reserve)();
    $this->gate->document = new EmissionDocument($this->owner->id, $this->invoice->id, $this->reference, 'different bytes', 123, 'production', 'validated_private_document');
    expect(fn () => ($this->reserve)())->toThrow(ValidationException::class);
    expect(($this->dispatch)($attempt)->status)->toBe('rejected')->and($this->transport->calls)->toBe(0);
});

it('requires durable commit boundaries and refuses all test adapters outside testing', function () {
    DB::beginTransaction();
    try {
        expect(fn () => ($this->reserve)())->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    app()->instance('env', 'production');
    expect(fn () => new SdkTestEmissionTransport([]))->toThrow(RuntimeException::class);
    expect(fn () => ($this->reserve)())->toThrow(ValidationException::class);
});

it('uses the SDK send with exact XML stable correlation and destination precheck enabled', function () {
    $attempt = ($this->reserve)();
    $remote = ['id' => 900, 'company_id' => 123, 'direction' => 'out', 'external_id' => $attempt->reference, 'created_at' => '2026-11-10T10:00:00+00:00'];
    $sdk = new SdkTestEmissionTransport([CreateInvoiceRequest::class => MockResponse::make($remote)]);
    $service = new OutgoingEmission($this->gate, $sdk);
    expect($service->dispatch($this->owner, $this->record, $attempt->id)->status)->toBe('accepted');
    $requests = $sdk->requests();
    expect($requests)->toHaveCount(1)->and($requests[0]['url'])->toBe('https://api.superpdp.tech/v1.beta/invoices')
        ->and($requests[0]['method'])->toBe('POST')->and($requests[0]['body'])->toBe($this->document->xml)
        ->and($requests[0]['query'])->toBe(['external_id' => $attempt->reference]);
});

it('has no SDK network fallback for missing responses', function () {
    $attempt = ($this->reserve)();
    $sdk = new SdkTestEmissionTransport([]);
    $service = new OutgoingEmission($this->gate, $sdk);
    expect($service->dispatch($this->owner, $this->record, $attempt->id)->status)->toBe('unknown')->and($sdk->requests())->toBe([]);
});

it('rejects preexisting legacy emission records without reclassifying them', function () {
    $legacy = ElectronicInvoice::create(['invoice_id' => $this->invoice->id, 'status' => 'legacy', 'iopole_invoice_id' => 'old-id']);
    $before = $legacy->fresh()->getRawOriginal();
    expect(fn () => ($this->reserve)())->toThrow(ValidationException::class);
    expect($legacy->fresh()->getRawOriginal())->toBe($before)->and(ElectronicInvoiceAttempt::count())->toBe(0);
});

it('enforces database uniqueness for reservations attempt sequences and event identities', function () {
    $attempt = ($this->reserve)();
    expect(fn () => DB::table('electronic_invoices')->insert(['invoice_id' => $this->invoice->id, 'dispatch_key' => $this->record->dispatch_key]))
        ->toThrow(QueryException::class);
    expect(fn () => $this->record->attempts()->create([
        'number' => 1, 'reference' => (string) Str::uuid(), 'status' => 'pending',
        'cii_sha256' => $attempt->cii_sha256, 'history' => [],
    ]))->toThrow(QueryException::class);
    ($this->dispatch)($attempt);
    $this->service->applyEvents($this->owner, $this->record, [($this->event)(1)]);
    $event = ElectronicInvoiceStatus::sole();
    expect(fn () => DB::table('electronic_invoice_statuses')->insert(['electronic_invoice_id' => $this->record->id, 'event_key' => $event->event_key]))
        ->toThrow(QueryException::class);
});

it('records an uncertain outcome if storing the accepted result fails after the transport', function () {
    $attempt = ($this->reserve)();
    $fail = true;
    Event::listen('eloquent.updating: '.ElectronicInvoice::class, function ($record) use (&$fail) {
        if ($record->status === 'accepted' && $fail) {
            $fail = false;
            throw new RuntimeException('write failed');
        }
    });
    $result = ($this->dispatch)($attempt);
    expect($result->status)->toBe('unknown')->and($result->error_code)->toBe('result_persistence_uncertain')
        ->and($this->record->fresh()->provider_invoice_id)->toBeNull()->and($this->transport->calls)->toBe(1);
    ($this->dispatch)(($this->reserve)(true));
    expect($this->transport->calls)->toBe(1);
});

it('does not bind the same remote invoice to a second local invoice', function () {
    ($this->dispatch)(($this->reserve)());
    $otherInvoice = $this->invoice->replicate();
    $otherInvoice->invoice_number = 'OTHER-LOCAL-INVOICE';
    $otherInvoice->save();
    $ref = 'invoice-cii/v1/validated/'.$this->owner->id.'/'.$otherInvoice->id.'/'.str_repeat('b', 64);
    $this->gate->document = new EmissionDocument($this->owner->id, $otherInvoice->id, $ref,
        $this->document->xml, 123, 'production', 'validated_private_document');
    $attempt = $this->service->reserve($this->owner, $otherInvoice, $ref);
    $second = ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);
    $result = $this->service->dispatch($this->owner, $second, $attempt->id);
    expect($result->status)->toBe('unknown')->and($result->error_code)->toBe('remote_binding_conflict')
        ->and($second->fresh()->provider_invoice_id)->toBeNull()->and($this->record->fresh()->provider_invoice_id)->toBe(900);
});

it('treats a 400 carrying a remote invoice identity as ambiguous rather than certainly refused', function () {
    $attempt = ($this->reserve)();
    $this->transport->onSend = fn () => ['status' => 400, 'body' => json_encode(['http_status_code' => 400, 'message' => 'error', 'id' => 900])];
    expect(($this->dispatch)($attempt)->status)->toBe('unknown');
});

it('reconciles via SDK GET metadata and original content without posting again', function () {
    $attempt = ($this->reserve)();
    $this->transport->onSend = fn () => throw new RuntimeException('timeout');
    ($this->dispatch)($attempt);
    $sdk = new SdkTestEmissionTransport([MockResponse::make($this->transport->remote), MockResponse::make($this->document->xml)]);
    $service = new OutgoingEmission($this->gate, $sdk);
    expect($service->reconcile($this->owner, $this->record, $attempt->id, 900)->status)->toBe('accepted');
    $requests = $sdk->requests();
    expect(array_column($requests, 'method'))->toBe(['GET', 'GET'])->and($requests[1]['query'])->toBe(['format' => 'original'])
        ->and($this->transport->calls)->toBe(1);
});

it('collects only outgoing SDK events and keeps pagination metadata', function () {
    ($this->dispatch)(($this->reserve)());
    $sdk = new SdkTestEmissionTransport([ListInvoiceEventsRequest::class => MockResponse::make(['data' => [($this->event)(7, 'fr:213')], 'has_after' => true])]);
    $service = new OutgoingEmission($this->gate, $sdk);
    expect($service->syncEvents($this->owner, $this->record))->toBe(['processed' => 1, 'has_after' => true])
        ->and($sdk->requests()[0]['query'])->toBe(['invoice_id' => 900, 'limit' => 100])
        ->and($this->record->fresh()->status)->toBe('accepted')->and($this->record->fresh()->business_status)->toBe('fr:213');
});

it('accepts an identical duplicate with reordered keys but not a changed payload', function () {
    ($this->dispatch)(($this->reserve)());
    $event = ($this->event)(1);
    $this->service->applyEvents($this->owner, $this->record, [$event]);
    expect($this->service->applyEvents($this->owner, $this->record, [array_reverse($event, true)]))->toBe(0);
    expect(fn () => $this->service->applyEvents($this->owner, $this->record, [array_replace($event, ['status_text' => 'modified'])]))->toThrow(ValidationException::class);
});

it('denies event reconciliation and recovery access from another account', function () {
    $attempt = ($this->reserve)();
    foreach ([
        fn () => $this->service->applyEvents($this->other, $this->record, []),
        fn () => $this->service->reconcile($this->other, $this->record, $attempt->id, 900),
        fn () => $this->service->recoverInterrupted($this->other, $this->record, $attempt->id),
        fn () => $this->service->syncEvents($this->other, $this->record),
    ] as $operation) {
        expect($operation)->toThrow(HttpException::class);
    }
    expect($this->transport->calls)->toBe(0);
});

it('rolls back database failures during claim prerequisites without persisting a false rejection', function (string $type) {
    $attempt = ($this->reserve)();
    $exception = new PDOException('Simulated transactional database failure', 40001);
    if ($type === 'query') {
        $exception = new QueryException('sqlite', 'select id from fixture for update', [], $exception);
    }
    $gate = new class($exception) implements EmissionPrerequisites
    {
        public function __construct(private Throwable $exception) {}

        public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument
        {
            throw $this->exception;
        }
    };
    $failing = new OutgoingEmission($gate, $this->transport);
    expect(fn () => $failing->dispatch($this->owner, $this->record, $attempt->id))->toThrow(PDOException::class);
    $attempt->refresh();
    expect($attempt->status)->toBe('pending')->and($attempt->transport_started_at)->toBeNull()
        ->and($attempt->error_code)->toBeNull()->and($attempt->history)->toHaveCount(1)
        ->and($this->record->fresh()->status)->toBe('pending')->and($this->transport->calls)->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
    expect(($this->dispatch)($attempt)->status)->toBe('accepted')->and($this->transport->calls)->toBe(1);
})->with(['pdo', 'query']);
