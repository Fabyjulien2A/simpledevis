<?php

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\ElectronicInvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePartySnapshot;
use App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\OutgoingEmission;
use App\Services\SuperPdp\Emission\SdkTestEmissionTransport;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\OfflinePreparationGateway;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Saloon\Config;
use Saloon\Http\Faking\MockResponse;
use Tests\Support\EmissionTestTransport;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SimulatedEmissionBridge;
use Tests\TestCase;

uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner)->post(route('company.update'), [
        'company_name' => 'Vendeur historique', 'country_code' => 'FR', 'siret' => '73282932000074',
        'address' => '12 rue Historique', 'postal_code' => '75012', 'city' => 'Paris',
        'tva_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat',
        'invoice_recovery_indemnity_text' => 'PMT historique', 'invoice_late_payment_penalties_text' => 'PMD historique',
        'invoice_early_payment_discount_text' => 'AAB historique',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ])->assertSessionHasNoErrors();
    $this->post(route('clients.store'), [
        'first_name' => 'Marie', 'last_name' => 'Historique', 'client_type' => 'professional',
        'country_code' => 'FR', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554',
        'address' => '3 rue Historique', 'postal_code' => '69002', 'city' => 'Lyon',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ])->assertSessionHasNoErrors();
    $this->client = $this->owner->clients()->sole();
    $this->company = $this->owner->company()->sole();
    $this->post(route('invoices.store'), [
        'action' => 'draft', 'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-09', 'due_date' => '2026-12-09',
        'items' => [['description' => 'Prestation historique', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->finalize = function () {
        expect($this->invoice->party_snapshot)->toBeNull()->and($this->invoice->emission_context)->toBeNull();
        $this->post(route('invoices.finalize', $this->invoice))->assertSessionHasNoErrors();
        $this->invoice->refresh();
        expect($this->invoice->isIssued())->toBeTrue()->and($this->invoice->party_snapshot['seller']['country_code'])->toBe('FR')
            ->and($this->invoice->emission_context['type_code'])->toBe(380)
            ->and($this->invoice->emission_context['currency_code'])->toBe('EUR')
            ->and($this->invoice->emission_context['routing_status'])->toBe('not_verified');
    };
    $this->directoryEntry = ['company' => ['number' => '552100554', 'formal_name' => 'Acheteur de l’annuaire',
        'address' => 'Annuaire', 'postcode' => '69002', 'city' => 'Lyon', 'country' => 'FR'],
        'identifier' => '0225:552100554', 'is_active' => true];
    $this->prepareCii = function (array $overrides = []) {
        $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
        $this->xml = PreparedCiiFixture::xml($this->payload);
        $gateway = new OfflinePreparationGateway($overrides + [
            ListFrenchDirectoryEntriesRequest::class => MockResponse::make(['data' => [$this->directoryEntry], 'has_more' => false]),
            GetCompanyMeRequest::class => MockResponse::make(['id' => 123, 'number_scheme' => 'fr_siren', 'number' => '732829320', 'country' => 'FR', 'env' => 'production']),
            GetSessionMeRequest::class => MockResponse::make(['client_id' => 'e2e-client', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified']),
            ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml']),
            CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
        ]);
        $this->preparationGateway = $gateway;

        return app(InvoiceCiiPreparation::class)->prepare($this->owner, $this->invoice, $gateway, 'e2e-client', 'production');
    };
    $this->bridge = function ($preparation) {
        expect($preparation['status'])->toBe('prepared_simulation')->and($preparation['ready_for_emission'])->toBeFalse();

        return new SimulatedEmissionBridge($this->owner->id, $this->invoice->id, $preparation['artifact']['reference']);
    };
    $this->remote = fn ($attempt) => ['id' => 900, 'company_id' => 123, 'direction' => 'out',
        'external_id' => $attempt->reference, 'created_at' => '2026-11-09T12:00:00+00:00'];
    $this->event = fn (int $id, string $code) => ['id' => $id, 'invoice_id' => 900, 'status_code' => $code,
        'status_text' => 'Réponse simulée', 'created_at' => '2026-11-09T12:01:00+00:00'];
});

afterEach(function () {
    Http::assertNothingSent();
    Config::allowStrayRequests();
});

it('runs the complete simulated lifecycle with exact archived bytes and immutable historical parties', function () {
    ($this->finalize)();
    $before = $this->invoice->getRawOriginal();
    $this->company->update(['company_name' => 'Vendeur actuel', 'country_code' => 'BE', 'billing_electronic_address' => null]);
    $this->client->update(['first_name' => 'Actuel', 'last_name' => 'Autre', 'siret' => '73282932000074', 'billing_electronic_address' => null]);
    $preparation = ($this->prepareCii)();
    $bridge = ($this->bridge)($preparation);
    $archive = app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $preparation['artifact']['reference']);
    expect($this->payload['buyer']['name'])->toBe('Marie Historique')->and($this->payload['seller']['name'])->toBe('Vendeur historique')
        ->and($preparation['routing']['buyer_siren'])->toBe('552100554')->and($preparation['routing']['status'])->toBe('not_verified')
        ->and($preparation['seller']['status'])->toBe('not_verified')->and($archive['xml'])->toBe($this->xml);
    $reservation = new OutgoingEmission($bridge, new EmissionTestTransport);
    $attempt = $reservation->reserve($this->owner, $this->invoice, $bridge->reference);
    $record = ElectronicInvoice::sole();
    $sdk = new SdkTestEmissionTransport([
        MockResponse::make(($this->remote)($attempt)),
        MockResponse::make(['data' => [($this->event)(1, 'api:uploaded'), ($this->event)(2, 'fr:200')], 'has_after' => true]),
        MockResponse::make(['data' => [($this->event)(3, 'fr:205'), ($this->event)(4, 'fr:212')], 'has_after' => false]),
    ]);
    $emission = new OutgoingEmission($bridge, $sdk);
    expect($emission->dispatch($this->owner, $record, $attempt->id)->status)->toBe('accepted');
    expect($emission->syncEvents($this->owner, $record))->toBe(['processed' => 2, 'has_after' => true]);
    expect($emission->syncEvents($this->owner, $record))->toBe(['processed' => 2, 'has_after' => false]);
    expect($emission->applyEvents($this->owner, $record, [($this->event)(3, 'fr:205')]))->toBe(0);
    $emission->dispatch($this->owner, $record, $attempt->id);
    $requests = $sdk->requests();
    expect(array_column($requests, 'method'))->toBe(['POST', 'GET', 'GET'])
        ->and($requests[0]['body'])->toBe($archive['xml'])->and($requests[0]['query'])->toBe(['external_id' => $attempt->reference])
        ->and($requests[2]['query'])->toBe(['invoice_id' => 900, 'starting_after_id' => 2, 'limit' => 100])
        ->and($record->fresh()->cii_sha256)->toBe($preparation['artifact']['cii_sha256'])
        ->and($record->fresh()->business_status)->toBe('fr:212')->and(ElectronicInvoiceStatus::count())->toBe(4)
        ->and(ElectronicInvoiceAttempt::count())->toBe(1)->and($this->invoice->payments()->count())->toBe(0)
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $unchanged = app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $preparation['artifact']['reference']);
    expect($unchanged)->toBe($archive)->and($unchanged['manifest']['ready_for_emission'])->toBeFalse()
        ->and(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice)['eligible'])->toBeFalse()
        ->and(app(EmissionPrerequisites::class))->toBeInstanceOf(ClosedEmissionPrerequisites::class)
        ->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
    expect(array_column($this->preparationGateway->requests(), 'class'))->toBe([
        ListFrenchDirectoryEntriesRequest::class, GetCompanyMeRequest::class, GetSessionMeRequest::class,
        ConvertInvoiceRequest::class, CreateValidationReportRequest::class,
    ]);
});

it('reuses one committed attempt across interleaved workers and sends the prepared CII to the mocked SDK once', function () {
    ($this->finalize)();
    $preparation = ($this->prepareCii)();
    $bridge = ($this->bridge)($preparation);
    $transport = new EmissionTestTransport;
    $emission = new OutgoingEmission($bridge, $transport);
    $attempt = $emission->reserve($this->owner, $this->invoice, $bridge->reference);
    $record = ElectronicInvoice::sole();
    $sdk = new SdkTestEmissionTransport([CreateInvoiceRequest::class => MockResponse::make(($this->remote)($attempt))]);
    $transport->onSend = function ($document, $reference) use ($bridge, $emission, $attempt, $record, $sdk) {
        expect(DB::transactionLevel())->toBe(0)->and($attempt->fresh()->transport_started_at)->not->toBeNull();
        $second = $emission->reserve($this->owner, $this->invoice, $bridge->reference, true);
        expect($emission->dispatch($this->owner, $record, $second->id)->status)->toBe('pending');

        return $sdk->send($document, $reference);
    };
    expect($emission->dispatch($this->owner, $record, $attempt->id)->status)->toBe('accepted');
    $emission->dispatch($this->owner, $record, $attempt->id);
    expect($transport->calls)->toBe(1)->and($sdk->requests())->toHaveCount(1)
        ->and($sdk->requests()[0]['body'])->toBe($this->xml)->and(ElectronicInvoiceAttempt::count())->toBe(1);
});

it('blocks an uncertain SDK result then reconciles exact original bytes without a second deposit', function (string $case) {
    ($this->finalize)();
    $bridge = ($this->bridge)(($this->prepareCii)());
    $transport = new EmissionTestTransport;
    $emission = new OutgoingEmission($bridge, $transport);
    $attempt = $emission->reserve($this->owner, $this->invoice, $bridge->reference);
    $record = ElectronicInvoice::sole();
    $response = $case === 'timeout' ? MockResponse::make()->throw(new RuntimeException('Simulated timeout SECRET')) : MockResponse::make([], 503);
    $sdk = new SdkTestEmissionTransport([CreateInvoiceRequest::class => $response]);
    $transport->onSend = fn ($document, $reference) => $sdk->send($document, $reference);
    expect($emission->dispatch($this->owner, $record, $attempt->id)->status)->toBe('unknown');
    $same = $emission->reserve($this->owner, $this->invoice, $bridge->reference, true);
    $emission->dispatch($this->owner, $record, $same->id);
    expect($same->id)->toBe($attempt->id)->and($transport->calls)->toBe(1);
    $reconciliationSdk = new SdkTestEmissionTransport([
        MockResponse::make(($this->remote)($attempt)), MockResponse::make($this->xml),
        MockResponse::make(['data' => [($this->event)(5, 'fr:205')], 'has_after' => false]),
    ]);
    $reconciliation = new OutgoingEmission($bridge, $reconciliationSdk);
    $resolved = $reconciliation->reconcile($this->owner, $record, $attempt->id, 900);
    expect($resolved->status)->toBe('accepted')->and($resolved->reconciliation['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and(array_column($resolved->history, 'status'))->toBe(['pending', 'pending', 'unknown', 'accepted']);
    $reconciliation->syncEvents($this->owner, $record);
    expect(array_column($reconciliationSdk->requests(), 'method'))->toBe(['GET', 'GET', 'GET'])
        ->and($record->fresh()->business_status)->toBe('fr:205')->and(ElectronicInvoiceAttempt::count())->toBe(1)
        ->and($this->invoice->fresh()->status)->toBe('non_payee');
})->with(['timeout', 'http503']);

it('rolls back incomplete finalization and never reaches CII preparation or reservation', function (string $field) {
    $this->company->update([$field => null]);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHasErrors();
    $invoice = $this->invoice->fresh();
    expect($invoice->isDraft())->toBeTrue()->and($invoice->party_snapshot)->toBeNull()
        ->and($invoice->emission_context)->toBeNull()->and($invoice->invoice_number)->toBeNull();
    $gateway = new OfflinePreparationGateway([]);
    expect(app(InvoiceCiiPreparation::class)->prepare($this->owner, $invoice, $gateway, 'e2e-client', 'production')['status'])->toBe('blocked')
        ->and($gateway->requests())->toBe([])->and(ElectronicInvoice::count())->toBe(0)
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
})->with(['country_code', 'billing_electronic_address', 'invoice_late_payment_penalties_text']);

it('refuses ineligible historical records without enriching or changing them', function (?int $version) {
    $legacy = new Invoice(['user_id' => $this->owner->id, 'client_id' => $this->client->id,
        'invoice_number' => 'LEGACY-E2E', 'document_status' => 'issued', 'date' => '2025-01-01',
        'status' => 'non_payee', 'amount_paid' => 0, 'subtotal_ht' => 100, 'total_tva' => 20, 'total_ttc' => 120]);
    if ($version !== null) {
        $legacy->party_snapshot = InvoicePartySnapshot::capture(['company_name' => 'Ancien vendeur'], ['company_name' => 'Ancien acheteur'], $version)->jsonSerialize();
    }
    $legacy->save();
    $before = $legacy->fresh()->getRawOriginal();
    $gateway = new OfflinePreparationGateway([]);
    expect(app(InvoiceCiiPreparation::class)->prepare($this->owner, $legacy, $gateway, 'e2e-client', 'production')['status'])->toBe('blocked')
        ->and($gateway->requests())->toBe([])->and($legacy->fresh()->getRawOriginal())->toBe($before)
        ->and(ElectronicInvoice::count())->toBe(0);
})->with([null, 1, 2]);

it('stops incomplete or ambiguous simulated routing and seller authority before conversion and deposit', function (string $case) {
    ($this->finalize)();
    $overrides = match ($case) {
        'partial' => [ListFrenchDirectoryEntriesRequest::class => MockResponse::make(['data' => [$this->directoryEntry], 'has_more' => true])],
        'ambiguous' => [ListFrenchDirectoryEntriesRequest::class => MockResponse::make(['data' => [$this->directoryEntry, $this->directoryEntry]])],
        'seller' => [GetSessionMeRequest::class => MockResponse::make(['client_id' => 'e2e-client', 'company_verification_status' => 'needs_review'])],
    };
    $result = ($this->prepareCii)($overrides);
    expect($result['status'])->toBe('blocked')->and($result['artifact'])->toBeNull()
        ->and(array_column($this->preparationGateway->requests(), 'class'))->not->toContain(ConvertInvoiceRequest::class)
        ->and(ElectronicInvoice::count())->toBe(0)->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
})->with(['partial', 'ambiguous', 'seller']);

it('keeps a real deposit request closed for both the simulation archive and the test-only alias', function () {
    ($this->finalize)();
    $result = ($this->prepareCii)();
    $bridge = ($this->bridge)($result);
    $before = app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $result['artifact']['reference']);
    app()->instance('env', 'production');
    expect(fn () => app(OutgoingEmission::class)->reserve($this->owner, $this->invoice, $result['artifact']['reference']))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(OutgoingEmission::class)->reserve($this->owner, $this->invoice, $bridge->reference))->toThrow(ValidationException::class);
    expect(fn () => $bridge->authorize($this->owner, $this->invoice, $bridge->reference))->toThrow(RuntimeException::class);
    expect(fn () => new SdkTestEmissionTransport([]))->toThrow(RuntimeException::class);
    expect(ElectronicInvoice::count())->toBe(0)->and(ElectronicInvoiceAttempt::count())->toBe(0)
        ->and(app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $result['artifact']['reference']))->toBe($before);
});

