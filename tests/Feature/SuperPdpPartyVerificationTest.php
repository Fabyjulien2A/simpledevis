<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\Verification\InvoicePartyVerification;
use App\Services\SuperPdp\Verification\ReadOnlyQualificationGateway;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use App\Services\SuperPdp\Verification\VerificationReadException;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    config(['services.superpdp.environment' => 'production', 'services.superpdp.client_id' => 'verification-client', 'services.superpdp.directory_base_url' => 'https://api.superpdp.tech', 'superpdp_verification.live_reads_enabled' => false]);
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Historical seller', 'siret' => '73282932000074', 'country_code' => 'FR', 'address' => '1 rue Fictive', 'postal_code' => '75001', 'city' => 'Paris',
        'vat_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat', 'invoice_recovery_indemnity_text' => 'PMT',
        'invoice_late_payment_penalties_text' => 'PMD', 'invoice_early_payment_discount_text' => 'AAB',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ]);
    $this->client = $this->owner->clients()->create(['company_name' => 'Historical buyer', 'client_type' => 'professional', 'country_code' => 'FR',
        'siret' => '55210055400013', 'address' => '2 rue Fictive', 'postal_code' => '69001', 'city' => 'Lyon',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554']]);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1', 'client_id' => $this->client->id,
        'date' => '2026-11-09', 'due_date' => '2026-12-09', 'items' => [['description' => 'Fictitious service', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->connection = $this->company->superPdpConnection()->create(['environment' => 'production', 'oauth_client_id' => 'verification-client', 'api_url' => config('services.superpdp.api_url'), 'superpdp_company_id' => 123, 'access_token' => 'ACCESS-TOKEN-NOT-LOGGED',
        'refresh_token' => 'REFRESH-TOKEN-NOT-USED', 'access_token_expires_at' => now()->addHours(2), 'status' => 'connected', 'reception_enabled' => true,
        'last_invoice_id' => 11, 'last_event_id' => 22]);
    $this->remoteCompany = ['id' => 123, 'created_at' => '2026-11-01T12:00:00+00:00', 'env' => 'production', 'number_scheme' => 'fr_siren',
        'number' => '732829320', 'country' => 'FR', 'formal_name' => 'Seller provider name'];
    $this->remoteSession = ['client_id' => 'verification-client', 'created_at' => '2026-11-01T12:00:00+00:00', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'];
    $this->buyerEntry = ['identifier' => '0225:552100554', 'is_active' => true, 'company' => ['number' => '552100554', 'formal_name' => 'Buyer provider name', 'address' => 'Annuaire', 'postcode' => '69001', 'city' => 'Lyon', 'country' => 'FR']];
    $this->ownEntry = ['id' => 45, 'created_at' => '2026-11-01T12:00:00+00:00', 'company' => $this->remoteCompany, 'identifier' => '0225:732829320',
        'directory' => 'ppf', 'status' => 'created', 'is_replyto' => false, 'effective_date' => '2026-01-01T00:00:00+00:00'];
    $this->seen = [];
    $this->responses = function (array $overrides = []) {
        $bodies = [ListFrenchDirectoryEntriesRequest::class => ['data' => [$this->buyerEntry]], GetSessionMeRequest::class => $this->remoteSession,
            GetCompanyMeRequest::class => $this->remoteCompany, ListDirectoryEntriesRequest::class => ['data' => [$this->ownEntry]]];
        $responses = [];
        foreach ($bodies as $class => $body) {
            $responses[$class] = $overrides[$class] ?? MockResponse::make($body)->throw(function ($pending) {
                $this->seen[] = ['class' => $pending->getRequest()::class, 'method' => $pending->getMethod()->value,
                    'url' => $pending->getUrl(), 'query' => $pending->query()->all(),
                    'authenticated' => $pending->headers()->get('Authorization') !== null];

                return null;
            });
        }

        return $responses;
    };
    $this->verify = fn (array $overrides = []) => app(InvoicePartyVerification::class)->verify($this->owner, $this->invoice, 'production', ($this->responses)($overrides));
});

afterEach(function () {
    Http::assertNothingSent();
    Config::allowStrayRequests();
});

it('wires the four documented GETs to historical parties and stores only sanitized private evidence', function () {
    $beforeInvoice = $this->invoice->getRawOriginal();
    $beforeConnection = $this->connection->fresh()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Verification SQL must be read only');
        }
    });
    $result = ($this->verify)();
    expect($result['status'])->toBe('passed')->and($result['emission_ready'])->toBeFalse()
        ->and($result['checks']['buyer_destination']['status'])->toBe('passed')
        ->and($result['checks']['seller_identity']['status'])->toBe('passed')
        ->and($result['checks']['seller_session']['status'])->toBe('passed')
        ->and($result['checks']['seller_bt34_receiving_publication']['status'])->toBe('passed')
        ->and($result['checks']['seller_emission_permission']['status'])->toBe('not_required')
        ->and($result['checks']['seller_bt34_sending_binding']['status'])->toBe('not_required');
    foreach ($result['checks'] as $check) {
        expect($check['verified'])->toBeFalse();
    }
    expect(array_column($this->seen, 'class'))->toBe([ListFrenchDirectoryEntriesRequest::class, GetSessionMeRequest::class, GetCompanyMeRequest::class, ListDirectoryEntriesRequest::class])
        ->and(array_column($this->seen, 'method'))->toBe(['GET', 'GET', 'GET', 'GET'])
        ->and(array_column($this->seen, 'authenticated'))->toBe([false, true, true, true])
        ->and($this->seen[0]['query'])->toBe(['number' => '552100554'])
        ->and($result['party_snapshot_sha256'])->toBe(hash('sha256', $this->invoice->getRawOriginal('party_snapshot')))
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($beforeConnection);
    $report = app(VerificationAuditStore::class)->read($this->owner, $this->invoice, $result['audit_reference']);
    unset($result['audit_reference']);
    expect($report)->toBe($result)->and(json_encode($report))->not->toContain('ACCESS-TOKEN-NOT-LOGGED', 'REFRESH-TOKEN-NOT-USED', 'Authorization')
        ->and(ElectronicInvoice::count())->toBe(0)->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class)
        ->and(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice)['eligible'])->toBeFalse();
});

