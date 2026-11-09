<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\OfflinePreparationGateway;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockResponse;
use Tests\Support\PreparedCiiFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Atelier historique', 'siret' => '73282932000074', 'country_code' => 'FR',
        'address' => '12 rue Historique', 'postal_code' => '75012', 'city' => 'Paris',
        'vat_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat',
        'invoice_recovery_indemnity_text' => 'PMT historique', 'invoice_late_payment_penalties_text' => 'PMD historique',
        'invoice_early_payment_discount_text' => 'AAB historique',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ]);
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Acheteur historique', 'client_type' => 'professional', 'siret' => '55210055400013',
        'country_code' => 'FR', 'address' => '3 rue Historique', 'postal_code' => '69002', 'city' => 'Lyon',
        'vat_number' => 'FR00552100554', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ]);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'emission_profile' => InvoiceEmissionContext::PROFILE, 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-09', 'due_date' => '2026-12-09',
        'items' => [['description' => 'Prestation historique', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $this->directoryCompany = ['number' => '552100554', 'formal_name' => 'Acheteur annuaire', 'address' => 'Annuaire', 'postcode' => '69002', 'city' => 'Lyon', 'country' => 'FR'];
    $this->entry = ['company' => $this->directoryCompany, 'identifier' => '0225:552100554', 'is_active' => true];
    $this->directory = ['data' => [$this->entry]];
    $this->remoteCompany = ['id' => 123, 'number_scheme' => 'fr_siren', 'number' => '732829320', 'country' => 'FR', 'env' => 'production'];
    $this->session = ['client_id' => 'client-offline', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'];
    $this->gateway = function (array $overrides = []): OfflinePreparationGateway {
        return new OfflinePreparationGateway($overrides + [
            ListFrenchDirectoryEntriesRequest::class => MockResponse::make($this->directory),
            GetCompanyMeRequest::class => MockResponse::make($this->remoteCompany),
            GetSessionMeRequest::class => MockResponse::make($this->session),
            ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']),
            CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
        ]);
    };
    $this->prepare = fn ($gateway) => app(InvoiceCiiPreparation::class)->prepare($this->owner, $this->invoice, $gateway, 'client-offline', 'production');
});

afterEach(function () {
    Http::assertNothingSent();
    Config::allowStrayRequests();
});

it('prepares an exact private CII via the SDK without SQL writes or emission and keeps all verification simulated', function () {
    $gateway = ($this->gateway)();
    $before = $this->invoice->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Toute écriture SQL est interdite.');
        }
    });
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('prepared_simulation')->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['routing']['status'])->toBe('not_verified')->and($result['seller']['status'])->toBe('not_verified')
        ->and($result['artifact']['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $archive = app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $result['artifact']['reference']);
    expect($archive['xml'])->toBe($this->xml)->and($archive['manifest']['ready_for_emission'])->toBeFalse()
        ->and($archive['manifest']['routing']['buyer_siren'])->toBe('552100554');
    $requests = $gateway->requests();
    expect(array_column($requests, 'class'))->toBe([ListFrenchDirectoryEntriesRequest::class, GetCompanyMeRequest::class, GetSessionMeRequest::class, ConvertInvoiceRequest::class, CreateValidationReportRequest::class])
        ->and($requests[3]['query'])->toBe(['from' => 'en16931', 'to' => 'cii'])
        ->and($requests[3]['content_type'])->toBe('application/json')->and($requests[3]['accept'])->toBe('application/xml')
        ->and($requests[4]['content_type'])->toStartWith('multipart/form-data; boundary=')
        ->and(json_decode($requests[3]['body'], true))->toBe($this->payload);
    foreach ($requests as $request) {
        expect($request['url'])->not->toBe('https://api.superpdp.tech/v1.beta/invoices');
    }
    expect(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice)['eligible'])->toBeFalse();
});

it('uses the historical SIREN and BT49 despite changes to current profiles and stale model relations', function () {
    $this->invoice->load('client', 'user.company');
    $this->client->update(['company_name' => 'Autre client', 'siret' => '73282932000074', 'billing_electronic_address' => ['scheme' => 'EM', 'value' => 'current@example.invalid']]);
    $this->company->update(['siret' => '55210055400013', 'billing_electronic_address' => null]);
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('prepared_simulation')->and($gateway->requests()[0]['query'])->toBe(['number' => '552100554'])
        ->and($result['routing']['party_snapshot_sha256'])->toBe(hash('sha256', $this->invoice->getRawOriginal('party_snapshot')));
});

