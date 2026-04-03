<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public mixed $company;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
        $this->company = $invoice->user->company;
    }

    public function build()
    {
        $this->invoice->loadMissing('client', 'items', 'quote', 'user');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $this->invoice,
            'company' => $this->company,
        ]);

        return $this->subject('Votre facture ' . $this->invoice->invoice_number)
            ->view('emails.invoice')
            ->with([
                'invoice' => $this->invoice,
                'company' => $this->company,
            ])
            ->attachData(
                $pdf->output(),
                'facture-' . $this->invoice->invoice_number . '.pdf',
                ['mime' => 'application/pdf']
            );
    }
}