it('keeps historical identities despite changes to current company and client profiles', function () {
    $this->client->update(['siret' => '73282932000074', 'country_code' => 'BE', 'company_name' => 'Current buyer']);
    $this->company->update(['siret' => '55210055400013', 'country_code' => 'BE', 'company_name' => 'Current seller']);
    $result = ($this->verify)();
    expect($result['checks']['buyer_destination']['status'])->toBe('passed')->and($result['checks']['seller_identity']['status'])->toBe('passed')
        ->and($result['buyer_siren'])->toBe('552100554')->and($this->seen[0]['query'])->toBe(['number' => '552100554']);
});

it('keeps the default network gate closed without any fallback or credential refresh', function () {
    $before = $this->connection->fresh()->getRawOriginal();
    $result = app(InvoicePartyVerification::class)->verify($this->owner, $this->invoice, 'production');
    expect($result['checks']['buyer_destination']['reason'])->toBe('live_reads_disabled')
        ->and($result['checks']['seller_identity']['reason'])->toBe('live_reads_disabled')
        ->and($this->connection->fresh()->getRawOriginal())->toBe($before)->and($this->seen)->toBe([]);
});

it('never promotes mock responses even when the future live-read flag is enabled', function () {
    config(['superpdp_verification.live_reads_enabled' => true]);
    $result = ($this->verify)();
    expect($result['checks']['buyer_destination']['source'])->toBe('simulated')->and($result['checks']['buyer_destination']['verified'])->toBeFalse()
        ->and($result['emission_ready'])->toBeFalse();
});

