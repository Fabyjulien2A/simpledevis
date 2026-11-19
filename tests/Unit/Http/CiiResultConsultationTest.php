<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\CiiPreparationJournal;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\InvoiceDestinationVerification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Tests\Support\PreparedCiiFixture;
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
    $this->app->bind(InvoiceCiiPreparation::class, static fn () => throw new LogicException('Producer forbidden on GET'));
    $destinations = $this->createMock(InvoiceDestinationVerification::class);
    $destinations->method('latest')->willReturn(null);
    $destinations->method('validityMinutes')->willReturn(15);
    $destinations->expects($this->never())->method('verify');
    $this->app->instance(InvoiceDestinationVerification::class, $destinations);
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-11-14T12:00:00Z'));
    Storage::fake('invoice_cii');
    config(['app.debug' => false, 'session.driver' => 'array', 'database.default' => 'offline',
        'database.connections' => ['offline' => ['driver' => 'sqlite', 'database' => ':memory:']],
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'superpdp_preparation.sandbox_client_id' => 'offline-client',
        'superpdp_preparation.live_enabled' => false, 'superpdp_preparation.authorization' => null]);
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new ReadOnlyDraftPreviewDatabase;
    DB::extend('offline', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $data['buyer'] += ['company_name' => 'Acheteur figé', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554'];
    $data['buyer']['client_type'] = 'professional';
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])->pluck('note', 'subject_code')->all();
    $this->memory->rows = [
        'users' => [1 => ['id' => 1, 'name' => 'Owner'], 2 => ['id' => 2, 'name' => 'Other']],
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
            'superpdp_company_id' => 123, 'directory_identifier' => '732829320', 'status' => 'disconnected',
            'access_token' => null, 'refresh_token' => null]],
    ];
    $this->actor = User::findOrFail(1);
    $this->actingAs($this->actor);
    $this->invoice = Invoice::findOrFail(99);
    $this->registry = app(CiiPreparationRegistry::class);
    $this->beforeRows = $this->memory->rows;
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $this->open = fn (array $query = []) => $this->get(route('invoices.electronic-preparation', ['invoice' => 99] + $query));
    $this->download = fn ($id) => $this->get(route('invoices.cii-download', ['invoice' => 99, 'reservation' => $id]));
    // Synthetic archive fixtures only: no converter, producer, observation or API call.
    $this->prepareFixture = function (string $kind = 'validated', string $validation = 'passed') {
        $reservation = $this->registry->reserve($this->actor, $this->invoice, 'api');
        $binding = $reservation['claim']->binding();
        $manifest = array_intersect_key($binding, array_flip(['user_id', 'invoice_id', 'source_sha256', 'payload_sha256',
            'party_snapshot_sha256', 'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id']))
            + ['schema_version' => 1, 'mode' => match ($kind) {
                'validated' => 'validated_private_document', 'pending' => 'pending_validation', default => 'offline_simulation',
            }, 'ready_for_emission' => $kind === 'validated', 'provenance' => $kind === 'simulations' ? 'simulated' : 'live',
                'cii_sha256' => hash('sha256', $this->xml), 'validation' => ['status' => $validation, 'report' => PreparedCiiFixture::report($this->xml)]];
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $relative = 'v1/'.$kind.'/1/99/'.$hash;
        $directory = Storage::disk('invoice_cii')->path($relative);
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/manifest.json', $json);
        file_put_contents($directory.'/document.xml', $this->xml);
        $artifact = ['reference' => 'invoice-cii/'.$relative, 'manifest_sha256' => $hash, 'cii_sha256' => hash('sha256', $this->xml)];
        $this->registry->complete($this->actor, $this->invoice, $reservation['claim'], $artifact);

        return $reservation + ['directory' => $directory];
    };
    $this->files = function () {
        $root = Storage::disk('invoice_cii')->path('');
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            clearstatcache(true, $path);
            $files[$path] = [$file->isDir() ? 'directory' : hash_file('sha256', $path), filemtime($path), fileperms($path)];
        }
        ksort($files);

        return $files;
    };
    $this->readOnly = function ($operation) {
        $before = ($this->files)();
        $response = $operation();
        expect(($this->files)())->toBe($before);

        return $response;
    };
});

