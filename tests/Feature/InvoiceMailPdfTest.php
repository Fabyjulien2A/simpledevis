<?php

use App\Http\Controllers\InvoiceController;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

function invoiceMailPdfText(string $content): string
{
    return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($content)->getText());
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    Mail::purge();
    $this->transport = Mail::mailer()->getSymfonyTransport();
    $this->user = User::factory()->create(['name' => 'Artisan Propriétaire']);
    $this->user->company()->create(['company_name' => 'Atelier Français', 'email' => 'artisan@example.test']);
    $this->client = $this->user->clients()->create(['first_name' => 'Marie', 'last_name' => 'Dupont', 'email' => 'marie@example.test']);
    $this->actingAs($this->user);
    $this->line = [
        'description' => 'Réparation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
        'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
    ];
    $this->payload = [
        'french_business_process_type' => 'S1',
        'action' => 'draft', 'client_id' => $this->client->id,
        'date' => '2026-11-05', 'due_date' => '2026-12-05', 'items' => [$this->line],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

it('sends matching downloaded and attached PDF business content synchronously', function (string $case) {
    if ($case === 'mixed') {
        $tiny = array_replace($this->line, ['quantity' => '1', 'unit_price_ht' => '0.03', 'unit_code' => 'C62']);
        $this->payload['items'] = [$tiny, $tiny, $tiny,
            array_replace($this->line, ['tva_rate' => '10']),
            array_replace($this->line, ['tva_rate' => '5.5', 'unit_code' => 'MTR']),
            array_replace($this->line, ['tva_rate' => '2.1']),
        ];
    } elseif (in_array($case, ['franchise', 'exempt', 'zero_rated'], true)) {
        $this->payload['items'][0] = array_replace($this->line, ['tax_treatment' => $case, 'tva_rate' => '0']);
        if ($case === 'exempt') {
            $this->payload['items'][0]['exemption_reason_code'] = 'VATEX-EU-132';
            $this->payload['items'][0]['exemption_reason'] = 'Justification conservée';
        }
    }
    if ($case === 'historical') {
        $this->invoice->update(['calculation_version' => null, 'subtotal_ht' => '7.00', 'total_tva' => '0.49', 'total_ttc' => '7.49']);
        $this->invoice->items()->sole()->update(['vat_category' => null, 'unit_code' => null, 'tva_rate' => '7']);
    } else {
        $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
        $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    }
    Queue::fake();
    $download = $this->get(route('invoices.pdf', $this->invoice))->assertOk();
    $this->post(route('invoices.sendEmail', $this->invoice))->assertRedirect(route('invoices.show', $this->invoice))->assertSessionHas('success');
    expect($this->transport->messages())->toHaveCount(1);
    $message = $this->transport->messages()->sole()->getOriginalMessage();
    $attachment = $message->getAttachments()[0];
    expect($message->getAttachments())->toHaveCount(1)
        ->and($message->getTo()[0]->getAddress())->toBe('marie@example.test')
        ->and($message->getSubject())->toBe('Votre facture '.$this->invoice->fresh()->invoice_number)
        ->and($attachment->getFilename())->toBe('facture-'.$this->invoice->fresh()->invoice_number.'.pdf')
        ->and($attachment->getMediaType().'/'.$attachment->getMediaSubtype())->toBe('application/pdf')
        ->and($attachment->getBody())->toStartWith('%PDF-');
    $text = invoiceMailPdfText($attachment->getBody());
    expect($text)->toBe(invoiceMailPdfText($download->getContent()));
    match ($case) {
        'mixed' => expect($text)->toContain('0,02', 'Mètre (MTR)', 'Unité (C62)', '5,50', '2,10'),
        'franchise' => expect($text)->toContain('Franchise en base (E)', 'VATEX-FR-FRANCHISE'),
        'exempt' => expect($text)->toContain('Autre exonération (E)', 'Justification conservée', 'VATEX-EU-132'),
        'zero_rated' => expect($text)->toContain('Taux zéro (Z)'),
        'historical' => expect($text)->toContain('7,49', 'TVA totale')->not->toContain('VENTILATION DE TVA'),
        default => expect($text)->toContain('Heure (HUR)', 'TVA applicable (S)'),
    };
    expect($message->getHtmlBody())->toContain('Bonjour Marie Dupont', 'Nous vous remercions pour votre confiance.', 'Cordialement,');
    expect(new InvoiceMail($this->invoice))->not->toBeInstanceOf(ShouldQueue::class);
    Queue::assertNothingPushed();
})->with(['standard', 'mixed', 'franchise', 'exempt', 'zero_rated', 'historical']);

it('uses exact prepared balances in the email body including issued credits', function (array $payments, array $credits, string $remaining, bool $settled) {
    $this->invoice->update(['amount_paid' => '99.99']);
    foreach ($payments as $amount) {
        $this->invoice->payments()->create(['amount' => $amount, 'paid_at' => '2026-11-05']);
    }
    foreach ($credits as $index => [$amount, $status]) {
        $this->invoice->creditNotes()->create([
            'user_id' => $this->user->id, 'credit_note_number' => 'AV-'.$index, 'date' => '2026-11-05',
            'status' => $status, 'reason' => 'Annulation', 'subtotal_ht' => $amount, 'total_tva' => '0.00', 'total_ttc' => $amount,
        ]);
    }
    $this->post(route('invoices.sendEmail', $this->invoice))->assertSessionHas('success');
    $message = $this->transport->messages()->sole()->getOriginalMessage();
    $html = $message->getHtmlBody();
    expect($html)->toContain('72,00 €');
    if ($settled) {
        expect($html)->toContain('Cette facture est entièrement réglée.')->not->toContain('Le montant restant dû');
    } else {
        expect($html)->toContain('Le montant restant dû', $remaining.' €')->not->toContain('Cette facture est entièrement réglée.');
    }
    expect(invoiceMailPdfText($message->getAttachments()[0]->getBody()))->toContain($remaining);
})->with([
    [['0.10', '0.20'], [], '71,70', false],
    [['0.10', '0.20'], [['10.00', 'issued'], ['50.00', 'draft']], '61,70', false],
    [['72.00'], [], '0,00', true],
    [[], [['80.00', 'issued']], '0,00', true],
]);

it('refuses inconsistent invoices before rendering and before reaching the transport', function (string $field) {
    if ($field === 'line_total_ht') {
        $this->invoice->items()->sole()->update([$field => '60.01']);
    } else {
        $this->invoice->update([$field => '0.01']);
    }
    $before = $this->invoice->fresh()->getRawOriginal();
    Pdf::shouldReceive('loadView')->never();
    $this->postJson(route('invoices.sendEmail', $this->invoice))->assertUnprocessable()
        ->assertJsonValidationErrors($field === 'line_total_ht' ? 'items.0.line_total_ht' : $field);
    expect($this->transport->messages())->toHaveCount(0)
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
})->with(['line_total_ht', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('refuses other users and missing email addresses before PDF generation', function (string $case) {
    $renderer = $this->mock(InvoicePdfRenderer::class);
    $renderer->shouldNotReceive('generate');
    if ($case === 'other user') {
        $this->actingAs(User::factory()->create());
        $this->post(route('invoices.sendEmail', $this->invoice))->assertForbidden();
    } elseif ($case === 'guest') {
        auth()->logout();
        $this->post(route('invoices.sendEmail', $this->invoice))->assertRedirect(route('login'));
    } else {
        $this->client->update(['email' => null]);
        $this->post(route('invoices.sendEmail', $this->invoice))->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('error', 'Ce client n’a pas d’adresse e-mail.');
    }
    expect($this->transport->messages())->toHaveCount(0);
})->with(['other user', 'guest', 'missing email']);

it('rechecks a stale owner before selecting the email recipient', function () {
    Invoice::whereKey($this->invoice->id)->update(['user_id' => User::factory()->create()->id]);
    $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('generate');
    try {
        app(InvoiceController::class)->sendByEmail($this->invoice);
        $this->fail('The fresh owner must be checked.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect($this->transport->messages())->toHaveCount(0);
});

it('uses the current invoice client for the recipient rather than a stale relation', function () {
    $this->invoice->load('client');
    $newClient = $this->user->clients()->create(['first_name' => 'Paul', 'last_name' => 'Martin', 'email' => 'paul@example.test']);
    Invoice::whereKey($this->invoice->id)->update(['client_id' => $newClient->id]);
    app(InvoiceController::class)->sendByEmail($this->invoice);
    $message = $this->transport->messages()->sole()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('paul@example.test')
        ->and($message->getHtmlBody())->toContain('Bonjour Paul Martin');
});

it('generates and sends without an authenticated session using the invoice owner', function () {
    // Sans entreprise : exercer réellement le repli du nom dans la vue PDF.
    $this->user->company()->delete();
    auth()->logout();
    $document = app(InvoicePdfRenderer::class)->generate($this->invoice);
    expect(invoiceMailPdfText($document['content']))->toContain('Artisan Propriétaire');
    Mail::to('marie@example.test')->send(new InvoiceMail($this->invoice));
    $message = $this->transport->messages()->sole()->getOriginalMessage();
    expect(invoiceMailPdfText($message->getAttachments()[0]->getBody()))->toBe(invoiceMailPdfText($document['content']))
        ->and($message->getHtmlBody())->toContain('Artisan Propriétaire');
});

it('preserves the alternative email action route', function () {
    $this->post(route('invoices.action', $this->invoice), ['action' => 'email'])->assertRedirect(route('invoices.show', $this->invoice))->assertSessionHas('success');
    expect($this->transport->messages())->toHaveCount(1);
});

it('does not modify invoice lines payments or credits when generating and sending', function () {
    $snapshot = fn () => collect(['invoices', 'invoice_items', 'payments', 'credit_notes', 'invoice_counters'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->get(route('invoices.pdf', $this->invoice))->assertOk();
    $this->post(route('invoices.sendEmail', $this->invoice))->assertSessionHas('success');
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query)))->toStartWith('select');
    }
    expect($snapshot())->toBe($before);
});