it('distinguishes refusal ambiguity and unavailable buyer routing', function (string $case, string $status) {
    $body = match ($case) {
        'absent' => ['data' => []], 'inactive' => ['data' => [array_replace($this->buyerEntry, ['is_active' => false])]],
        'multiple' => ['data' => [$this->buyerEntry, $this->buyerEntry]], 'partial' => ['data' => [$this->buyerEntry], 'has_more' => true],
        'suffix' => ['data' => [array_replace($this->buyerEntry, ['identifier' => '0225:552100554_ACHATS'])]],
        'foreign' => ['data' => [array_replace($this->buyerEntry, ['identifier' => '0225:732829320'])]],
        'malformed' => '{"data":{}}', 'duplicate_json' => '{"data":[],"data":[]}', 'unknown_pagination' => ['data' => [$this->buyerEntry], 'has_after' => true],
        default => ['error' => 'PRIVATE-ERROR-BODY'],
    };
    $http = match ($case) {
        'forbidden' => 403, 'unavailable' => 503, default => 200
    };
    $result = ($this->verify)([ListFrenchDirectoryEntriesRequest::class => MockResponse::make($body, $http)]);
    expect($result['checks']['buyer_destination']['status'])->toBe($status)
        ->and($result['checks']['buyer_destination']['verified'])->toBeFalse()->and(json_encode($result))->not->toContain('PRIVATE-ERROR-BODY');
})->with([
    ['absent', 'refused'], ['inactive', 'refused'], ['multiple', 'ambiguous'], ['partial', 'ambiguous'], ['suffix', 'ambiguous'],
    ['foreign', 'ambiguous'], ['malformed', 'ambiguous'], ['duplicate_json', 'ambiguous'], ['unknown_pagination', 'ambiguous'],
    ['forbidden', 'refused'], ['unavailable', 'unavailable'],
]);

it('classifies the documented seller session states and stops later GETs when needed', function (string $case, string $status) {
    match ($case) {
        'review' => $this->remoteSession['company_verification_status'] = 'needs_review', 'denied' => $this->remoteSession['company_verification_status'] = 'failed',
        'unknown' => $this->remoteSession['company_verification_status'] = 'future_state', 'client' => $this->remoteSession['client_id'] = 'other-client',
        'user_review' => $this->remoteSession['user_identity_verification_status'] = 'needs_review', 'user_denied' => $this->remoteSession['user_identity_verification_status'] = 'failed',
        'missing_user' => $this->remoteSession['user_identity_verification_status'] = null, 'date' => $this->remoteSession['created_at'] = 'not-a-date',
    };
    $result = ($this->verify)();
    expect($result['checks']['seller_session']['status'])->toBe($status)
        ->and(array_column($this->seen, 'class'))->not->toContain(GetCompanyMeRequest::class, ListDirectoryEntriesRequest::class);
})->with([['review', 'unavailable'], ['denied', 'refused'], ['unknown', 'ambiguous'], ['client', 'refused'], ['user_review', 'unavailable'], ['user_denied', 'refused'], ['missing_user', 'unavailable'], ['date', 'ambiguous']]);

it('compares the authenticated company with both the historical seller and the owned connection', function (string $field, mixed $value, string $status) {
    $this->remoteCompany[$field] = $value;
    $result = ($this->verify)();
    expect($result['checks']['seller_identity']['status'])->toBe($status)
        ->and(array_column($this->seen, 'class'))->not->toContain(ListDirectoryEntriesRequest::class);
})->with([['id', 999, 'refused'], ['number', '552100554', 'refused'], ['env', 'sandbox', 'refused'], ['number_scheme', 'sandbox', 'refused'],
    ['id', '123', 'ambiguous'], ['env', 'future', 'ambiguous'], ['created_at', null, 'ambiguous']]);

