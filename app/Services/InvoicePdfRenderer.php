<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\FrenchDecimalFormatter;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfRenderer
{
    public function __construct(
        private readonly InvoicePdfDataBuilder $dataBuilder,
        private readonly FrenchDecimalFormatter $decimalFormatter,
    ) {
    }

    /**
     * L'appelant autorise l'accès. Aucune dépendance à une session authentifiée.
     * Le même contexte préparé sert à la pièce jointe et au corps de l'e-mail.
     *
     * @return array{content: string, filename: string, mime: string, invoice: Invoice, company: mixed, pdfData: array}
     */
    public function generate(Invoice $invoice): array
    {
        $invoice = $invoice->newQuery()->with('client', 'quote', 'user.company')->findOrFail($invoice->getKey());
        $pdfData = $this->dataBuilder->build($invoice);
        $company = $invoice->user->company;
        $decimalFormatter = $this->decimalFormatter;

        $content = Pdf::loadView('invoices.pdf', compact('invoice', 'company', 'pdfData', 'decimalFormatter'))->output();

        return [
            'content' => $content,
            'filename' => 'facture-' . $pdfData['invoice_number'] . '.pdf',
            'mime' => 'application/pdf',
            'invoice' => $invoice,
            'company' => $company,
            'pdfData' => $pdfData,
        ];
    }
}
