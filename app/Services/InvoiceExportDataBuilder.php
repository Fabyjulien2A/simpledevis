<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\SuperPdp\FrenchSellerIdentifiers;
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
     * L'autorisation d'accès relève de l'appelant. Aucune écriture ni déduction de régime.
     */
    public function buildElectronic(Invoice $invoice): array
    {
        if (! $invoice->exists) {
            throw new InvalidArgumentException('La préparation électronique nécessite une facture enregistrée.');
        }

        $stored = $invoice->newQuery()->with([
            'items' => fn ($query) => $query->orderBy('id'),
            'payments' => fn ($query) => $query->orderBy('id'),
            'creditNotes' => fn ($query) => $query->where('status', 'issued')->orderBy('id'),
        ])->findOrFail($invoice->getKey());

        $snapshot = $stored->getRawOriginal('party_snapshot');
        if ($snapshot !== null) {
            $parties = InvoiceElectronicPartyData::resolve(['party_snapshot' => $snapshot]);
        } else {
            // Compatibilité de lecture historique uniquement ; le builder bloque cette source.
            $stored->load('client', 'user.company');
            $parties = $this->legacyElectronicParties($stored);
            $parties['party_snapshot'] = null;
        }

        return array_merge($parties, [
            'emission_profile' => $stored->emission_profile,
            'emission_context' => $stored->getRawOriginal('emission_context'),
            'french_business_process_type' => $stored->french_business_process_type,
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

    private function legacyElectronicParties(Invoice $stored): array
    {
        $parties = $this->partyData($stored);
        $company = $stored->user?->company;
        // Mapping documenté de la franchise déclarée, sans numéro de TVA dans
        // l'une ou l'autre colonne. Ne jamais attribuer un régime ou un numéro de TVA.
        if ($company?->vat_regime === 'franchise'
            && trim((string) $company->vat_number) === ''
            && trim((string) $company->tva_number) === '') {
            try {
                $parties['seller']['tax_registration_identifier'] = FrenchSellerIdentifiers::sirenFromSiret(
                    trim((string) $company->siret)
                );
            } catch (InvalidArgumentException) {
                // Garder le SIRET original pour les erreurs explicites du diagnostic/builder.
                // Aucun BT-32 n'est inventé si son extraction est impossible.
            }
        }

        return array_merge($parties, [
            // Métadonnée locale du diagnostic, distincte des champs du payload EN16931.
            'company_vat_regime' => $stored->user?->company?->vat_regime,
            // Textes persistés uniquement ; aucune reprise des PDF ou des scénarios fictifs.
            'company_invoice_mentions' => [
                'PMT' => $company?->invoice_recovery_indemnity_text,
                'PMD' => $company?->invoice_late_payment_penalties_text,
                'AAB' => $company?->invoice_early_payment_discount_text,
            ],
        ]);
    }

    private function partyData(Invoice $invoice): array
    {
        $company = $invoice->user?->company;

        return [
            'seller' => [
                'name' => $company?->company_name ?? $company?->name ?? $invoice->user?->name,
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
                'client_type' => $invoice->client?->client_type,
                'full_name' => $invoice->client?->full_name,
                'company_name' => $invoice->client?->company_name,
                'siret' => $invoice->client?->siret,
                'vat_number' => $invoice->client?->vat_number,
                'country_code' => $invoice->client?->country_code,
                'address' => $invoice->client?->address,
                'postal_code' => $invoice->client?->postal_code,
                'city' => $invoice->client?->city,
                'email' => $invoice->client?->email,
                'phone' => $invoice->client?->phone,
            ],

        ];
    }
}
