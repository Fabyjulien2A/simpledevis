<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use InvalidArgumentException;

/**
 * Source fixe en mémoire réservée à l'essai fictif, sans simuler une facture persistée.
 * Le vrai builder conserve ses contrôles de statut, version, règlement, fiscalité et schéma.
 */
class SandboxInvoiceFixture extends InvoiceExportDataBuilder
{
    private readonly Invoice $invoice;

    public function __construct()
    {
        $this->invoice = (new Invoice)->fill([
            'invoice_number' => 'SANDBOX-FICTIF-0001', 'date' => '2026-11-06', 'due_date' => '2026-12-06',
            'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee',
            'amount_paid' => '0.00', 'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00',
        ]);
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    /**
     * Décisions explicites réservées à cette fiction de prestation ordinaire (S1).
     * Textes repris exactement de resources/views/invoices/pdf.blade.php, sans lecture du PDF.
     * Aucun de ces textes n'est un défaut pour les factures réelles.
     */
    public function frenchContext(): array
    {
        return [
            'business_process_type' => 'S1',
            'notes' => [
                ['subject_code' => 'PMT', 'note' => '40 € dus en cas de retard de paiement.'],
                ['subject_code' => 'PMD', 'note' => '10% du montant TTC.'],
                ['subject_code' => 'AAB', 'note' => 'Néant.'],
            ],
        ];
    }

    public function buildElectronic(Invoice $invoice): array
    {
        if ($invoice !== $this->invoice || $invoice->exists || $invoice->getKey() !== null) {
            throw new InvalidArgumentException('Cette source accepte uniquement son exemple fictif non enregistré.');
        }

        return [
            ...$invoice->only(['invoice_number', 'document_status', 'calculation_version', 'status', 'amount_paid']),
            'date' => $invoice->date?->format('Y-m-d'), 'due_date' => $invoice->due_date?->format('Y-m-d'),
            'seller' => [
                'name' => 'Vendeur entièrement fictif — essai sandbox', 'siret' => '00000000000000',
                'vat_number' => 'FR00000000000', 'address' => '1 rue Fictive', 'postal_code' => '75001',
                'city' => 'Paris', 'country_code' => 'FR',
            ],
            'buyer' => [
                'client_type' => 'professional', 'company_name' => 'Acheteur entièrement fictif — essai sandbox',
                'full_name' => 'Acheteur fictif', 'siret' => '00000000000000', 'vat_number' => 'FR00000000000',
                'address' => '2 rue Fictive', 'postal_code' => '69001', 'city' => 'Lyon', 'country_code' => 'FR',
            ],
            'payments' => [], 'issued_credit_notes' => [],
            'items' => [[
                'description' => 'Prestation fictive de test — aucune opération commerciale', 'quantity' => '1.00',
                'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'tva_rate' => '20.00',
                'vat_category' => 'S', 'unit_code' => 'C62',
            ]],
            'totals' => $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc']),
        ];
    }
}
