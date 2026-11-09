<?php

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FacturXService;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePdfDataBuilder;
use App\Services\InvoicePdfPartyData;
use App\Services\InvoicePdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

function snapshotPdfText(string $content): string
{
    return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($content)->getText());
}

function storePdfSnapshot(Invoice $invoice, array $seller, array $buyer): void
{
    DB::table('invoices')->where('id', $invoice->id)->update([
        'party_snapshot' => json_encode(InvoicePartySnapshot::capture($seller, $buyer), JSON_THROW_ON_ERROR),
    ]);
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    Mail::purge();
    $this->transport = Mail::mailer()->getSymfonyTransport();
    $this->user = User::factory()->create(['name' => 'CURRENT USER']);
    $this->company = $this->user->company()->create([
        'company_name' => 'Vendeur historique', 'legal_status' => 'SARL historique',
        'address' => '12 rue Historique', 'postal_code' => '69002', 'city' => 'Lyon',
        'email' => 'historical-seller@example.test', 'phone' => '0102030405',
        'siret' => '73282932000074', 'vat_number' => 'FR44732829320',
        'iban' => 'FR761234567890', 'bic' => 'HISTFRXX',
        'payment_terms' => 'Paiement historique à 45 jours.',
        'invoice_early_payment_discount_text' => 'Escompte historique de 2 pour cent.',
        'invoice_late_payment_penalties_text' => 'Pénalités historiques au taux convenu.',
        'invoice_recovery_indemnity_text' => 'Indemnité historique convenue.',
    ]);
    $this->client = $this->user->clients()->create([
        'first_name' => 'Marie', 'last_name' => 'Historique', 'company_name' => 'Acheteur historique',
        'address' => '34 rue Ancienne', 'postal_code' => '75001', 'city' => 'Paris',
        'email' => 'historical-buyer@example.test', 'phone' => '0607080910',
    ]);
    $this->actingAs($this->user)->post(route('invoices.store'), [
        'french_business_process_type' => 'S1', 'action' => 'issue', 'client_id' => $this->client->id,
        'date' => '2026-11-09', 'due_date' => '2026-12-24',
        'items' => [['description' => 'Prestation conservée', 'quantity' => '1.5', 'unit_price_ht' => '40',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->company->update(collect($this->company->getAttributes())->except(['id', 'user_id', 'created_at', 'updated_at', 'quote_validity'])
        ->map(fn () => 'CURRENT SELLER')->all());
    $this->client->update(['first_name' => 'CURRENT', 'last_name' => 'BUYER', 'company_name' => 'CURRENT BUYER',
        'address' => 'CURRENT ADDRESS', 'postal_code' => '99999', 'city' => 'CURRENT CITY',
        'email' => 'current-buyer@example.test', 'phone' => 'CURRENT PHONE']);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('uses only captured parties and legal texts in both downloaded and attached PDFs without changing monetary data', function () {
    $this->invoice->payments()->create(['amount' => '10.20', 'paid_at' => '2026-11-09']);
    $this->invoice->creditNotes()->create(['user_id' => $this->user->id, 'date' => '2026-11-09',
        'reason' => 'Avoir', 'status' => 'issued', 'subtotal_ht' => '5', 'total_tva' => '0', 'total_ttc' => '5']);
    $tables = fn () => collect(['invoices', 'invoice_items', 'payments', 'credit_notes', 'invoice_counters'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $tables();
    $amounts = app(InvoicePdfDataBuilder::class)->build($this->invoice);
    $response = $this->get(route('invoices.pdf', $this->invoice))->assertOk();
    $text = snapshotPdfText($response->getContent());
    expect($text)->toContain('Vendeur historique', 'SARL historique', 'Marie Historique', 'Acheteur historique',
        '12 rue Historique', '34 rue Ancienne', 'historical-seller@example.test', 'historical-buyer@example.test',
        '73282932000074', 'FR44732829320', 'FR761234567890', 'HISTFRXX',
        'Paiement historique à 45 jours.', 'Escompte historique de 2 pour cent.',
        'Pénalités historiques au taux convenu.', 'Indemnité historique convenue.',
        '60,00', '12,00', '72,00', '10,20', '56,80', 'Prestation conservée', 'Non renseigné')
        ->not->toContain('CURRENT', 'Néant.', '10% du montant TTC.', '40 € dus', 'Paiement à réception de facture.');

    $this->post(route('invoices.sendEmail', $this->invoice))->assertSessionHas('success');
    $message = $this->transport->messages()->sole()->getOriginalMessage();
    // Le destinataire actuel est préservé : seule la projection dans le PDF change.
    expect($message->getTo()[0]->getAddress())->toBe('current-buyer@example.test')
        ->and(snapshotPdfText($message->getAttachments()[0]->getBody()))->toBe($text)
        ->and(app(InvoicePdfDataBuilder::class)->build($this->invoice))->toBe($amounts)
        ->and($tables())->toBe($before);
    if ($directory = getenv('SIMPLEDEVIS_PDF_PREVIEW_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/snapshot-preview.pdf', $response->getContent());
    }
});

it('does not fill empty or partial historical parties with current profiles or fixed terms', function (array $seller, array $buyer) {
    storePdfSnapshot($this->invoice, $seller, $buyer);
    $text = snapshotPdfText(app(InvoicePdfRenderer::class)->generate($this->invoice)['content']);
    expect($text)->not->toContain('CURRENT', 'Vendeur historique', 'Marie Historique', 'Client sans nom',
        'Paiement à réception de facture.', '10% du montant TTC.', '40 € dus', 'Néant.');
})->with([
    [[], []],
    [['company_name' => null, 'payment_terms' => '', 'invoice_late_payment_penalties_text' => null], ['first_name' => 'Luc']],
]);

it('reconstructs the buyer name only from captured fields', function (array $buyer, string $name) {
    storePdfSnapshot($this->invoice, [], $buyer);
    $parties = app(InvoicePdfPartyData::class)->build($this->invoice);
    expect($parties['buyer']['full_name'])->toBe($name);
    $html = view('invoices.pdf', ['invoice' => $this->invoice, 'company' => $this->company])->render();
    expect($html)->toContain(e($name))->not->toContain('CURRENT');
})->with([
    [['first_name' => 'Paul', 'last_name' => 'Ancien'], 'Paul Ancien'],
    [['last_name' => 'Ancien'], 'Ancien'],
    [['first_name' => 'Paul'], 'Paul'],
    [['company_name' => 'Société ancienne'], 'Société ancienne'],
    [['full_name' => 'Nom capturé', 'first_name' => 'Paul', 'last_name' => 'Ancien'], 'Nom capturé'],
    [['full_name' => ' ', 'first_name' => 'Paul', 'company_name' => 'Société ancienne'], 'Paul'],
]);

it('resolves historical seller aliases without consulting live profiles', function () {
    storePdfSnapshot($this->invoice, ['name' => 'Ancien nom', 'tva_number' => 'ANCIENNE-TVA'], []);
    $text = snapshotPdfText(app(InvoicePdfRenderer::class)->generate($this->invoice)['content']);
    expect($text)->toContain('Ancien nom', 'ANCIENNE-TVA')->not->toContain('CURRENT');
    storePdfSnapshot($this->invoice, ['user_name' => 'Ancien utilisateur'], []);
    expect(snapshotPdfText(app(InvoicePdfRenderer::class)->generate($this->invoice)['content']))
        ->toContain('Ancien utilisateur')->not->toContain('CURRENT');
});

it('keeps the SQL NULL legacy party and fixed-text path', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]);
    $text = snapshotPdfText(app(InvoicePdfRenderer::class)->generate($this->invoice)['content']);
    expect($text)->toContain('CURRENT SELLER', 'CURRENT BUYER', '10% du montant TTC.', '40 € dus', 'Néant.');
});

it('refuses invalid snapshots before rendering or handing an email to the transport', function (string $json, string $target) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $json]);
    Pdf::shouldReceive('loadView')->never();
    $response = $target === 'download'
        ? $this->getJson(route('invoices.pdf', $this->invoice))
        : $this->postJson(route('invoices.sendEmail', $this->invoice));
    $response->assertUnprocessable()->assertJsonValidationErrors('party_snapshot');
    expect($this->transport->messages())->toHaveCount(0);
    $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'party_snapshot' => $json]);
})->with([
    'malformed JSON' => '{', 'JSON null' => 'null', 'empty object' => '{}', 'array' => '[]',
    'unsupported version' => '{"schema_version":3,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
    'string version' => '{"schema_version":"1","seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
    'party array' => '{"schema_version":1,"seller":[],"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
    'invalid field type' => '{"schema_version":1,"seller":{"name":42},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
    'unknown field' => '{"schema_version":1,"seller":{"unknown":"x"},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
    'duplicate key' => '{"schema_version":1,"seller":{"name":"x","name":"y"},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}',
])->with(['download', 'attachment']);

