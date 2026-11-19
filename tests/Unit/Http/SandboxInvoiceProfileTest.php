<?php

use App\Models\Company;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\Emission\InvoiceEmissionReadiness;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxInvoiceEvidence;
use App\Services\SuperPdp\SandboxInvoiceProfile;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use GuzzleHttp\Psr7\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Repositories\ArrayStore;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SandboxOwnInvoiceCii;
use Tests\TestCase;

uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    $this->setUpIsolatedEmissionDatabase();
    $this->withoutVite();
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $this->sdk = MockClient::global([]);
    $this->expectedSdkCalls = 0;
    Storage::fake('invoice_cii');
    Storage::fake('invoice_logos');
    config(['session.driver' => 'array', 'cache.default' => 'array', 'app.debug' => false,
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'isolated-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'superpdp_preparation.sandbox_client_id' => 'isolated-client']);
    $this->owner = User::factory()->create(['id' => 3]);
    $this->buyer = User::factory()->create(['id' => 4]);
    $this->actingAs($this->owner);
    foreach ([3 => ['Burger Queen', 18172, '000000002', 1], 4 => ['Tricatel', 18171, '000000001', 2]] as $id => [$name, $provider, $number, $connectionId]) {
        $company = (new Company)->forceFill(['id' => $id, 'user_id' => $id, 'company_name' => $name, 'siret' => null,
            'country_code' => 'FR', 'address' => 'Adresse de test', 'postal_code' => '75001', 'city' => 'Paris', 'vat_regime' => 'subject_to_vat']);
        $company->save();
        $company->superPdpConnection()->create(['id' => $connectionId, 'environment' => 'sandbox',
            'oauth_client_id' => 'isolated-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'superpdp_company_id' => $provider, 'directory_identifier' => $number, 'status' => 'connected',
            'access_token' => 'OFFLINE-ACCESS-'.$id, 'refresh_token' => 'OFFLINE-REFRESH-'.$id, 'access_token_expires_at' => now()->addHour()]);
    }
    $this->client = $this->owner->clients()->create(['client_type' => 'professional', 'company_name' => 'Tricatel',
        'country_code' => 'FR', 'siret' => null, 'address' => 'Adresse client de test', 'postal_code' => '69001', 'city' => 'Lyon']);
    $memory = DB::connection()->getPdo();
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'database.default' => SandboxDatabaseIsolation::CONNECTION,
        'database.connections' => [SandboxDatabaseIsolation::CONNECTION => ['driver' => 'mysql', 'host' => 'forbidden.invalid',
            'database' => SandboxDatabaseIsolation::DATABASE, 'username' => SandboxDatabaseIsolation::DATABASE]]]);
    DB::clearResolvedInstance('db');
    $this->app->forgetInstance('db');
    SandboxDatabaseIsolation::install($this->app);
    $manager = app('db');
    $manager->extend('mysql', fn ($configuration) => new SQLiteConnection($memory, $configuration['database'], '',
        $configuration + ['name' => SandboxDatabaseIsolation::CONNECTION]));
    Model::setConnectionResolver($manager);
    foreach ([$this->owner, $this->buyer, $this->client] as $model) {
        $model->setConnection(SandboxDatabaseIsolation::CONNECTION);
    }
    expect($memory->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
    $this->csrf = str_repeat('s', 40);
    $this->withSession(['_token' => $this->csrf]);
    $this->body = ['_token' => $this->csrf, 'action' => 'issue', 'emission_profile' => SandboxInvoiceProfile::PROFILE,
        'client_id' => $this->client->id, 'date' => '2026-11-15', 'due_date' => '2026-12-15', 'french_business_process_type' => 'S1',
        'items' => [['description' => 'Notre prestation A', 'quantity' => '2.5', 'unit_price_ht' => '40', 'tva_rate' => '20', 'tax_treatment' => 'taxable', 'unit_code' => 'C62'],
            ['description' => 'Notre prestation B', 'quantity' => '4', 'unit_price_ht' => '25', 'tva_rate' => '10', 'tax_treatment' => 'taxable', 'unit_code' => 'C62']]];
    $this->receipt = function ($path, $data, $source = 'live', $token = 'OFFLINE-ACCESS-3') {
        // Synthetic receipts ONLY, never real live evidence or an actual SDK send.
        $url = 'https://api.superpdp.tech/v1.beta/'.$path;
        $pending = $this->createMock(PendingRequest::class);
        $pending->method('getMethod')->willReturn(Method::GET);
        $pending->method('getUrl')->willReturn($url);
        $pending->method('query')->willReturn(new ArrayStore);
        $response = new Response(new GuzzleHttp\Psr7\Response(200, [], json_encode($data)), $pending,
            new Request('GET', $url, ['Authorization' => 'Bearer '.$token]));
        if ($source === 'simulated') {
            $response->setMocked(true);
        }

        return $response;
    };
    $this->evidence = function ($invoice, $source = 'live', $buyerOverride = null) {
        $seller = ['id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002', 'env' => 'sandbox', 'country' => 'FR'];
        $buyer = ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001', 'env' => 'sandbox', 'country' => 'FR', 'formal_name' => 'Tricatel'];
        $entry = ['id' => 9, 'company' => $buyer, 'identifier' => '0225:315143296_18171', 'directory' => 'peppol', 'status' => 'created', 'is_replyto' => false];
        $buyerBody = $buyerOverride ?? ['data' => [$entry]];
        $proof = fn ($path, $data) => SandboxSellerEvidence::fromResponse(($this->receipt)($path, $data, $source));

        return app(SandboxInvoiceEvidence::class)->record($this->owner, $invoice,
            $proof('companies/me', $seller), $proof('oauth2_sessions/me', ['client_id' => 'isolated-client',
                'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified']),
            $proof('directory_entries', ['data' => [['id' => 8, 'company' => $seller, 'identifier' => '0225:315143296_18172',
                'directory' => 'peppol', 'status' => 'created', 'is_replyto' => false]]]),
            SandboxSellerEvidence::fromResponse(($this->receipt)('directory_entries', $buyerBody, $source, 'OFFLINE-ACCESS-4')));
    };
    $this->publishCii = function ($invoice, $manifest, $xml) {
        $bytes = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $bytes);
        $relative = 'v1/validated/3/'.$invoice->id.'/'.$hash;
        Storage::disk('invoice_cii')->put($relative.'/document.xml', $xml);
        Storage::disk('invoice_cii')->put($relative.'/manifest.json', $bytes);

        return ['reference' => 'invoice-cii/'.$relative, 'manifest_sha256' => $hash, 'cii_sha256' => hash('sha256', $xml)];
    };
    $this->validated = function ($invoice, $report) {
        // Private synthetic fixture ONLY. No real validation/provenance is fabricated by application code.
        $registry = app(CiiPreparationRegistry::class);
        $slot = $registry->reserve($this->owner, $invoice, 'api');
        $binding = $slot['claim']->binding();
        $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
        $xml = SandboxOwnInvoiceCii::xml($payload);
        $m = array_intersect_key($binding, array_flip(['user_id', 'invoice_id', 'source_sha256', 'payload_sha256',
            'party_snapshot_sha256', 'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id'])) + [
                'schema_version' => 1, 'mode' => 'validated_private_document', 'provenance' => 'live', 'ready_for_emission' => true,
                'emission_profile' => SandboxInvoiceProfile::PROFILE, 'prepared_at' => now()->toIso8601String(),
                'cii_sha256' => hash('sha256', $xml), 'verification_reference' => $report['audit_reference'],
                'validation' => ['status' => 'passed', 'source' => 'live', 'observed_at' => now()->toIso8601String(),
                    'cii_sha256' => hash('sha256', $xml), 'report' => PreparedCiiFixture::report($xml)],
            ];
        $artifact = ($this->publishCii)($invoice, $m, $xml);
        $registry->complete($this->owner, $invoice, $slot['claim'], $artifact);
        config(['superpdp_emission.http_sandbox_enabled' => true, 'superpdp_emission.sandbox_enabled' => true,
            'superpdp_emission.sandbox_client_id' => 'isolated-client', 'superpdp_emission.authorization' => [
                'approved_by' => 'ISOLATED-FIXTURE', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
                'user_id' => 3, 'invoice_id' => $invoice->id, 'document_reference' => $artifact['reference'],
                'cii_sha256' => $artifact['cii_sha256'], 'provider_company_id' => 18172, 'environment' => 'sandbox',
                'oauth_client_id' => 'isolated-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            ]]);

        return $artifact;
    };
    $this->revalidated = function ($invoice, $case = 'valid') {
        $original = ($this->validated)($invoice, ($this->evidence)($invoice));
        $archive = app(PreparedCiiArchive::class)->readValidated($this->owner, $invoice, $original['reference']);
        $parent = $archive['manifest'];
        $xml = $archive['xml'];
        $this->travel(16)->minutes();
        $proof = ($this->evidence)($invoice);
        unset($proof['audit_reference']);
        $proof += ['cii_archive_reference' => $original['reference'], 'cii_sha256' => $parent['cii_sha256'],
            'payload_sha256' => $parent['payload_sha256']];
        $proof['checks']['seller_directory'] = $proof['checks']['seller_identity'];
        if ($case === 'expiring directory') {
            $proof['checks']['seller_directory']['observed_at'] = now()->subMinutes(14)->toIso8601String();
        }
        foreach ([
            'seller_identity' => [1, 'companies/me'],
            'seller_session' => [1, 'oauth2_sessions/me'],
            'seller_directory' => [1, 'directory_entries'],
            'buyer_destination' => [2, 'directory_entries'],
        ] as $key => [$connectionId, $path]) {
            $buyer = $connectionId === 2;
            $proof['sdk_provenance'][$key] = ['method' => 'GET', 'endpoint' => SandboxEmissionAuthorization::API_URL.'/'.$path,
                'http_status' => 200, 'connection_id' => $connectionId, 'provider_company_id' => $buyer ? 18171 : 18172,
                'credential_sha256' => hash('sha256', $buyer ? 'OFFLINE-ACCESS-4' : 'OFFLINE-ACCESS-3')] + $proof['checks'][$key];
        }
        match ($case) {
            'wrong proof fingerprint' => $proof['cii_sha256'] = str_repeat('a', 64),
            'wrong buyer identity' => $proof['sdk_provenance']['buyer_destination']['provider_company_id'] = 18172,
            'wrong seller credential' => $proof['sdk_provenance']['seller_directory']['credential_sha256'] = str_repeat('a', 64),
            'wrong proof source' => $proof['sdk_provenance']['seller_directory']['source'] = 'simulated',
            'expired directory' => $proof['checks']['seller_directory']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
            default => null,
        };
        $reference = app(VerificationAuditStore::class)->store($this->owner, $invoice, $proof);
        $manifest = array_replace($parent, [
            'revalidated_from' => $original['reference'], 'original_prepared_at' => $parent['prepared_at'],
            'prepared_at' => now()->toIso8601String(), 'verification_reference' => $reference,
        ]);
        $manifest['validation']['observed_at'] = $manifest['prepared_at'];
        match ($case) {
            'foreign parent owner' => $manifest['revalidated_from'] = str_replace('/validated/3/', '/validated/4/', $original['reference']),
            'foreign parent invoice' => $manifest['revalidated_from'] = str_replace('/validated/3/'.$invoice->id.'/', '/validated/3/999/', $original['reference']),
            'wrong parent fingerprint' => $manifest['revalidated_from'] = ($this->publishCii)($invoice,
                array_replace($parent, ['payload_sha256' => str_repeat('a', 64)]), $archive['xml'])['reference'],
            'reused report' => $manifest['verification_reference'] = $parent['verification_reference'],
            'expired validation' => $manifest['validation']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
            'simulated validation' => $manifest['validation']['source'] = 'simulated',
            default => null,
        };
        if ($case === 'different XML bytes') {
            $xml .= "\n";
            $manifest['cii_sha256'] = $manifest['validation']['cii_sha256'] = hash('sha256', $xml);
            $manifest['validation']['report'] = PreparedCiiFixture::report($xml);
        }
        $artifact = ($this->publishCii)($invoice, $manifest, $xml);
        config(['superpdp_emission.authorization' => array_replace(config('superpdp_emission.authorization'), [
            'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
            'document_reference' => $artifact['reference'], 'cii_sha256' => $artifact['cii_sha256'],
        ])]);

        return [$original, $artifact];
    };
});

afterEach(function () {
    Http::assertNothingSent();
    $this->sdk->assertSentCount($this->expectedSdkCalls);
    MockClient::destroyGlobal();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    $this->travelBack();
});

it('preserves the sandbox documentary buyer diagnostic without inventing replyto values', function ($classification, $expectedStatus, $expectedReason) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $entry = ['id' => 9, 'company' => ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001',
        'env' => 'sandbox', 'country' => 'FR', 'formal_name' => 'Tricatel'],
        'identifier' => '0225:315143296_18171', 'directory' => 'peppol', 'status' => 'created',
        'access_token' => 'REMOTE-SECRET', 'refresh_token' => 'REMOTE-REFRESH'];
    if ($classification !== 'absent') {
        $entry['is_replyto'] = $classification;
    }
    $report = ($this->evidence)($invoice, 'live', ['data' => [$entry]]);
    $private = app(VerificationAuditStore::class)->read($this->owner, $invoice, $report['audit_reference']);
    $assessment = $private['checks']['buyer_destination']['assessment'];
    expect($assessment['status'])->toBe($expectedStatus)->and($assessment['reason'])->toBe($expectedReason)
        ->and($private['checks']['buyer_destination']['status'])->toBe($expectedStatus === 'matched' ? 'passed' : 'refused')
        ->and($private['status'])->toBe($expectedStatus === 'matched' ? 'passed' : 'refused')
        ->and(json_encode($private))->not->toContain('REMOTE-SECRET', 'REMOTE-REFRESH', 'OFFLINE-ACCESS', 'OFFLINE-REFRESH')
        ->and($invoice->fresh()->getRawOriginal())->toBe($before);
    if ($classification !== false && $classification !== true) {
        expect($assessment['classification_diagnostic']['field_present'])->toBe($classification !== 'absent')
            ->and($assessment['classification_diagnostic']['value_type'])->toBe($classification === 'absent' ? 'absent' : get_debug_type($classification))
            ->and($assessment['classification_diagnostic'])->not->toHaveKey('value');
    } else {
        expect($assessment)->not->toHaveKey('classification_diagnostic');
    }
})->with([
    'absent' => ['absent', 'matched', 'sandbox_documentary_destination'],
    'null' => [null, 'unavailable', 'replyto_classification_missing'],
    'sensitive malformed classification' => ['REMOTE-SECRET', 'unavailable', 'replyto_classification_missing'],
    'replyto' => [true, 'refused', 'technical_replyto_address'],
    'qualified' => [false, 'matched', 'sandbox_documentary_destination'],
]);