it('refuses absent inactive multiple partial foreign and unsupported destinations without conversion', function (string $case, string $reason) {
    match ($case) {
        'empty' => $this->directory['data'] = [],
        'inactive' => $this->directory['data'][0]['is_active'] = false,
        'multiple' => $this->directory['data'][] = $this->entry,
        'partial' => $this->directory['has_more'] = true,
        'composed' => $this->directory['data'][0]['identifier'] = '0225:552100554_ACHATS',
        'other_scheme' => $this->directory['data'][0]['identifier'] = '0088:123456',
        'foreign' => $this->directory['data'][0]['company']['number'] = '732829320',
        'mismatch' => $this->directory['data'][0]['identifier'] = '0225:732829320',
        'technical' => $this->directory['data'][0]['identifier'] = '0225:552100554_replyto',
        'country' => $this->directory['data'][0]['company']['country'] = 'BE',
    };
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['artifact'])->toBeNull()
        ->and($result['routing']['qualified_for_simulation'] ?? false)->toBeFalse()
        ->and($result['routing']['reason'] ?? $result['reason'])->toBe($reason)
        ->and(array_column($gateway->requests(), 'class'))->not->toContain(ConvertInvoiceRequest::class)
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
})->with([
    ['empty', 'no_active_destination'], ['inactive', 'no_active_destination'], ['multiple', 'ambiguous_destinations'],
    ['partial', 'partial_response'], ['composed', 'unsupported_active_address'], ['other_scheme', 'unsupported_active_address'],
    ['foreign', 'qualification_failed'], ['mismatch', 'invalid_or_foreign_entry'], ['technical', 'invalid_or_foreign_entry'], ['country', 'directory_country_mismatch'],
]);

it('never treats a receive mandate BT34 or KYB alone as emission permission', function (string $case, string $reason) {
    match ($case) {
        'identity' => $this->remoteCompany['number'] = '552100554',
        'scheme' => $this->remoteCompany['number_scheme'] = 'sandbox',
        'env' => $this->remoteCompany['env'] = 'sandbox',
        'client' => $this->session['client_id'] = 'other-app',
        'kyb' => $this->session['company_verification_status'] = 'needs_review',
        'failed' => $this->session['company_verification_status'] = 'failed',
        'user' => $this->session['user_identity_verification_status'] = 'not_verified',
        'missing_user' => $this->session['user_identity_verification_status'] = null,
    };
    $this->remoteCompany['mandates'] = [['direction' => 'in', 'verification_status' => 'verified']];
    $result = ($this->prepare)(($this->gateway)());
    expect($result['status'])->toBe('blocked')->and($result['seller']['status'])->toBe('not_verified')
        ->and($result['seller']['reasons'])->toContain($reason)->and($result['artifact'])->toBeNull();
})->with([
    ['identity', 'seller_identity_mismatch_or_delegation_unsupported'], ['scheme', 'seller_identity_mismatch_or_delegation_unsupported'],
    ['env', 'environment_mismatch'], ['client', 'oauth_client_mismatch'],
    ['kyb', 'company_authority_not_verified'], ['failed', 'company_authority_not_verified'],
    ['user', 'user_identity_not_verified'], ['missing_user', 'user_identity_not_verified'],
]);

it('rejects HTTP and malformed responses without retaining a document or leaking the body', function (string $request, int $status, mixed $body) {
    $gateway = ($this->gateway)([$request => MockResponse::make($body, $status)]);
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['artifact'])->toBeNull()
        ->and(json_encode($result))->not->toContain('secret-body-token')
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
})->with([
    [GetCompanyMeRequest::class, 403, ['error' => 'secret-body-token']],
    [GetSessionMeRequest::class, 200, 'not JSON secret-body-token'],
    [ConvertInvoiceRequest::class, 500, 'secret-body-token'],
    [CreateValidationReportRequest::class, 200, ['data' => []]],
    [CreateValidationReportRequest::class, 503, ['error' => 'secret-body-token']],
    [ListFrenchDirectoryEntriesRequest::class, 200, ['data' => [], 'has_after' => true]],
]);