afterEach(function () {
    expect($this->memory->rows)->toBe($this->beforeRows);
    Http::assertNothingSent();
    Model::setConnectionResolver($this->originalResolver);
    Carbon::setTestNow();
});

it('shows no preparation without creating directories or activating generation', function () {
    ($this->readOnly)($this->open)->assertOk()->assertSee('Aucune préparation CII effectuée')
        ->assertSee('Préparer le document CII')->assertDontSee('Télécharger le CII validé');
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
});

it('reports no CII attempt when the journal is absent and preparation is not configured', function () {
    config(['superpdp_preparation.sandbox_client_id' => null]);
    ($this->readOnly)($this->open)->assertOk()->assertSee('Aucune préparation CII effectuée')
        ->assertDontSee('Préparation interrompue ou résultat incertain')
        ->assertDontSee('Date indisponible')->assertDontSee('Dernier état enregistré')
        ->assertDontSee('Télécharger le CII validé');
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
});

it('shows running or expired operations without changing their persistent state', function (bool $expired) {
    $reservation = $this->registry->reserve($this->actor, $this->invoice, 'api');
    if ($expired) {
        Carbon::setTestNow(now()->addMinutes(16));
    }
    ($this->readOnly)($this->open)->assertOk()->assertSee($expired
        ? 'Préparation interrompue ou résultat incertain, à examiner.' : 'Préparation en cours.');
    $journal = new CiiPreparationJournal(app(PreparedCiiArchive::class)->reservationDirectory(false));
    expect($journal->read($reservation['claim']->binding())['state'])->toBe('running');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertNotFound();
})->with([false, true]);

it('shows verified document states and privately downloads exact bytes without renewing proofs', function (string $kind, string $validation, string $label, string $suffix) {
    $reservation = ($this->prepareFixture)($kind, $validation);
    ($this->readOnly)($this->open)->assertOk()->assertSee($label)->assertSee('14/11/2026 13:00:00')
        ->assertSee('Préparation documentaire locale')->assertSee('Vérification de la destination électronique')
        ->assertDontSee($reservation['directory'])->assertDontSee('invoice-cii/v1/');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="facture-99-cii-'.$suffix.'.xml"')
        ->assertContent($this->xml);
})->with([
    ['validated', 'passed', 'CII produit et validation réussie.', 'valide'],
    ['pending', 'failed', 'CII produit mais validation échouée.', 'non-valide'],
    ['pending', 'unavailable', 'Validation indisponible.', 'non-valide'],
    ['simulations', 'passed_simulation', 'Document de simulation uniquement', 'simulation'],
]);

it('keeps uncertain preparations without document in review and prevents download', function () {
    $reservation = $this->registry->reserve($this->actor, $this->invoice, 'api');
    $this->registry->uncertain($this->actor, $this->invoice, $reservation['claim']);
    ($this->readOnly)($this->open)->assertOk()->assertSee('Préparation interrompue ou résultat incertain, à examiner.');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertNotFound();
});

it('refuses missing altered or malformed private results without quarantine writes or repair', function (string $case) {
    $reservation = ($this->prepareFixture)();
    if ($case === 'missing archive') {
        unlink($reservation['directory'].'/document.xml');
    } elseif ($case === 'altered archive') {
        file_put_contents($reservation['directory'].'/document.xml', 'PRIVATE-REMOTE-SECRET');
    } elseif ($case === 'altered manifest') {
        file_put_contents($reservation['directory'].'/manifest.json', '{}');
    } else {
        $path = app(PreparedCiiArchive::class)->reservationDirectory(false).DIRECTORY_SEPARATOR.$reservation['reservation_id'].'.jsonl';
        file_put_contents($path, '{"truncated":');
    }
    ($this->readOnly)($this->open)->assertOk()->assertSee('Préparation interrompue ou résultat incertain, à examiner.')
        ->assertDontSee('Télécharger le CII validé')->assertDontSee('PRIVATE-REMOTE-SECRET');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertStatus(409)
        ->assertDontSee('PRIVATE-REMOTE-SECRET');
    if ($case !== 'broken journal') {
        $journal = new CiiPreparationJournal(app(PreparedCiiArchive::class)->reservationDirectory(false));
        expect($journal->read($reservation['claim']->binding())['state'])->toBe('completed');
    }
})->with(['missing archive', 'altered archive', 'altered manifest', 'broken journal']);