it('requires the Tricatel list credentials and sandbox runtime for documentary buyer evidence', function ($case) {
    $connection = Company::findOrFail(4)->superPdpConnection()->sole();
    $path = $case === 'endpoint' ? 'companies/me' : 'directory_entries';
    $proof = SandboxSellerEvidence::fromResponse(($this->receipt)($path, ['data' => []], 'live',
        $case === 'seller token' ? 'OFFLINE-ACCESS-3' : 'OFFLINE-ACCESS-4'));
    if ($case === 'connection') {
        $connection->id = 1;
    }
    if ($case === 'production') {
        $this->app->instance('env', 'production');
        expect(fn () => app(SandboxInvoiceEvidence::class)->assessBuyer($proof, $connection))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        return;
    }
    $result = app(SandboxInvoiceEvidence::class)->assessBuyer($proof, $connection);
    expect($result['status'])->not->toBe('matched')->and($result)->not->toHaveKey('qualification_documentaire_sandbox');
})->with(['seller token', 'endpoint', 'connection', 'production']);

it('records documentary qualification only while leaving CII and emission authorization closed', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $configuration = config('superpdp_preparation');
    $emission = config('superpdp_emission');
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Documentary qualification must not write SQL.');
        }
    });
    $entry = ['id' => 59580, 'company' => ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001',
        'env' => 'sandbox', 'country' => 'FR', 'formal_name' => 'Tricatel'],
        'identifier' => '0225:315143296_18171', 'directory' => 'peppol', 'status' => 'created'];
    $report = ($this->evidence)($invoice, 'live', ['data' => [$entry]]);
    $assessment = $report['checks']['buyer_destination']['assessment'];
    expect($report['checks']['buyer_destination']['status'])->toBe('passed')
        ->and($assessment['qualification_documentaire_sandbox'])->toBe('passed')
        ->and($assessment['basis'])->toBe('directory_list_contract')
        ->and($assessment['replyto_classification'])->toBe('undetermined')
        ->and($assessment['classification_diagnostic']['field_present'])->toBeFalse()
        ->and($assessment['delivery_verified'])->toBeFalse()
        ->and($assessment['ready_for_emission'])->toBeFalse()
        ->and($assessment)->not->toHaveKey('is_replyto')
        ->and($report['ready_for_emission'])->toBeFalse()->and($report['emission_ready'])->toBeFalse();
    $sellerConnection = Company::findOrFail(3)->superPdpConnection()->sole();
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
    expect(app(CiiPreparationEvidence::class)->assess($this->owner, $invoice, $data, $sellerConnection,
        $report['audit_reference'])['live'])->toBeTrue();
    expect(fn () => app(\App\Services\SuperPdp\CiiPreparationGuards::class)->authorization(3, $invoice->id,
        CiiPreparationEvidence::hash($data), $report['audit_reference'], $sellerConnection, 'isolated-client', true))
        ->toThrow(RuntimeException::class);
    expect(config('superpdp_preparation'))->toBe($configuration)->and(config('superpdp_emission'))->toBe($emission)
        ->and($invoice->fresh()->getRawOriginal())->toBe($before);
});