it('denies other accounts and blocks drafts and historical invoices before any SDK request', function () {
    $other = User::factory()->create();
    $gateway = ($this->gateway)();
    expect(fn () => app(InvoiceCiiPreparation::class)->prepare($other, $this->invoice, $gateway, 'client-offline', 'production'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($gateway->requests())->toBe([]);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null, 'emission_context' => null, 'emission_profile' => null]);
    $before = $this->invoice->fresh()->getRawOriginal();
    $result = ($this->prepare)($gateway);
    expect($result['reason'])->toBe('local_data_incomplete')->and($gateway->requests())->toBe([])
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
});

it('preserves current payments and refuses conversion once the invoice has a settlement', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid' => '1.00']);
    $gateway = ($this->gateway)();
    expect(($this->prepare)($gateway)['reason'])->toBe('local_data_incomplete')->and($gateway->requests())->toBe([]);
});

it('protects archived files against owner traversal corruption and repeated preparation', function () {
    $this->freezeTime();
    $result = ($this->prepare)(($this->gateway)());
    $again = ($this->prepare)(($this->gateway)());
    expect($again['artifact'])->toBe($result['artifact'])->and(count(Storage::disk('invoice_cii')->allFiles()))->toBe(2);
    $archive = app(PreparedCiiArchive::class);
    $ref = $result['artifact']['reference'];
    expect(fn () => $archive->read(User::factory()->create(), $this->invoice, $ref))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $archive->read($this->owner, $this->invoice, $ref.'/../../x'))->toThrow(RuntimeException::class);
    $relative = substr($ref, strlen('invoice-cii/'));
    Storage::disk('invoice_cii')->put($relative.'/document.xml', 'corrupted');
    expect(fn () => $archive->read($this->owner, $this->invoice, $ref))->toThrow(RuntimeException::class);
    expect(($this->prepare)(($this->gateway)())['reason'])->toBe('private_storage_failed');
    expect(Storage::disk('invoice_cii')->get($relative.'/document.xml'))->toBe('corrupted');
});

it('handles storage errors without altering the invoice or publishing a partial bundle', function () {
    $before = $this->invoice->getRawOriginal();
    app()->instance(PreparedCiiArchive::class, new class extends PreparedCiiArchive {
        protected function write(string $path, string $bytes): void
        {
            parent::write($path, $bytes);
            throw new RuntimeException('Simulation disque plein');
        }
    });
    $result = ($this->prepare)(($this->gateway)());
    expect($result['reason'])->toBe('private_storage_failed')->and($result['artifact'])->toBeNull()
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([])->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
});

it('cannot construct a gateway with a send operation a real connector or a recordable fixture', function () {
    expect(fn () => new OfflinePreparationGateway([\Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest::class => MockResponse::make([])]))->toThrow(RuntimeException::class);
    expect(fn () => new OfflinePreparationGateway([ConvertInvoiceRequest::class => fn () => MockResponse::make([])]))->toThrow(RuntimeException::class);
    expect(fn () => new OfflinePreparationGateway([]))->not->toThrow(RuntimeException::class);
});

it('accepts inactive alternatives and explicit completeness without selecting an arbitrary active entry', function () {
    $this->directory['has_more'] = false;
    $inactive = $this->entry;
    $inactive['is_active'] = false;
    $inactive['identifier'] = '0225:552100554_ANCIEN';
    $this->directory['data'][] = $inactive;
    $result = ($this->prepare)(($this->gateway)());
    expect($result['status'])->toBe('prepared_simulation')->and($result['routing']['completeness'])->toBe('explicit_complete')
        ->and($result['routing']['destination']['identifier'])->toBe('0225:552100554');
});

it('does not invent a route for a declared EM or suffix address even when the simple SIREN is active', function (array $address) {
    // Simulation d'une autre facture finalisée avec une déclaration différente, jamais une réécriture métier.
    $context = $this->invoice->emission_context;
    $context['buyer_electronic_address'] = $address;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => json_encode($context)]);
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['routing']['reason'])->toBe('declared_bt49_not_matched')
        ->and(array_column($gateway->requests(), 'class'))->not->toContain(ConvertInvoiceRequest::class);
})->with([
    [['scheme' => 'EM', 'value' => 'billing@example.invalid']],
    [['scheme' => '0225', 'value' => '552100554_ACHATS']],
]);