it('uses stored snapshots rather than stale invoice attributes and loaded live relations', function () {
    $this->invoice->load('client', 'user.company');
    $this->invoice->party_snapshot = null;
    expect(snapshotPdfText(app(InvoicePdfRenderer::class)->generate($this->invoice)['content']))
        ->toContain('Vendeur historique', 'Marie Historique')->not->toContain('CURRENT');
});

it('projects a snapshot without querying current seller buyer or user records', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $parties = app(InvoicePdfPartyData::class)->build($this->invoice);
    expect($parties['from_snapshot'])->toBeTrue()->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('from "invoices"');
});

it('preserves the legacy User company fallback of the unprepared FacturX view', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]);
    $this->company->delete();
    $html = view('invoices.pdf', ['invoice' => $this->invoice->fresh(), 'company' => $this->user])->render();
    expect($html)->toContain('CURRENT USER', e($this->user->email), 'CURRENT BUYER', '10% du montant TTC.');
});

it('keeps the shared unprepared view used by FacturXService on the same historical party projection', function () {
    Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) {
        expect($view)->toBe('invoices.pdf')->and($data)->not->toHaveKey('pdfData');
        $html = view($view, $data)->render();
        expect($html)->toContain('Vendeur historique', 'Marie Historique', 'Paiement historique à 45 jours.', '72,00')
            ->not->toContain('CURRENT', '10% du montant TTC.');
        // Arrêt après le rendu : ne pas produire de XML ou activer de flux électronique.
        throw new RuntimeException('PDF view verified');
    });
    expect(fn () => app(FacturXService::class)->generate($this->invoice))
        ->toThrow(RuntimeException::class, 'PDF view verified');
});