it('limits the meaning of owned directory entries to receiving publication', function (string $case, string $status) {
    $entry = $this->ownEntry;
    match ($case) {
        'pending' => $entry['status'] = 'pending', 'error' => $entry['status'] = 'error', 'replyto' => $entry['is_replyto'] = true,
        'missing_replyto' => $entry['is_replyto'] = null, 'foreign' => $entry['company']['id'] = 999,
        'future' => $entry['effective_date'] = now()->addYear()->format('Y-m-d'), 'invalid_date' => $entry['effective_date'] = 'not-a-date',
        'unknown' => $entry['status'] = 'future_status', default => null,
    };
    $body = match ($case) {
        'empty' => ['data' => []], 'multiple' => ['data' => [$entry, $entry]], 'partial' => ['data' => [$entry], 'has_more' => true],
        'object' => '{"data":{}}', default => ['data' => [$entry]]
    };
    $result = ($this->verify)([ListDirectoryEntriesRequest::class => MockResponse::make($body)]);
    expect($result['checks']['seller_bt34_receiving_publication']['status'])->toBe($status)
        ->and($result['checks']['seller_bt34_sending_binding']['status'])->toBe('not_required')
        ->and($result['checks']['seller_emission_permission']['status'])->toBe('not_required')->and($result['emission_ready'])->toBeFalse();
})->with([['pending', 'unavailable'], ['error', 'refused'], ['replyto', 'refused'], ['missing_replyto', 'unavailable'], ['foreign', 'refused'], ['future', 'unavailable'],
    ['invalid_date', 'ambiguous'], ['unknown', 'ambiguous'], ['empty', 'unavailable'], ['multiple', 'ambiguous'], ['partial', 'ambiguous'], ['object', 'ambiguous']]);

it('does not turn a receive mandate or unrecognized sending flags into sending authority', function () {
    $this->remoteCompany['mandates'] = [['direction' => 'in', 'verification_status' => 'verified']];
    $this->remoteSession['can_send'] = true;
    $this->ownEntry['sender_authorized'] = true;
    $result = ($this->verify)();
    expect($result['checks']['seller_bt34_receiving_publication']['status'])->toBe('passed')
        ->and($result['checks']['seller_bt34_sending_binding']['status'])->toBe('not_required')
        ->and($result['checks']['seller_emission_permission']['status'])->toBe('not_required');
});

it('does not refresh or reuse unavailable credentials while public routing can still be assessed', function (string $case) {
    if ($case === 'expired') {
        $this->connection->update(['access_token_expires_at' => now()->subMinute()]);
    } elseif ($case === 'missing') {
        $this->connection->delete();
    } else {
        DB::table('superpdp_connections')->where('id', $this->connection->id)->update(['access_token' => 'invalid ciphertext']);
    }
    $result = ($this->verify)();
    expect($result['checks']['buyer_destination']['status'])->toBe('passed')->and($result['checks']['seller_identity']['status'])->toBe('unavailable')
        ->and(array_column($this->seen, 'class'))->toBe([ListFrenchDirectoryEntriesRequest::class]);
})->with(['expired', 'missing', 'unreadable']);

it('sanitizes simulated transport failures and continues independent checks', function () {
    $result = ($this->verify)([ListFrenchDirectoryEntriesRequest::class => MockResponse::make()->throw(new RuntimeException('SECRET-TRANSPORT-TOKEN'))]);
    expect($result['checks']['buyer_destination']['status'])->toBe('unavailable')->and($result['checks']['seller_identity']['status'])->toBe('passed')
        ->and(json_encode($result))->not->toContain('SECRET-TRANSPORT-TOKEN');
});

it('protects private reports against cross-user access traversal and corruption', function () {
    $result = ($this->verify)();
    $store = app(VerificationAuditStore::class);
    $reference = $result['audit_reference'];
    $other = User::factory()->create();
    expect(fn () => $store->read($other, $this->invoice, $reference))->toThrow(HttpException::class);
    expect(fn () => app(InvoicePartyVerification::class)->verify($other, $this->invoice, 'production', ($this->responses)()))->toThrow(HttpException::class);
    expect(fn () => $store->read($this->owner, $this->invoice, $reference.'/../x'))->toThrow(RuntimeException::class);
    $file = str_replace('invoice-party-checks/v1/', 'party-checks/v1/', $reference);
    Storage::disk('invoice_cii')->put($file, '{}');
    expect(fn () => $store->read($this->owner, $this->invoice, $reference))->toThrow(RuntimeException::class);
});