it('has no network fallback when a mock response is missing', function () {
    $gateway = new OfflinePreparationGateway([
        ListFrenchDirectoryEntriesRequest::class => MockResponse::make($this->directory),
        GetCompanyMeRequest::class => MockResponse::make($this->remoteCompany),
        GetSessionMeRequest::class => MockResponse::make($this->session),
    ]);
    expect(($this->prepare)($gateway)['reason'])->toBe('conversion_failed')
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});

it('rejects an invoice changed during preparation without removing its new payment state', function () {
    $real = app(\App\Services\InvoiceExportDataBuilder::class);
    $mock = $this->createMock(\App\Services\InvoiceExportDataBuilder::class);
    $reads = 0;
    $mock->method('buildElectronic')->willReturnCallback(function ($invoice) use ($real, &$reads) {
        if (++$reads === 3) {
            DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => '10.00']);
        }

        return $real->buildElectronic($invoice);
    });
    app()->instance(\App\Services\InvoiceExportDataBuilder::class, $mock);
    $result = ($this->prepare)(($this->gateway)());
    expect($result['reason'])->toBe('invoice_changed_during_preparation')->and($result['artifact'])->toBeNull()
        ->and($this->invoice->fresh()->amount_paid)->toBe('10.00')->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});

it('rejects a CII for another document before requesting validation', function () {
    $xml = str_replace($this->payload['number'], 'ANOTHER-INVOICE', $this->xml);
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($xml, 200, ['Content-Type' => 'application/xml'])]);
    $result = ($this->prepare)($gateway);
    expect($result['reason'])->toBe('conversion_failed')->and($result['artifact'])->toBeNull()
        ->and(array_column($gateway->requests(), 'class'))->not->toContain(CreateValidationReportRequest::class);
});

it('refuses even one supported address when another active destination is unsupported', function () {
    $other = $this->entry;
    $other['identifier'] = '0225:552100554_ACHATS';
    $this->directory['data'][] = $other;
    $result = ($this->prepare)(($this->gateway)());
    expect($result['status'])->toBe('blocked')->and($result['routing']['reason'])->toBe('unsupported_active_address');
});

it('blocks drafts before SDK operations and does not reuse a proof from another invoice', function () {
    $result = ($this->prepare)(($this->gateway)());
    $otherInvoice = $this->invoice->replicate();
    $otherInvoice->invoice_number = 'OTHER-INVOICE';
    $otherInvoice->save();
    expect(fn () => app(PreparedCiiArchive::class)->read($this->owner, $otherInvoice, $result['artifact']['reference']))->toThrow(RuntimeException::class);
    DB::table('invoices')->where('id', $otherInvoice->id)->update(['document_status' => 'draft']);
    $gateway = ($this->gateway)();
    $result = app(InvoiceCiiPreparation::class)->prepare($this->owner, $otherInvoice, $gateway, 'client-offline', 'production');
    expect($result['reason'])->toBe('local_data_incomplete')->and($gateway->requests())->toBe([]);
});

it('rejects an exposed storage location even if the disk is labelled private', function () {
    Storage::fake('public');
    config(['filesystems.disks.invoice_cii.root' => Storage::disk('public')->path('')]);
    Storage::forgetDisk('invoice_cii');
    $result = ($this->prepare)(($this->gateway)());
    expect($result['reason'])->toBe('private_storage_failed')->and($result['artifact'])->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects an altered manifest and leaves it untouched rather than silently repairing it', function () {
    $this->freezeTime();
    $result = ($this->prepare)(($this->gateway)());
    $ref = $result['artifact']['reference'];
    $relative = substr($ref, strlen('invoice-cii/'));
    Storage::disk('invoice_cii')->put($relative.'/manifest.json', '{}');
    expect(fn () => app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $ref))->toThrow(RuntimeException::class);
    expect(($this->prepare)(($this->gateway)())['reason'])->toBe('private_storage_failed')
        ->and(Storage::disk('invoice_cii')->get($relative.'/manifest.json'))->toBe('{}');
});
