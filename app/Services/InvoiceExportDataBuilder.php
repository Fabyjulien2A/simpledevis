<?php

namespace App\Services;

use App\Models\Invoice;
use InvalidArgumentException;

class InvoiceExportDataBuilder
{
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing('client', 'items', 'quote', 'payments', 'user.company');

        return array_merge([
            'invoice_number' => $invoice->invoice_number,
            'date' => $invoice->date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'status' => $invoice->status,
        ], $this->partyData($invoice), [
            'items' => $invoice->items->map(function ($item) {
                return [
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price_ht' => (float) $item->unit_price_ht,
                    'tva_rate' => (float) $item->tva_rate,
                    'line_total_ht' => (float) $item->line_total_ht,
                ];
            })->values()->all(),

            'totals' => [
                'subtotal_ht' => (float) $invoice->subtotal_ht,
                'total_tva' => (float) $invoice->total_tva,
                'total_ttc' => (float) $invoice->total_ttc,
                'amount_paid' => (float) $invoice->amount_paid_calculated,
                'remaining_amount' => (float) $invoice->remaining_amount,
            ],
        ]);
    }

    /**
     * Lecture électronique distincte de l'export historique en floats.
     * Relire le stockage : des relations déjà chargées ne prouvent pas l'absence de règlement.
     * L'autorisation d'accès relève de l'appelant. Aucune écriture ni qualification fiscale ici.
     */
    public function buildElectronic(Invoice $invoice): array
    {
        if (! $invoice->exists) {
            throw new InvalidArgumentException('La préparation électronique nécessite une facture enregistrée.');
        }

        $stored = $invoice->newQuery()->with([
            'client', 'user.company',
            'items' => fn ($query) => $query->orderBy('id'),
            'payments' => fn ($query) => $query->orderBy('id'),
            'creditNotes' => fn ($query) => $query->where('status', 'issued')->orderBy('id'),
        ])->findOrFail($invoice->getKey());

        return array_merge($this->partyData($stored), [
            'invoice_number' => $stored->invoice_number,
            'date' => $stored->date?->format('Y-m-d'),
            'due_date' => $stored->due_date?->format('Y-m-d'),
            'document_status' => $stored->document_status,
            'calculation_version' => $stored->calculation_version,
            'status' => $stored->status,
            'amount_paid' => $stored->amount_paid,
            'payments' => $stored->payments->modelKeys(),
            'issued_credit_notes' => $stored->creditNotes->modelKeys(),
            'items' => $stored->items->map(fn ($item) => $item->only([
                'id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht',
                'vat_category', 'unit_code', 'exemption_reason_code', 'exemption_reason',
            ]))->all(),
            'totals' => $stored->only(['subtotal_ht', 'total_tva', 'total_ttc']),
        ]);
    }

    private function partyData(Invoice $invoice): array
    {
        $company = $invoice->user->company;

        return [
            'seller' => [
                'name' => $company?->company_name ?? $company?->name ?? $invoice->user->name,
                'address' => $company?->address,
                'postal_code' => $company?->postal_code,
                'city' => $company?->city,
                'email' => $company?->email,
                'phone' => $company?->phone,
                'siret' => $company?->siret,
                'vat_number' => $company?->vat_number,
                'country_code' => 'FR',
                'legal_status' => $company?->legal_status,
                'iban' => $company?->iban,
                'bic' => $company?->bic,
                'payment_terms' => $company?->payment_terms,

            ],

            'buyer' => [
                'client_type' => $invoice->client->client_type,
                'full_name' => $invoice->client->full_name,
                'company_name' => $invoice->client->company_name,
                'siret' => $invoice->client->siret,
                'vat_number' => $invoice->client->vat_number,
                'country_code' => $invoice->client->country_code,
                'address' => $invoice->client->address,
                'postal_code' => $invoice->client->postal_code,
                'city' => $invoice->client->city,
                'email' => $invoice->client->email,
                'phone' => $invoice->client->phone,
            ],

        ];
    }
}
