<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationResultView;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\Verification\InvoiceDestinationVerification;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Tests\Support\ReadOnlyDraftPreviewDatabase;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::swap(new class extends Factory
    {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('Network forbidden'));
        }
    });
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(static fn () => throw new LogicException('SDK sender forbidden'));
    Storage::shouldReceive('disk')->never();
    $this->app->bind(InvoiceCiiPreparation::class, static fn () => throw new LogicException('Producer forbidden on GET'));
    $this->app->bind(PreparedCiiArchive::class, static fn () => throw new LogicException('Archive forbidden on GET'));
    // Result consultation has its own read-only integration suite; isolate readiness here.
    $resultView = $this->createMock(CiiPreparationResultView::class);
    $resultView->method('inspect')->willReturn(['state' => 'none', 'label' => 'Aucune préparation effectuée.', 'date' => null,
        'validation' => 'Non effectuée', 'messages' => [], 'download_id' => null, 'simulation' => false]);
    $this->app->instance(CiiPreparationResultView::class, $resultView);
    $destination = $this->createMock(InvoiceDestinationVerification::class);
    $destination->method('latest')->willReturn(null);
    $destination->method('validityMinutes')->willReturn(15);
    $destination->expects($this->never())->method('verify');
    $this->app->instance(InvoiceDestinationVerification::class, $destination);
    $this->report = null;
    $audits = $this->createMock(VerificationAuditStore::class);
    $audits->method('read')->willReturnCallback(function ($actor, $invoice, $reference) {
        if ($this->report === null) {
            throw new RuntimeException('PRIVATE-PATH-SECRET');
        }

        return $this->report;
    });
    $audits->expects($this->never())->method('store');
    $this->app->instance(VerificationAuditStore::class, $audits);
    $this->withoutVite();
    Carbon::setTestNow('2026-11-14 12:00:00');
    config(['session.driver' => 'array', 'database.default' => 'offline',
        'database.connections' => ['offline' => ['driver' => 'sqlite', 'database' => ':memory:']],
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.client_id' => 'offline-client',
        'superpdp_preparation.sandbox_client_id' => 'offline-client',
        'superpdp_preparation.live_enabled' => false, 'superpdp_preparation.authorization' => null]);
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new ReadOnlyDraftPreviewDatabase;
    DB::extend('offline', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $data = SuperPdpPayloadFixture::data();
    $data['buyer'] += ['company_name' => 'Acheteur figé', 'siret' => '55210055400013'];
    $data['buyer']['client_type'] = 'professional';
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])->pluck('note', 'subject_code')->all();
    $this->memory->rows = [
        'users' => [1 => ['id' => 1, 'name' => 'Owner'], 2 => ['id' => 2, 'name' => 'Other']],
        'companies' => [1 => ['id' => 1, 'user_id' => 1, 'company_name' => 'Fiche actuelle distincte']],
        'invoices' => [99 => ['id' => 99, 'user_id' => 1, 'client_id' => 45,
            ...array_intersect_key($data, array_flip(['invoice_number', 'date', 'due_date', 'document_status', 'calculation_version', 'status', 'amount_paid'])),
            ...$data['totals'], 'french_business_process_type' => 'S1', 'emission_profile' => InvoiceEmissionContext::PROFILE,
            'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($data),
            'emission_context' => json_encode(InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
                ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], $data))]],
        'invoice_items' => [42 => $data['items'][0] + ['invoice_id' => 99]],
        'payments' => [], 'credit_notes' => [],
        'superpdp_connections' => [1 => ['id' => 1, 'company_id' => 1, 'superpdp_company_id' => 123,
            'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'status' => 'connected', 'access_token' => Crypt::encryptString('ACCESS-SECRET'),
            'refresh_token' => Crypt::encryptString('REFRESH-SECRET'), 'access_token_expires_at' => now()->addHour()->toDateTimeString()]],
    ];
    $this->actor = User::findOrFail(1);
    $this->actingAs($this->actor);
    $this->open = fn (array $query = []) => $this->get(route('invoices.electronic-preparation', ['invoice' => 99] + $query));
    $this->beforeRows = $this->memory->rows;
    $this->proofsAndApproval = function () {
        $source = app(InvoiceExportDataBuilder::class)->buildElectronic(Invoice::findOrFail(99));
        $this->report = ['status' => 'passed', 'expected_environment' => 'sandbox',
            'expected_provider_company_id' => 123, 'expected_oauth_client_id' => 'offline-client',
            'expected_api_url' => 'https://api.superpdp.tech/v1.beta',
            'source_sha256' => CiiPreparationEvidence::hash($source),
            'party_snapshot_sha256' => hash('sha256', $source['party_snapshot']),
            'emission_context_sha256' => hash('sha256', $source['emission_context']),
            'checked_at' => now()->toIso8601String(), 'checks' => []];
        foreach (['buyer_destination', 'seller_identity', 'seller_session'] as $key) {
            $this->report['checks'][$key] = ['status' => 'passed', 'source' => 'live', 'verified' => true,
                'response_sha256' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
        }
        config(['superpdp_preparation.live_enabled' => true, 'superpdp_preparation.authorization' => [
            'approved_by' => 'Offline test', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
            'user_id' => 1, 'invoice_id' => 99, 'source_sha256' => CiiPreparationEvidence::hash($source),
            'verification_reference' => 'invoice-party-checks/v1/1/99/'.str_repeat('a', 64).'.json',
            'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'provider_company_id' => 123,
            'api_url' => 'https://api.superpdp.tech/v1.beta',
        ]]);
    };
});