it('never automatically promotes a previously quarantined result', function () {
    $reservation = ($this->prepareFixture)();
    $journal = new CiiPreparationJournal(app(PreparedCiiArchive::class)->reservationDirectory(false));
    $journal->quarantine($reservation['claim']->binding(), now()->timestamp);
    ($this->readOnly)($this->open)->assertOk()->assertSee('Préparation interrompue ou résultat incertain, à examiner.')
        ->assertDontSee('Télécharger le CII validé');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertNotFound();
});

it('protects page and document against foreign users guests and drafts', function (string $case) {
    $reservation = ($this->prepareFixture)();
    if ($case === 'guest') {
        auth()->forgetGuards();
        ($this->readOnly)($this->open)->assertRedirect(route('login'));
        ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertRedirect(route('login'));

        return;
    }
    if ($case === 'foreign user') {
        $this->actingAs(User::findOrFail(2));
    } else {
        $this->memory->rows['invoices'][99]['document_status'] = 'draft';
        $this->beforeRows = $this->memory->rows;
    }
    $status = $case === 'foreign user' ? 403 : 404;
    ($this->readOnly)($this->open)->assertStatus($status)->assertDontSee('Acheteur figé');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertStatus($status);
})->with(['guest', 'foreign user', 'draft']);

it('refuses substituted reservation invoice company or provider contexts', function (string $case) {
    $reservation = ($this->prepareFixture)();
    $id = $reservation['reservation_id'];
    if ($case === 'reservation') {
        $id = str_repeat('f', 64);
    } elseif ($case === 'company') {
        $this->memory->rows['companies'][1]['id'] = 2;
    } elseif ($case === 'provider') {
        $this->memory->rows['superpdp_connections'][1]['superpdp_company_id'] = 456;
    } else {
        $this->memory->rows['invoices'][99]['invoice_number'] = 'OTHER';
    }
    $this->beforeRows = $this->memory->rows;
    ($this->readOnly)(fn () => ($this->download)($id))->assertStatus($case === 'company' ? 409 : 403);
})->with(['reservation', 'company', 'provider', 'invoice']);

it('keeps production disabled and never accepts a download path or preparation override', function () {
    $reservation = ($this->prepareFixture)();
    ($this->readOnly)(fn () => ($this->open)(['reservation' => str_repeat('f', 64), 'path' => 'PRIVATE-INJECTED-PATH',
        'produce' => 1, 'live_enabled' => 1]))->assertOk()->assertDontSee('PRIVATE-INJECTED-PATH');
    $this->app->instance('env', 'production');
    ($this->readOnly)($this->open)->assertOk()->assertDontSee('Télécharger le CII validé');
    ($this->readOnly)(fn () => ($this->download)($reservation['reservation_id']))->assertStatus(409);
});

it('never enables the preparation button or creates a generation POST', function () {
    ($this->prepareFixture)();
    $response = ($this->readOnly)($this->open)->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $x = new DOMXPath($dom);
    $button = $x->query('//section[@aria-labelledby="cii-document-title"]//button')->item(0);
    expect($button->hasAttribute('disabled'))->toBeTrue()->and($button->getAttribute('type'))->toBe('button')
        ->and($x->query('//section[@aria-labelledby="cii-document-title"]//form')->length)->toBe(0);
    ($this->readOnly)(fn () => $this->post(route('invoices.cii-download', ['invoice' => 99, 'reservation' => str_repeat('a', 64)])))
        ->assertStatus(405);
});