it('reuses passed seller checks from a refused buyer report only within the original validity and context', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $report = ($this->evidence)($invoice, 'live', ['data' => []]);
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
    $connection = Company::findOrFail(3)->superPdpConnection()->sole();
    $service = app(SandboxInvoiceEvidence::class);
    $reuse = fn ($candidate) => $service->reusableSellerChecks($invoice, $data, $connection, $candidate);
    expect($report['status'])->toBe('refused')
        ->and($reuse($report))->toBe(array_intersect_key($report['checks'], array_flip(['seller_identity', 'seller_session'])));
    $rotated = $report;
    $rotated['oauth_credential_sha256'] = str_repeat('a', 64);
    expect(fn () => $reuse($rotated))->toThrow(RuntimeException::class);
    $simulated = $report;
    $simulated['checks']['seller_identity']['source'] = 'simulated';
    expect(fn () => $reuse($simulated))->toThrow(RuntimeException::class);
    $old = $report;
    $old['checks']['seller_session']['observed_at'] = now()->subMinutes(16)->toIso8601String();
    expect(fn () => $reuse($old))->toThrow(RuntimeException::class);
    $this->travel(16)->minutes();
    expect(fn () => $reuse($report))->toThrow(RuntimeException::class);
    $this->travelBack();
});

it('refreshes the sandbox summary before finalization without French legal requirements or writes', function () {
    $body = $this->body;
    $body['items'] = [$body['items'][0]];
    $body['items'][0]['quantity'] = '1';
    $body['items'][0]['unit_price_ht'] = '100';
    // No SIRET, VAT number or French legal mentions on these sandbox parties.
    $this->mock(\App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic::class)
        ->shouldNotReceive('preview');
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Le récapitulatif sandbox doit rester en lecture seule.');
        }
    });
    $this->get(route('invoices.create'))->assertOk()
        ->assertSee('data-summary-refresh', false)->assertSee('invoice-finalization-summary.js');
    $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    $this->post(route('invoices.electronic-summary'), $body)->assertOk()
        ->assertSee('Sandbox SUPER PDP')->assertSee(SandboxInvoiceProfile::NOTICE)
        ->assertSee('Burger Queen')->assertSee('Tricatel')
        ->assertSee('100.00')->assertSee('20.00')->assertSee('120.00')
        ->assertDontSee('Informations à corriger')->assertDontSee('SIRET')
        ->assertDontSee('Indemnité de recouvrement')->assertDontSee('Pénalités de retard')
        ->assertViewHas('summary', fn ($summary) => $summary['sandbox'] && $summary['errors'] === []);
    $body['items'][0]['quantity'] = '2';
    $this->post(route('invoices.electronic-summary'), $body)->assertOk()->assertSee('240.00');
    $body['items'][0]['quantity'] = 'invalid';
    $this->post(route('invoices.electronic-summary'), $body)->assertOk()
        ->assertSee('Informations à corriger')->assertSee('Indisponible');
    $body['due_date'] = '2020-01-01';
    $this->post(route('invoices.electronic-summary'), $body)
        ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    expect(Invoice::count())->toBe(0);
});