afterEach(function () {
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Model::setConnectionResolver($this->originalResolver);
    Carbon::setTestNow();
    Http::assertNothingSent();
    expect($this->memory->rows)->toBe($this->beforeRows);
});

it('adds the CII section without changing existing sections or exposing an active generation action', function () {
    $response = ($this->open)()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Document CII')->assertSee('Données documentaires figées')->assertSee('Connexion OAuth')
        ->assertSee('Preuves vendeur et destinataire')->assertSee('Autorisation de préparation')
        ->assertSee('Aucun bilan complet vendeur/destinataire')->assertSee('désactivées dans la configuration')
        ->assertSee('Préparation documentaire locale')->assertSee('Informations figées à la finalisation')
        ->assertSee('Vérification de la destination électronique')->assertSee('Montants enregistrés sur la facture')
        ->assertDontSee('ACCESS-SECRET')->assertDontSee('REFRESH-SECRET')->assertDontSee('PRIVATE-PATH-SECRET');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $x = new DOMXPath($dom);
    $button = $x->query('//section[@aria-labelledby="cii-document-title"]//button')->item(0);
    expect($button->textContent)->toBe('Préparer le document CII')
        ->and($button->getAttribute('type'))->toBe('button')->and($button->hasAttribute('disabled'))->toBeTrue()
        ->and($x->query('//section[@aria-labelledby="cii-document-title"]//form')->length)->toBe(0);
});

it('stays disabled even when all local preconditions and config approvals match', function () {
    ($this->proofsAndApproval)();
    ($this->open)()->assertOk()->assertViewHas('ciiReadiness', function ($r) {
        expect(array_column($r['groups'], 'passed'))->toBe([true, true, true, true])
            ->and($r['can_prepare'])->toBeFalse()->and($r['web_preparation_enabled'])->toBeFalse()
            ->and($r['cii_produced'])->toBeFalse()->and($r['validation_performed'])->toBeFalse()
            ->and($r['emission_allowed'])->toBeFalse();

        return true;
    })->assertSee('Le parcours web de préparation CII n’est pas activé.');
});

it('shows a CSRF protected POST form only when the sandbox switch and every prerequisite pass', function () {
    ($this->proofsAndApproval)();
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'superpdp_preparation.web_enabled' => true]);
    $response = ($this->open)()->assertOk()->assertViewHas('ciiReadiness', fn ($r) => $r['can_prepare'] && $r['web_preparation_enabled']);
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $x = new DOMXPath($dom);
    $form = $x->query('//section[@aria-labelledby="cii-document-title"]//form')->item(0);
    expect($form->getAttribute('method'))->toBe('POST')
        ->and($form->getAttribute('action'))->toBe(route('invoices.request-cii-preparation', 99))
        ->and($x->query('.//input[@name="_token"]', $form)->length)->toBe(1)
        ->and($x->query('.//button[@type="submit" and not(@disabled)]', $form)->length)->toBe(1);
});

it('keeps the button disabled with the sandbox web switch when proof or authorization is missing', function () {
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'superpdp_preparation.web_enabled' => true]);
    ($this->open)()->assertOk()->assertViewHas('ciiReadiness', fn ($r) => ! $r['can_prepare'])
        ->assertSee('disabled', false);
});

it('does not expose a POST for CII generation', function () {
    $this->post(route('invoices.electronic-preparation', 99))->assertStatus(405);
});