it('omits an unavailable historical logo even when a current one exists', function () {
    storePdfSnapshot($this->invoice, ['logo' => 'logos/missing-historical.png'], []);
    $this->company->update(['logo' => 'logos/current.png']);
    $html = view('invoices.pdf', ['invoice' => $this->invoice, 'company' => $this->company])->render();
    expect($html)->not->toContain('<img', 'current.png');
});

it('uses an existing historical logo and omits it after deletion without substituting the current file', function () {
    $relative = 'snapshot-pdf-'.bin2hex(random_bytes(8));
    Storage::fake('public');
    $directory = Storage::disk('public')->path($relative);
    mkdir($directory, 0777, true);
    $historical = $directory.'/historical.png';
    $current = $directory.'/current.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=');
    file_put_contents($historical, $png);
    file_put_contents($current, $png);
    try {
        storePdfSnapshot($this->invoice, ['logo' => $relative.'/historical.png'], []);
        $this->company->update(['logo' => $relative.'/current.png']);
        $parties = app(InvoicePdfPartyData::class)->build($this->invoice);
        expect($parties['logo_path'])->toBe(realpath($historical));
        $html = view('invoices.pdf', ['invoice' => $this->invoice, 'company' => $this->company])->render();
        expect($html)->toContain('<img', 'historical.png')->not->toContain('current.png');
        unlink($historical);
        clearstatcache();
        expect(app(InvoicePdfPartyData::class)->build($this->invoice)['logo_path'])->toBeNull();
        $html = view('invoices.pdf', ['invoice' => $this->invoice, 'company' => $this->company])->render();
        expect($html)->not->toContain('<img', 'current.png');
    } finally {
        if (is_file($historical)) {
            unlink($historical);
        }
        unlink($current);
        rmdir($directory);
    }
});