it('rejects the sandbox summary outside its authorized runtime', function () {
    $this->app->instance('env', 'production');
    config(['app.env' => 'production']);
    $this->postJson(route('invoices.electronic-summary'), $this->body)
        ->assertUnprocessable()->assertJsonValidationErrors('emission_profile');
});

it('saves an incomplete sandbox draft and displays its editable summary without diagnosing a frozen document', function () {
    // Match Burger Queen's real sandbox profile: documentary details not entered yet.
    Company::findOrFail(3)->update(['address' => null, 'postal_code' => null, 'city' => null,
        'country_code' => null, 'vat_regime' => null, 'payment_terms' => null]);
    $this->mock(\App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic::class)
        ->shouldNotReceive('diagnose');
    $this->mock(SuperPdpInvoicePayloadBuilder::class, function ($mock) {
        $mock->shouldNotReceive('build');
        $mock->shouldNotReceive('buildFromData');
        $mock->shouldNotReceive('buildSandboxFromData');
    });
    $body = $this->body;
    $body['action'] = 'draft';
    $body['items'] = [$body['items'][0]];
    $body['items'][0]['quantity'] = '1';
    $body['items'][0]['unit_price_ht'] = '100';
    $this->post(route('invoices.store'), $body)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $body['notes'] = 'Modification enregistrée depuis le formulaire';
    $this->put(route('invoices.update', $draft), $body)->assertSessionHasNoErrors()
        ->assertRedirect(route('invoices.show', $draft));
    $draft->refresh();
    expect($draft->isDraft())->toBeTrue()
        ->and($draft->party_snapshot)->toBeNull()->and($draft->emission_context)->toBeNull()
        ->and($draft->invoice_number)->toBeNull()->and($draft->notes)->toBe($body['notes'])
        ->and($draft->subtotal_ht)->toBe('100.00')->and($draft->total_tva)->toBe('20.00')
        ->and($draft->total_ttc)->toBe('120.00');
    $before = $draft->getRawOriginal();
    $this->get(route('invoices.show', $draft))->assertOk()
        ->assertSee('Facture mise à jour avec succès.')
        ->assertSee('Burger Queen')->assertSee('Tricatel')->assertSee('120.00')
        ->assertSee('Le brouillon est enregistré et reste modifiable.')
        ->assertDontSee('Le profil Sandbox SUPER PDP exige ses parties autorisées')
        ->assertViewHas('emissionDiagnostic', null)
        ->assertViewHas('finalizationSummary', fn ($summary) => $summary['sandbox']);
    expect($draft->fresh()->getRawOriginal())->toBe($before);
});