it('does not mistake a qualified destination-only report for complete party evidence', function () {
    $destination = $this->createMock(InvoiceDestinationVerification::class);
    $destination->method('latest')->willReturn(['status' => 'qualified', 'verified' => true,
        'source' => 'live', 'reason' => 'unique_active_exact_match_simulated']);
    $destination->method('validityMinutes')->willReturn(15);
    $destination->expects($this->never())->method('verify');
    $this->app->instance(InvoiceDestinationVerification::class, $destination);
    ($this->open)()->assertOk()->assertSee('Destination qualifiée')
        ->assertSee('Aucun bilan complet vendeur/destinataire')
        ->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['evidence']['passed'] && ! $r['can_prepare']);
});

it('requires the owner of an issued invoice', function (string $case) {
    if ($case === 'guest') {
        auth()->forgetGuards();
        ($this->open)()->assertRedirect(route('login'));

        return;
    }
    if ($case === 'other owner') {
        $this->actingAs(User::findOrFail(2));
    } else {
        $this->memory->rows['invoices'][99]['document_status'] = 'draft';
        $this->beforeRows = $this->memory->rows;
    }
    ($this->open)()->assertStatus($case === 'draft' ? 404 : 403)->assertDontSee('Acheteur figé');
})->with(['guest', 'other owner', 'draft']);

it('preserves documentary failures rather than replacing frozen data with current profiles', function (string $case) {
    if ($case === 'missing snapshot') {
        $this->memory->rows['invoices'][99]['party_snapshot'] = null;
    } elseif ($case === 'bad snapshot') {
        $this->memory->rows['invoices'][99]['party_snapshot'] = '{"schema_version":999}';
    } elseif ($case === 'missing context') {
        $this->memory->rows['invoices'][99]['emission_context'] = null;
    } else {
        $this->memory->rows['invoices'][99]['total_ttc'] = '999.00';
    }
    $this->beforeRows = $this->memory->rows;
    ($this->open)()->assertOk()->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['documents']['passed']
        && $r['groups']['documents']['messages'] !== [] && ! $r['can_prepare'])
        ->assertDontSee('Fiche actuelle distincte');
})->with(['missing snapshot', 'bad snapshot', 'missing context', 'wrong totals']);

it('reports connection and environment blockers using shared guards', function (string $case, string $message) {
    match ($case) {
        'missing' => $this->memory->rows['superpdp_connections'] = [],
        'foreign connection' => $this->memory->rows['superpdp_connections'][1]['company_id'] = 2,
        'disconnected' => $this->memory->rows['superpdp_connections'][1]['status'] = 'disconnected',
        'expired' => $this->memory->rows['superpdp_connections'][1]['access_token_expires_at'] = now()->addMinutes(4)->toDateTimeString(),
        'broken token' => $this->memory->rows['superpdp_connections'][1]['refresh_token'] = 'BROKEN-SECRET',
        'client' => $this->memory->rows['superpdp_connections'][1]['oauth_client_id'] = 'other',
        'unpinned' => config(['superpdp_preparation.sandbox_client_id' => null]),
        'production' => $this->app->instance('env', 'production'),
        'provider production' => config(['services.superpdp.environment' => 'production']),
    };
    $this->beforeRows = $this->memory->rows;
    ($this->open)()->assertOk()->assertSee($message)->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['oauth']['passed']);
})->with([
    ['missing', 'Aucune connexion OAuth'], ['foreign connection', 'Aucune connexion OAuth'],
    ['disconnected', 'La connexion OAuth est déconnectée'], ['expired', 'Le jeton OAuth est expiré'],
    ['client', 'Connexion OAuth non qualifiée'], ['unpinned', 'configuré explicitement'],
    ['broken token', 'Les jetons OAuth enregistrés sont illisibles'],
    ['production', 'notamment en production'], ['provider production', 'exige le contexte SUPER PDP sandbox'],
]);

