<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdfRenderer;
use App\Support\FrenchDecimalFormatter;
use Brick\Math\BigDecimal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public mixed $company = null;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build(InvoicePdfRenderer $renderer, FrenchDecimalFormatter $decimalFormatter)
    {
        $document = $renderer->generate($this->invoice);
        $this->invoice = $document['invoice'];
        $this->company = $document['company'];
        $pdfData = $document['pdfData'];

        return $this->subject('Votre facture ' . $pdfData['invoice_number'])
            ->view('emails.invoice')
            ->with([
                'invoice' => $this->invoice,
                'company' => $this->company,
                'pdfData' => $pdfData,
                'decimalFormatter' => $decimalFormatter,
                'hasRemainingAmount' => BigDecimal::of($pdfData['totals']['remaining_amount'])->isGreaterThan('0.00'),
            ])
            ->attachData(
                $document['content'],
                $document['filename'],
                ['mime' => $document['mime']]
            );
    }
}