it('lists every missing seller documentary field and finalizes only after they are entered', function () {
    Company::findOrFail(3)->update(['address' => null, 'postal_code' => null, 'city' => null,
        'country_code' => null, 'vat_regime' => null]);
    $body = $this->body;
    $body['action'] = 'draft';
    $body['french_business_process_type'] = 'B1';
    $body['items'] = [$body['items'][0]];
    $body['items'][0]['quantity'] = '1';
    $body['items'][0]['unit_price_ht'] = '100';
    $this->post(route('invoices.store'), $body)
        ->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $before = $draft->getRawOriginal();
    $this->from(route('invoices.show', $draft))
        ->post(route('invoices.finalize', $draft), ['_token' => $this->csrf])
        ->assertSessionHasErrors(['seller.address', 'seller.postal_code', 'seller.city', 'seller.country_code', 'vat_regime']);
    expect($draft->fresh()->getRawOriginal())->toBe($before)
        ->and($draft->isDraft())->toBeTrue()->and($draft->party_snapshot)->toBeNull()
        ->and($draft->emission_context)->toBeNull()->and($draft->invoice_number)->toBeNull();
    $this->get(route('invoices.show', $draft))->assertOk()
        ->assertSee('Paramètres entreprise')->assertSee('adresse postale')->assertSee('code postal')
        ->assertSee('ville')->assertSee('pays')->assertSee('Régime de TVA non renseigné')
        ->assertDontSee('Le profil Sandbox SUPER PDP exige ses parties autorisées');
    Company::findOrFail(3)->update(['address' => 'Adresse de test', 'postal_code' => '75001',
        'city' => 'Paris', 'country_code' => 'FR', 'vat_regime' => 'subject_to_vat']);
    $this->post(route('invoices.finalize', $draft), ['_token' => $this->csrf])->assertSessionHasNoErrors();
    $draft->refresh();
    expect($draft->isIssued())->toBeTrue()->and($draft->emission_context['invoice_id'])->toBe($draft->id)
        ->and($draft->party_snapshot)->not->toBeNull()->and($draft->invoice_number)->toStartWith('SBX-');
    expect(app(SuperPdpInvoicePayloadBuilder::class)->build($draft)['totals']['total_with_vat'])->toBe('120.00');
});

it('keeps draft editing then freezes only stored data under a separate fresh test reference', function () {
    $this->post(route('invoices.store'), array_replace($this->body, ['action' => 'draft']))->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $edited = $this->body;
    $edited['items'][0]['quantity'] = '3';
    $this->put(route('invoices.update', $invoice), $edited)->assertSessionHasNoErrors();
    $this->post(route('invoices.finalize', $invoice), ['_token' => $this->csrf])->assertSessionHasNoErrors();
    $invoice->refresh();
    $before = $invoice->getRawOriginal();
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
    Company::findOrFail(3)->update(['address' => 'Changed profile address']);
    $this->client->update(['address' => 'Changed client address']);
    expect(app(SuperPdpInvoicePayloadBuilder::class)->build($invoice))->toBe($payload);
    $this->put(route('invoices.update', $invoice), $edited)->assertForbidden();
    $this->post(route('invoices.finalize', $invoice), ['_token' => $this->csrf])->assertSessionHas('error');
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    expect(Invoice::count())->toBe(2)->and(Invoice::orderByDesc('id')->first()->invoice_number)->not->toBe($invoice->invoice_number);
});

it('refuses production direct HTTP requests and access to a sandbox document', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $this->app->instance('env', 'production');
    $this->get(route('invoices.create'))->assertOk()->assertDontSee('value="'.SandboxInvoiceProfile::PROFILE.'"', false);
    $this->post(route('invoices.store'), $this->body)->assertSessionHasErrors('emission_profile');
    foreach (['invoices.show', 'invoices.electronic-preparation', 'invoices.edit'] as $route) {
        $this->get(route($route, $invoice))->assertForbidden();
    }
    $this->post(route('invoices.finalize', $invoice), ['_token' => $this->csrf])->assertForbidden();
    $this->post(route('invoices.request-cii-preparation', $invoice), ['_token' => $this->csrf])->assertForbidden();
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf])->assertForbidden();
    $this->app->instance('env', 'sandbox');
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
});

it('requires the exact owner company client and provider bindings', function ($case) {
    match ($case) {
        'owner' => $this->actingAs($this->buyer),
        'client' => $this->client->update(['company_name' => 'Other']),
        'legal identifier' => Company::findOrFail(3)->update(['siret' => '73282932000074']),
        'provider' => Company::findOrFail(3)->superPdpConnection()->update(['superpdp_company_id' => 18171]),
    };
    $response = $this->post(route('invoices.store'), $this->body);
    if (in_array($case, ['owner', 'client'], true)) {
        $response->assertSessionHasErrors();
    } else {
        $response->assertForbidden();
    }
    expect(Invoice::count())->toBe(0);
})->with(['owner', 'client', 'legal identifier', 'provider']);

