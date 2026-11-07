<?php

namespace App\Services;

use App\Models\Invoice;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Données monétaires et fiscales de lecture, sans rendu, qualification fiscale
 * implicite ni écriture. Les chaînes retournées ne sont pas localisées.
 */
class InvoicePdfDataBuilder
{
    public function __construct(private readonly InvoiceFiscalDataPreparer $fiscalPreparation)
    {
    }

    /**
     * @return array{invoice_id: int, invoice_number: ?string, calculation_version: ?string,
     * document_status: string, date: ?string, due_date: ?string, items: array,
     * vat_breakdowns: ?array, totals: array<string, string>, payments: array, issued_credit_notes: array}
     */
    public function build(Invoice $invoice): array
    {
        if (! $invoice->exists) {
            throw new InvalidArgumentException('La préparation nécessite une facture enregistrée.');
        }

        // Ne pas réutiliser des attributs ou relations potentiellement obsolètes.
        // L'autorisation d'accès relève du parcours appelant.
        $invoice = $invoice->newQuery()->with([
            'items' => fn ($query) => $query->orderBy('id'),
            'payments' => fn ($query) => $query->orderBy('id'),
            'creditNotes' => fn ($query) => $query->where('status', 'issued')->orderBy('id'),
        ])->findOrFail($invoice->getKey());

        $items = $invoice->items->map(fn ($line) => $line->only([
            'id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht',
            'vat_category', 'unit_code', 'exemption_reason_code', 'exemption_reason',
        ]))->all();
        $totals = $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc']);
        $breakdowns = null;

        // Lecture historique conservée : aucune qualification fiscale implicite.
        if ($invoice->calculation_version !== null) {
            $prepared = $this->fiscalPreparation->prepare($items, $totals, $invoice->calculation_version);
            $items = $prepared['items'];
            $totals = $prepared['totals'];
            $breakdowns = $prepared['vat_breakdowns'];
        }

        $paid = BigDecimal::of('0.00');
        $payments = [];
        foreach ($invoice->payments as $payment) {
            $paid = $paid->plus($payment->amount);
            $payments[] = [
                'id' => $payment->id, 'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->format('Y-m-d'), 'method' => $payment->method,
            ];
        }

        $credits = BigDecimal::of('0.00');
        $creditNotes = [];
        foreach ($invoice->creditNotes as $creditNote) {
            $credits = $credits->plus($creditNote->total_ttc);
            $creditNotes[] = [
                'id' => $creditNote->id, 'credit_note_number' => $creditNote->credit_note_number,
                'total_ttc' => $creditNote->total_ttc,
            ];
        }

        // Même règle métier que remaining_amount : max(0, TTC - avoirs),
        // puis max(0, net - paiements). Ne jamais remplacer le TTC de la facture.
        $net = BigDecimal::max('0.00', BigDecimal::of($totals['total_ttc'])->minus($credits));
        $remaining = BigDecimal::max('0.00', $net->minus($paid));
        $totals['amount_paid'] = (string) $paid->toScale(2);
        $totals['issued_credit_total'] = (string) $credits->toScale(2);
        $totals['remaining_amount'] = (string) $remaining->toScale(2);

        return [
            'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
            'calculation_version' => $invoice->calculation_version,
            'document_status' => $invoice->document_status,
            'date' => $invoice->date?->format('Y-m-d'), 'due_date' => $invoice->due_date?->format('Y-m-d'),
            'items' => $items, 'vat_breakdowns' => $breakdowns, 'totals' => $totals,
            'payments' => $payments, 'issued_credit_notes' => $creditNotes,
        ];
    }
}
