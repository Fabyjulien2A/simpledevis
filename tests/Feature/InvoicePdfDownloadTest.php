<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfDataBuilder;
use App\Services\InvoicePdfRenderer;
use App\Support\FrenchDecimalFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

function invoiceDownloadHtml(Invoice $invoice): string
{
    return view('invoices.pdf', [
        'invoice' => $invoice->fresh()->load('client', 'quote'),
        'company' => $invoice->user->company,
        'pdfData' => app(InvoicePdfDataBuilder::class)->build($invoice),
        'decimalFormatter' => new FrenchDecimalFormatter,
    ])->render();
}

function invoiceDownloadText(string $pdf): string
{
    return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->user = User::factory()->create();
    $this->user->company()->create([
        'company_name' => 'Atelier Démonstration', 'address' => '12 rue des Artisans',
        'postal_code' => '69002', 'city' => 'Lyon', 'siret' => '73282932000074',
        'payment_terms' => 'Règlement à trente jours.',
        'invoice_late_payment_penalties_text' => '10% du montant TTC.',
        'invoice_recovery_indemnity_text' => '40 € dus en cas de retard de paiement.',
        'invoice_early_payment_discount_text' => 'Néant.',
    ]);
    $client = $this->user->clients()->create(['first_name' => 'Marie', 'last_name' => 'Dupont']);
    $this->actingAs($this->user);
    $this->line = [
        'description' => 'Réparation et fournitures', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
        'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
    ];
    $this->payload = [
        'french_business_process_type' => 'S1',
        'action' => 'issue', 'client_id' => $client->id, 'date' => '2026-11-05',
        'due_date' => '2026-12-05', 'items' => [$this->line], 'notes' => 'Note conservée.',
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

it('downloads a real PDF with exact stored amounts and preserved legal information', function () {
    $response = $this->get(route('invoices.pdf', $this->invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment;', 'facture-'.$this->invoice->invoice_number.'.pdf')
        ->and($response->getContent())->toStartWith('%PDF-');
    $text = invoiceDownloadText($response->getContent());
    expect($text)->toContain('Atelier Démonstration', 'Marie Dupont', 'TVA applicable (S)', 'Heure (HUR)',
        '60,00', '12,00', '72,00', 'VENTILATION DE TVA', 'TVA totale', 'Règlement à trente jours.',
        'Pénalités de retard', '40 €', 'Note conservée.');
});

it('renders mixed rates with correct bases and grouped VAT in HTML and PDF', function () {
    // Créer un brouillon pour modifier ses lignes par le parcours public autorisé.
    $this->payload['action'] = 'draft';
    $tiny = array_replace($this->line, ['quantity' => '1', 'unit_price_ht' => '0.03', 'unit_code' => 'C62']);
    $this->payload['items'] = [$tiny, $tiny, $tiny,
        array_replace($this->line, ['tva_rate' => '10']),
        array_replace($this->line, ['tva_rate' => '5.5', 'unit_code' => 'MTR']),
        array_replace($this->line, ['tva_rate' => '2.1']),
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->first();
    $html = invoiceDownloadHtml($invoice);
    expect($html)->toContain('Ventilation de TVA', 'Base HT', 'Montant TVA', '0,09', '0,02', '5,50', '2,10', 'Mètre (MTR)', 'Unité (C62)');
    $text = invoiceDownloadText($this->get(route('invoices.pdf', $invoice))->assertOk()->getContent());
    expect($text)->toContain('180,09', '10,58', '190,67', '0,02', '5,50', '2,10');
});

it('renders exemptions franchise and zero rating distinctly without inventing reasons', function (string $treatment, array $reason, string $label) {
    $this->payload['action'] = 'draft';
    $this->payload['items'] = [array_replace($this->line, ['tax_treatment' => $treatment, 'tva_rate' => '0'], $reason)];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->first();
    $html = invoiceDownloadHtml($invoice);
    expect($html)->toContain($label, '0,00', '60,00');
    if ($treatment === 'franchise') {
        expect($html)->toContain('VATEX-FR-FRANCHISE')->not->toContain('293 B');
    } elseif ($treatment === 'exempt') {
        expect($html)->toContain('Motif enregistré &lt;exonération&gt;', 'VATEX-EU-132')->not->toContain('Franchise en base');
    } else {
        expect($html)->not->toContain('Franchise en base', 'Autre exonération', 'Code :', 'Motif :');
    }
    expect(invoiceDownloadText($this->get(route('invoices.pdf', $invoice))->assertOk()->getContent()))->toContain($label);
})->with([
    ['franchise', [], 'Franchise en base (E)'],
    ['exempt', ['exemption_reason_code' => 'VATEX-EU-132', 'exemption_reason' => 'Motif enregistré <exonération>'], 'Autre exonération (E)'],
    ['zero_rated', [], 'Taux zéro (Z)'],
]);

it('renders historical stored amounts with total VAT and no invented breakdown', function () {
    $this->invoice->update(['calculation_version' => null, 'subtotal_ht' => '7.00', 'total_tva' => '0.49', 'total_ttc' => '7.49']);
    $this->invoice->items()->sole()->update(['vat_category' => null, 'unit_code' => null, 'tva_rate' => '7', 'line_total_ht' => '99.99']);
    $html = invoiceDownloadHtml($this->invoice);
    expect($html)->toContain('TVA totale', '7,49', '99,99')->not->toContain('Ventilation de TVA', 'TVA applicable (S)', 'Qté / unité');
    $text = invoiceDownloadText($this->get(route('invoices.pdf', $this->invoice))->assertOk()->getContent());
    expect($text)->toContain('TVA totale', '7,49', '99,99')->not->toContain('Ventilation de TVA');
});

it('shows the exact remaining balance with payments and issued credits without writes', function () {
    $this->invoice->payments()->create(['amount' => '0.10', 'paid_at' => '2026-11-05']);
    $this->invoice->payments()->create(['amount' => '0.20', 'paid_at' => '2026-11-06']);
    $this->invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001', 'date' => '2026-11-05',
        'status' => 'issued', 'reason' => 'Annulation partielle', 'subtotal_ht' => '10.00', 'total_tva' => '0.00', 'total_ttc' => '10.00',
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $response = $this->get(route('invoices.pdf', $this->invoice))->assertOk();
    expect(invoiceDownloadText($response->getContent()))->toContain('0,30', '61,70', '72,00', '05/11/2026', '06/11/2026');
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query)))->toStartWith('select');
    }
});

it('refuses inconsistent decimal amounts before invoking DomPDF', function (string $field) {
    if ($field === 'line_total_ht') {
        $this->invoice->items()->sole()->update([$field => '60.01']);
    } else {
        $this->invoice->update([$field => '0.01']);
    }
    $before = $this->invoice->fresh()->getRawOriginal();
    Pdf::shouldReceive('loadView')->never();
    $this->getJson(route('invoices.pdf', $this->invoice))->assertUnprocessable()
        ->assertJsonValidationErrors($field === 'line_total_ht' ? 'items.0.line_total_ht' : $field);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
})->with(['line_total_ht', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('checks authorization before preparing or rendering any invoice', function (bool $guest) {
    $builder = $this->mock(InvoicePdfDataBuilder::class);
    $builder->shouldNotReceive('build');
    Pdf::shouldReceive('loadView')->never();
    if ($guest) {
        auth()->logout();
        $this->get(route('invoices.pdf', $this->invoice))->assertRedirect(route('login'));
    } else {
        $this->actingAs(User::factory()->create());
        $this->get(route('invoices.pdf', $this->invoice))->assertForbidden();
    }
})->with([true, false]);

it('rechecks ownership on a stale route model before preparing or rendering', function () {
    Invoice::whereKey($this->invoice->id)->update(['user_id' => User::factory()->create()->id]);
    $renderer = $this->createMock(InvoicePdfRenderer::class);
    $renderer->expects($this->never())->method('generate');
    Pdf::shouldReceive('loadView')->never();
    try {
        app(InvoiceController::class)->pdf($this->invoice, $renderer);
        $this->fail('The fresh owner must be checked.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('keeps the shared view usable by callers not yet supplying prepared data', function () {
    $html = view('invoices.pdf', ['invoice' => $this->invoice, 'company' => $this->user->company])->render();
    expect($html)->toContain('72,00', 'TVA (20,00 %)', 'Conditions de paiement')->not->toContain('Ventilation de TVA');
});

it('renders a multipage invoice with all rows totals and fiscal reasons preserved', function () {
    $this->payload['action'] = 'draft';
    $this->payload['items'] = [];
    foreach (range(1, 16) as $index) {
        $this->payload['items'][] = array_replace($this->line, [
            'description' => "Ligne $index - Réparation et pose de fournitures pour un chantier artisanal avec une description détaillée.",
            'tva_rate' => ['20', '10', '5.5', '2.1'][$index % 4],
            'unit_code' => ['C62', 'HUR', 'MTR'][$index % 3],
        ]);
    }
    $this->payload['items'][] = array_replace($this->line, [
        'tax_treatment' => 'franchise', 'tva_rate' => '0', 'exemption_reason' => 'Justification explicitement enregistrée pour cette prestation.',
    ]);
    $this->payload['items'][] = array_replace($this->line, ['tax_treatment' => 'zero_rated', 'tva_rate' => '0']);
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->first();
    $content = $this->get(route('invoices.pdf', $invoice))->assertOk()->getContent();
    $pdf = (new Parser)->parseContent($content);
    expect(count($pdf->getPages()))->toBeGreaterThan(1);
    $text = preg_replace('/\s+/u', ' ', $pdf->getText());
    expect($text)->toContain('Ligne 1 -', 'Ligne 16 -', 'VENTILATION DE TVA', 'Franchise en base (E)', 'Taux zéro (Z)',
        'Justification explicitement enregistrée', 'Total TTC', 'Merci pour votre confiance.');
    // Aperçu facultatif limité à une base SQLite en mémoire, jamais à des données réelles.
    if ($directory = getenv('SIMPLEDEVIS_PDF_PREVIEW_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/invoice-preview.pdf', $content);
    }
});
