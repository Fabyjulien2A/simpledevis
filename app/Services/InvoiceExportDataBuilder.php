<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceExportDataBuilder
{
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing('client', 'items', 'quote', 'payments', 'user.company');

        $company = $invoice->user->company;

        return [
            'invoice_number' => $invoice->invoice_number,
            'date' => $invoice->date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'status' => $invoice->status,

            'seller' => [
                'name' => $company->company_name ?? $company->name ?? $invoice->user->name,
                'address' => $company->address,
                'postal_code' => $company->postal_code,
                'city' => $company->city,
                'email' => $company->email,
                'phone' => $company->phone,
                'siret' => $company->siret,
                'vat_number' => $company->vat_number,
            ],

            'buyer' => [
                'full_name' => $invoice->client->full_name,
                'company_name' => $invoice->client->company_name,
                'address' => $invoice->client->address,
                'postal_code' => $invoice->client->postal_code,
                'city' => $invoice->client->city,
                'email' => $invoice->client->email,
                'phone' => $invoice->client->phone,
            ],

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
        ];
    }
}