it('leaves the whole lifecycle blocked when reconciliation does not prove the same remote document', function (string $case) {
    ($this->finalize)();
    $bridge = ($this->bridge)(($this->prepareCii)());
    $transport = new EmissionTestTransport;
    $emission = new OutgoingEmission($bridge, $transport);
    $attempt = $emission->reserve($this->owner, $this->invoice, $bridge->reference);
    $record = ElectronicInvoice::sole();
    $transport->onSend = fn () => throw new RuntimeException('lost response');
    $emission->dispatch($this->owner, $record, $attempt->id);
    $remote = ($this->remote)($attempt);
    if ($case === 'correlation') {
        unset($remote['external_id']);
    }
    $readSdk = new SdkTestEmissionTransport([MockResponse::make($remote), MockResponse::make($case === 'bytes' ? 'other original' : $this->xml)]);
    $reconciliation = new OutgoingEmission($bridge, $readSdk);
    expect($reconciliation->reconcile($this->owner, $record, $attempt->id, 900)->status)->toBe('unknown');
    $same = $emission->reserve($this->owner, $this->invoice, $bridge->reference, true);
    $emission->dispatch($this->owner, $record, $same->id);
    expect($same->id)->toBe($attempt->id)->and($transport->calls)->toBe(1)
        ->and(ElectronicInvoiceAttempt::count())->toBe(1)->and($record->fresh()->provider_invoice_id)->toBeNull();
    foreach ($readSdk->requests() as $request) {
        expect($request['method'])->toBe('GET');
    }
})->with(['bytes', 'correlation']);

it('refuses reservation if the prepared private CII is corrupted between stages', function () {
    ($this->finalize)();
    $result = ($this->prepareCii)();
    $bridge = ($this->bridge)($result);
    $reference = $result['artifact']['reference'];
    $path = substr($reference, strlen('invoice-cii/')).'/document.xml';
    Storage::disk('invoice_cii')->put($path, 'corrupted document');
    $transport = new EmissionTestTransport;
    $emission = new OutgoingEmission($bridge, $transport);
    expect(fn () => $emission->reserve($this->owner, $this->invoice, $bridge->reference))->toThrow(RuntimeException::class);
    expect(ElectronicInvoice::count())->toBe(0)->and(ElectronicInvoiceAttempt::count())->toBe(0)->and($transport->calls)->toBe(0)
        ->and(Storage::disk('invoice_cii')->get($path))->toBe('corrupted document');
});