it('rejects missing stale simulated or mismatched complete proof reports', function (string $case, string $message) {
    ($this->proofsAndApproval)();
    match ($case) {
        'absent' => $this->report = null,
        'stale' => $this->report['checks']['seller_identity']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
        'missing seller' => $this->report['checks']['seller_identity'] = [],
        'missing destination' => $this->report['checks']['buyer_destination'] = [],
        'foreign invoice' => $this->report['source_sha256'] = str_repeat('b', 64),
        'simulation' => $this->report['checks']['seller_session'] = array_replace($this->report['checks']['seller_session'], ['source' => 'simulated', 'verified' => false]),
    };
    ($this->open)()->assertOk()->assertSee($message)->assertDontSee('PRIVATE-PATH-SECRET')
        ->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['evidence']['passed'] && ! $r['can_prepare']);
})->with([
    ['absent', 'absent, illisible ou non autorisé'], ['stale', 'fraîcheur maximale exigée est de quinze minutes'],
    ['missing seller', 'preuves qualifiées et cohérentes'], ['missing destination', 'preuves qualifiées et cohérentes'],
    ['foreign invoice', 'ne correspond pas aux données figées'], ['simulation', 'preuves simulées ou en cache'],
]);

it('reports invalid scoped authorizations and ignores caller supplied approvals', function (string $case) {
    ($this->proofsAndApproval)();
    match ($case) {
        'expired' => config(['superpdp_preparation.authorization.expires_at' => now()->timestamp]),
        'foreign invoice' => config(['superpdp_preparation.authorization.invoice_id' => 999]),
        'foreign owner' => config(['superpdp_preparation.authorization.user_id' => 2]),
        'source' => config(['superpdp_preparation.authorization.source_sha256' => str_repeat('c', 64)]),
        'missing' => config(['superpdp_preparation.authorization' => null]),
    };
    ($this->open)(['authorization' => ['invoice_id' => 99], 'live_enabled' => 1, 'can_prepare' => 1, 'verification_reference' => 'INJECTED'])
        ->assertOk()->assertDontSee('INJECTED')->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['authorization']['passed'] && ! $r['can_prepare']);
})->with(['expired', 'foreign invoice', 'foreign owner', 'source', 'missing']);

it('retains the gateway authorization guards after extraction without sending any request', function (string $case) {
    ($this->proofsAndApproval)();
    $reference = config('superpdp_preparation.authorization.verification_reference');
    $sourceHash = config('superpdp_preparation.authorization.source_sha256');
    match ($case) {
        'disabled' => config(['superpdp_preparation.live_enabled' => false]),
        'missing' => config(['superpdp_preparation.authorization' => null]),
        'expired' => config(['superpdp_preparation.authorization.expires_at' => now()->timestamp]),
        'future' => config(['superpdp_preparation.authorization.approved_at' => now()->addMinute()->timestamp]),
        'long' => config(['superpdp_preparation.authorization.expires_at' => now()->addHour()->timestamp]),
        'no approver' => config(['superpdp_preparation.authorization.approved_by' => '']),
        'owner' => config(['superpdp_preparation.authorization.user_id' => 2]),
        'invoice' => config(['superpdp_preparation.authorization.invoice_id' => 2]),
        'source' => config(['superpdp_preparation.authorization.source_sha256' => str_repeat('b', 64)]),
        'proof' => config(['superpdp_preparation.authorization.verification_reference' => 'other']),
        'provider' => config(['superpdp_preparation.authorization.provider_company_id' => 456]),
        'environment' => config(['superpdp_preparation.authorization.environment' => 'production']),
        'client' => config(['superpdp_preparation.authorization.oauth_client_id' => 'other']),
        'api' => config(['superpdp_preparation.authorization.api_url' => 'https://other.invalid']),
        'simulated evidence' => $this->report['checks']['seller_identity'] = array_replace($this->report['checks']['seller_identity'], ['source' => 'simulated', 'verified' => false]),
    };
    expect(fn () => SandboxCiiPreparationGateway::forInvoice($this->actor, Invoice::findOrFail(99), $sourceHash, $reference))
        ->toThrow(RuntimeException::class);
})->with(['disabled', 'missing', 'expired', 'future', 'long', 'no approver', 'owner', 'invoice',
    'source', 'proof', 'provider', 'environment', 'client', 'api', 'simulated evidence']);

it('keeps Tricatel blocked without using its sandbox number as a legal identifier', function () {
    $snapshot = json_decode($this->memory->rows['invoices'][99]['party_snapshot'], true);
    $snapshot['seller']['name'] = 'Tricatel';
    $snapshot['seller']['siret'] = null;
    $snapshot['seller']['vat_number'] = null;
    $this->memory->rows['invoices'][99]['party_snapshot'] = json_encode($snapshot);
    $this->beforeRows = $this->memory->rows;
    ($this->open)()->assertOk()->assertViewHas('ciiReadiness', fn ($r) => ! $r['groups']['documents']['passed'] && ! $r['can_prepare']);
});