it('does not relax the French profile and ignores browser supplied frozen data or approvals', function () {
    $this->post(route('invoices.store'), array_replace($this->body, ['emission_profile' => 'fr_b2b_380_eur']))->assertSessionHasErrors();
    expect(Invoice::count())->toBe(0);
    $this->post(route('invoices.store'), $this->body + ['party_snapshot' => ['seller' => ['siret' => 'FAKE']],
        'emission_context' => ['approved' => true], 'authorization' => ['enabled' => true]])->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    expect($invoice->party_snapshot['seller']['siret'])->toBeNull()
        ->and($invoice->emission_context['profile'])->toBe(SandboxInvoiceProfile::PROFILE)
        ->and(config('superpdp_emission.authorization'))->toBeNull();
});

it('keeps CII HTTP and the French directory closed without scoped server evidence or authorization', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $this->post(route('invoices.request-cii-preparation', $invoice), ['_token' => $this->csrf])->assertStatus(303);
    $this->post(route('invoices.verify-destination', $invoice), ['_token' => $this->csrf])->assertStatus(422);
    expect(Storage::disk('invoice_cii')->allFiles())->toBe([])
        ->and($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('electronic_invoice_attempts')->count())->toBe(0);
});

it('uses the existing sandbox CII checks for the exact frozen own lines and totals', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
    $xml = SandboxOwnInvoiceCii::xml($payload);
    app(CiiDocumentChecks::class)->assertInvoiceMatches($xml, $payload, $invoice);
    expect(fn () => app(CiiDocumentChecks::class)->assertInvoiceMatches(
        str_replace($invoice->invoice_number, 'SANDBOX-PREPARATION-BQ-TRICATEL', $xml), $payload, $invoice))->toThrow(RuntimeException::class);
});

it('records only typed sandbox proofs and rejects French forged stale or rotated evidence', function ($case) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $report = ($this->evidence)($invoice, $case === 'simulated' ? 'simulated' : 'live');
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
    $connection = Company::findOrFail(3)->superPdpConnection()->sole();
    if (in_array($case, ['French kind', 'stale', 'wrong invoice'], true)) {
        match ($case) {
            'French kind' => $report['kind'] = 'ordinary',
            'stale' => $report['checked_at'] = now()->subMinutes(16)->toIso8601String(),
            'wrong invoice' => $report['source_sha256'] = str_repeat('a', 64),
        };
        unset($report['audit_reference']);
        $report['audit_reference'] = app(VerificationAuditStore::class)->store($this->owner, $invoice, $report);
    }
    if ($case === 'rotated token') {
        $connection->update(['access_token' => 'ROTATED-OFFLINE']);
    }
    $assess = fn () => app(CiiPreparationEvidence::class)->assess($this->owner, $invoice, $data, $connection, $report['audit_reference']);
    if (in_array($case, ['live', 'simulated'], true)) {
        expect($assess()['live'])->toBe($case === 'live');
    } else {
        expect($assess)->toThrow(RuntimeException::class);
    }
})->with(['live', 'simulated', 'French kind', 'stale', 'wrong invoice', 'rotated token']);

it('runs HTTP conversion and validation through the existing reservation once and keeps mocked CII non emitting', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $report = ($this->evidence)($invoice);
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
    $xml = SandboxOwnInvoiceCii::xml($payload);
    config(['superpdp_preparation.web_enabled' => true, 'superpdp_preparation.live_enabled' => true,
        'superpdp_preparation.authorization' => ['approved_by' => 'ISOLATED-FIXTURE', 'approved_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(10)->timestamp, 'user_id' => 3, 'invoice_id' => $invoice->id,
            'source_sha256' => CiiPreparationEvidence::hash($data), 'verification_reference' => $report['audit_reference'],
            'environment' => 'sandbox', 'oauth_client_id' => 'isolated-client', 'api_url' => 'https://api.superpdp.tech/v1.beta', 'provider_company_id' => 18172]]);
    $this->sdk->addResponses([
        ConvertInvoiceRequest::class => MockResponse::make($xml, 200, ['Content-Type' => 'application/xml']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($xml)),
    ]);
    $this->post(route('invoices.request-cii-preparation', $invoice), ['_token' => $this->csrf])->assertStatus(303)->assertSessionHas('cii_request_status', 'review');
    $this->expectedSdkCalls = 2;
    $slot = app(CiiPreparationRegistry::class)->consult($this->owner, $invoice);
    expect($slot['state'])->toBe('review_required')->and($slot['result']['status'])->toBe('prepared_simulation');
    $this->post(route('invoices.request-cii-preparation', $invoice), ['_token' => $this->csrf])->assertStatus(303);
    expect($invoice->fresh()->getRawOriginal())->toBe($before)->and(DB::table('electronic_invoice_attempts')->count())->toBe(0);
});