it('rejects missing mocks without any network fallback', function () {
    $result = app(InvoicePartyVerification::class)->verify($this->owner, $this->invoice, 'production', []);
    expect($result['status'])->toBe('unavailable')->and($this->seen)->toBe([]);
});

it('rejects non-GET mock operations and untrusted credential origins', function () {
    expect(fn () => ReadOnlyQualificationGateway::forInvoice($this->owner, $this->invoice, 'production', [CreateInvoiceRequest::class => MockResponse::make([])]))->toThrow(VerificationReadException::class);
    config(['services.superpdp.directory_base_url' => 'https://example.invalid']);
    $result = ($this->verify)();
    expect($result['checks']['connection']['reason'])->toBe('official_origin_required')->and($this->seen)->toBe([]);
});

it('rejects an implicit environment without consulting the provider', function () {
    $result = app(InvoicePartyVerification::class)->verify($this->owner, $this->invoice, '', ($this->responses)());
    expect($result['checks']['connection']['reason'])->toBe('explicit_environment_required')->and($this->seen)->toBe([]);
});

it('does not consult the provider for historical invoices lacking emission evidence', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]);
    $result = ($this->verify)();
    expect($result['checks']['invoice_data']['reason'])->toBe('invoice_not_locally_eligible')->and($this->seen)->toBe([]);
});

it('invalidates verification evidence when the invoice changes during consultation', function () {
    $response = MockResponse::make(['data' => [$this->buyerEntry]])->throw(function () {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => null]);

        return null;
    });
    $result = ($this->verify)([ListFrenchDirectoryEntriesRequest::class => $response]);
    expect($result['status'])->toBe('ambiguous')->and($result['checks']['source_consistency']['status'])->toBe('ambiguous');
    foreach ($result['checks'] as $check) {
        expect($check['verified'])->toBeFalse();
    }
});

it('fails closed if the audit disk is public or served', function (string $setting, mixed $value) {
    config(['filesystems.disks.invoice_cii.'.$setting => $value]);
    expect(fn () => ($this->verify)())->toThrow(RuntimeException::class);
    expect(ElectronicInvoice::count())->toBe(0);
})->with([['visibility', 'public'], ['serve', true]]);

it('discards provider secrets even inside otherwise valid responses', function () {
    $this->remoteSession['access_token'] = 'PROVIDER-SECRET';
    $this->remoteCompany['private_note'] = 'PROVIDER-SECRET';
    $result = ($this->verify)();
    $stored = app(VerificationAuditStore::class)->read($this->owner, $this->invoice, $result['audit_reference']);
    expect(json_encode($stored))->not->toContain('PROVIDER-SECRET', 'private_note', 'access_token')
        ->and($stored['checks']['seller_session']['response_sha256'])->toMatch('/\A[a-f0-9]{64}\z/');
});

it('does not require a published BT34 receiving address to check emission identity', function () {
    $result = ($this->verify)([ListDirectoryEntriesRequest::class => MockResponse::make(['error' => 'unavailable'], 503)]);
    expect($result['status'])->toBe('passed')->and($result['emission_ready'])->toBeFalse()
        ->and($result['checks']['seller_bt34_receiving_publication']['status'])->toBe('unavailable')
        ->and($result['checks']['seller_bt34_sending_binding']['status'])->toBe('not_required')
        ->and($result['checks']['seller_emission_permission']['status'])->toBe('not_required');
});

it('does not send credentials from another configured environment', function () {
    config(['services.superpdp.environment' => 'sandbox']);
    $result = ($this->verify)();
    expect($result['checks']['seller_identity']['reason'])->toBe('oauth_environment_or_client_mismatch');
    expect(array_column($this->seen, 'class'))->toBe([ListFrenchDirectoryEntriesRequest::class]);
});