it('uses OutgoingEmission for this new test profile and never duplicates accepted refused or uncertain SDK deposits', function ($outcome) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $artifact = ($this->validated)($invoice, ($this->evidence)($invoice));
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve'])->assertStatus(303);
    $grant = session('emission_confirmation.'.$invoice->id);
    $attempt = ElectronicInvoiceAttempt::sole();
    expect($attempt->reference)->toBe($grant['uuid'])->not->toBe($invoice->invoice_number)
        ->and($attempt->transport_started_at)->toBeNull();
    $this->sdk->assertNothingSent();
    $this->sdk->addResponses([CreateInvoiceRequest::class => function ($pending) use ($outcome, $attempt, $artifact) {
        expect(DB::transactionLevel())->toBe(0)->and($attempt->fresh()->transport_started_at)->not->toBeNull()
            ->and($pending->query()->get('external_id'))->toBe($attempt->reference)
            ->and(hash('sha256', $pending->body()->all()))->toBe($artifact['cii_sha256']);

        return match ($outcome) {
            'accepted' => MockResponse::make(['id' => 999, 'company_id' => 18172, 'direction' => 'out',
                'external_id' => $attempt->reference, 'created_at' => now()->toIso8601String()]),
            'rejected' => MockResponse::make(['http_status_code' => 400, 'message' => 'Refused'], 400),
            'unknown' => MockResponse::make([], 503),
        };
    }]);
    $body = ['_token' => $this->csrf, 'step' => 'confirm', 'confirm_emission' => '1',
        'attempt_reference' => $grant['uuid'], 'confirmation_token' => $grant['token']];
    $this->post(route('invoices.request-electronic-emission', $invoice), $body)->assertStatus(303);
    $this->post(route('invoices.request-electronic-emission', $invoice), $body)->assertStatus(303);
    $this->expectedSdkCalls = 1;
    expect($attempt->fresh()->status)->toBe($outcome)->and(ElectronicInvoiceAttempt::count())->toBe(1)
        ->and($invoice->fresh()->getRawOriginal())->toBe($before);
    $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(SandboxInvoiceProfile::NOTICE);
})->with(['accepted', 'rejected', 'unknown']);

it('selects and reserves the exact revalidation independently of the preparation journal', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    [$original, $revalidated] = ($this->revalidated)($invoice);
    $registry = app(CiiPreparationRegistry::class);
    expect($registry->consult($this->owner, $invoice)['result']['artifact']['reference'])->toBe($original['reference']);
    $files = collect(Storage::disk('invoice_cii')->allFiles())
        ->mapWithKeys(fn ($file) => [$file => hash_file('sha256', Storage::disk('invoice_cii')->path($file))])->all();
    config(['superpdp_preparation.sandbox_client_id' => null, 'superpdp_preparation.web_enabled' => false]);

    $inspection = $this->get(route('invoices.show', $invoice))->assertOk()->viewData('emissionReadiness');
    expect($inspection['document_reference'])->toBe($revalidated['reference'])->and($inspection['can_send'])->toBeTrue();
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve',
        'document_reference' => $original['reference']])->assertStatus(303);
    expect(ElectronicInvoice::sole()->document_reference)->toBe($revalidated['reference'])
        ->and(ElectronicInvoiceAttempt::sole()->transport_started_at)->toBeNull();
    expect(collect(Storage::disk('invoice_cii')->allFiles())
        ->mapWithKeys(fn ($file) => [$file => hash_file('sha256', Storage::disk('invoice_cii')->path($file))])->all())->toBe($files);
});

it('refuses a revalidation with stale or mismatched document lineage or qualification', function ($case) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    ($this->revalidated)($invoice, $case);
    $inspection = app(InvoiceEmissionReadiness::class)->inspect($this->owner, $invoice);
    expect($inspection['document_reference'])->toBeNull()->and($inspection['can_send'])->toBeFalse();
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve'])->assertStatus(422);
    expect(ElectronicInvoiceAttempt::count())->toBe(0);
})->with(['expired validation', 'simulated validation', 'expired directory', 'foreign parent owner', 'foreign parent invoice',
    'wrong parent fingerprint', 'different XML bytes', 'reused report', 'wrong proof fingerprint', 'wrong buyer identity',
    'wrong seller credential', 'wrong proof source']);

it('does not fall back to a fresh preparation when the server reference is absent invalid or expired', function ($case) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    ($this->validated)($invoice, ($this->evidence)($invoice));
    match ($case) {
        'absent' => config(['superpdp_emission.authorization.document_reference' => null]),
        'invalid' => config(['superpdp_emission.authorization.document_reference' => 'invoice-cii/v1/invalid']),
        'expired' => config(['superpdp_emission.authorization.expires_at' => now()->timestamp]),
    };
    $inspection = app(InvoiceEmissionReadiness::class)->inspect($this->owner, $invoice);
    expect($inspection['document_reference'])->toBeNull()->and($inspection['can_send'])->toBeFalse();
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve'])->assertStatus(422);
    expect(ElectronicInvoiceAttempt::count())->toBe(0);
})->with(['absent', 'invalid', 'expired']);

it('refuses confirmation when a proof expires or the server selects a different archive after reservation', function ($case) {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    [, $revalidated] = ($this->revalidated)($invoice, $case === 'expired proof' ? 'expiring directory' : 'valid');
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve'])->assertStatus(303);
    $grant = session('emission_confirmation.'.$invoice->id);
    if ($case === 'expired proof') {
        $this->travel(2)->minutes();
    } else {
        $archive = app(PreparedCiiArchive::class)->readValidated($this->owner, $invoice, $revalidated['reference']);
        $manifest = $archive['manifest'];
        $manifest['validation']['response_sha256'] = str_repeat('a', 64);
        $other = ($this->publishCii)($invoice, $manifest, $archive['xml']);
        config(['superpdp_emission.authorization.document_reference' => $other['reference']]);
        expect(app(InvoiceEmissionReadiness::class)->inspect($this->owner, $invoice)['document_reference'])->toBe($other['reference']);
    }
    expect($grant['expires_at'])->toBeGreaterThan(now()->timestamp);
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'confirm',
        'confirm_emission' => '1', 'attempt_reference' => $grant['uuid'], 'confirmation_token' => $grant['token']])->assertStatus(303);
    $attempt = ElectronicInvoiceAttempt::sole();
    expect($attempt->status)->toBe('rejected')->and($attempt->error_code)->toBe('local_precondition_failed')
        ->and($attempt->transport_started_at)->toBeNull()
        ->and(ElectronicInvoice::sole()->document_reference)->toBe($revalidated['reference']);
})->with(['expired proof', 'different reference']);

it('rejects a modified frozen document locally after reservation before any SDK send', function () {
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    ($this->validated)($invoice, ($this->evidence)($invoice));
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'reserve'])->assertStatus(303);
    $grant = session('emission_confirmation.'.$invoice->id);
    DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['description' => 'Tampered isolated fixture']);
    $this->post(route('invoices.request-electronic-emission', $invoice), ['_token' => $this->csrf, 'step' => 'confirm', 'confirm_emission' => '1',
        'attempt_reference' => $grant['uuid'], 'confirmation_token' => $grant['token']])->assertStatus(303);
    expect(ElectronicInvoiceAttempt::sole()->status)->toBe('rejected')
        ->and(ElectronicInvoiceAttempt::sole()->error_code)->toBe('local_precondition_failed');
});

it('generates short unique sandbox references for the replacement totals without rewriting frozen history', function () {
    $body = $this->body;
    $body['items'] = [['description' => 'Prestation de test SimpleDevis', 'quantity' => '1',
        'unit_price_ht' => '100', 'tva_rate' => '20', 'tax_treatment' => 'taxable', 'unit_code' => 'C62']];
    $this->post(route('invoices.store'), $body)->assertSessionHasNoErrors();
    $first = Invoice::sole();
    $before = $first->getRawOriginal();
    $this->post(route('invoices.store'), $body)->assertSessionHasNoErrors();
    $second = Invoice::orderByDesc('id')->first();
    foreach ([$first, $second] as $invoice) {
        expect($invoice->invoice_number)->toMatch('/\ASBX-[0-9A-HJKMNP-TV-Z]{26}\z/D')
            ->and(mb_strlen($invoice->invoice_number))->toBe(30)
            ->and($invoice->subtotal_ht)->toBe('100.00')->and($invoice->total_tva)->toBe('20.00')
            ->and($invoice->total_ttc)->toBe('120.00');
        $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
        expect($payload['seller']['electronic_address']['value'])->toBe('315143296_18172')
            ->and($payload['buyer']['electronic_address']['value'])->toBe('315143296_18171');
    }
    expect($first->invoice_number)->not->toBe($second->invoice_number)
        ->and($first->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('invoice_counters')->count())->toBe(0);
    // In-memory historical fixture only: capture must retain an old 40-character reference.
    $legacy = clone $first;
    $legacy->emission_context = array_replace($legacy->emission_context,
        ['test_reference' => 'SBX-ea09521e-ab70-4f90-aa63-8268d743d151']);
    expect(SandboxInvoiceProfile::capture($legacy, Company::findOrFail(3), $this->client)['test_reference'])
        ->toBe('SBX-ea09521e-ab70-4f90-aa63-8268d743d151');
});

it('checks the electronic number length at the transactional finalization boundary', function ($route, $length) {
    // Controlled generator output exercises the 35/36-character boundary on both HTTP paths.
    \Illuminate\Support\Str::createUlidsUsing(fn () => str_repeat('X', $length - 4));
    try {
        $body = $this->body;
        if ($route === 'draft') {
            $body['action'] = 'draft';
            $this->post(route('invoices.store'), $body)->assertSessionHasNoErrors();
            $draft = Invoice::sole();
            $before = $draft->getRawOriginal();
            $response = $this->post(route('invoices.finalize', $draft), ['_token' => $this->csrf]);
        } else {
            $response = $this->post(route('invoices.store'), $body);
        }
        if ($length > 35) {
            $response->assertSessionHasErrors('invoice_number');
            if ($route === 'draft') {
                expect($draft->fresh()->getRawOriginal())->toBe($before)
                    ->and($draft->fresh()->party_snapshot)->toBeNull()->and($draft->fresh()->emission_context)->toBeNull();
            } else {
                expect(Invoice::count())->toBe(0)->and(DB::table('invoice_items')->count())->toBe(0);
            }
        } else {
            $response->assertSessionHasNoErrors();
            expect(Invoice::sole()->isIssued())->toBeTrue()->and(mb_strlen(Invoice::sole()->invoice_number))->toBe(35);
        }
    } finally {
        \Illuminate\Support\Str::createUlidsNormally();
    }
})->with([['direct', 35], ['direct', 36], ['draft', 35], ['draft', 36]]);

it('offers the profile and freezes a new own multi rate test invoice without consuming the French counter', function () {
    $this->get(route('invoices.create'))->assertOk()->assertSee('Sandbox SUPER PDP');
    $this->post(route('invoices.store'), $this->body)->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));
    $invoice = Invoice::sole();
    expect($invoice->invoice_number)->toStartWith('SBX-')->and($invoice->total_ttc)->toBe('230.00')
        ->and($invoice->party_snapshot['seller']['siret'])->toBeNull()->and(DB::table('invoice_counters')->count())->toBe(0);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
    expect(array_column($payload['lines'], 'invoiced_quantity'))->toBe(['2.50', '4.00'])
        ->and($payload['seller']['legal_registration_identifier']['value'])->toBe('000000002')
        ->and($payload['buyer']['electronic_address']['value'])->toBe('315143296_18171');
    $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(SandboxInvoiceProfile::NOTICE);
    $this->get(route('invoices.electronic-preparation', $invoice))->assertOk()->assertSee(SandboxInvoiceProfile::NOTICE);
});
