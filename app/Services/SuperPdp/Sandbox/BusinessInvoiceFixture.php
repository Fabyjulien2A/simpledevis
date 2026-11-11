<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalItemTranslator;
use App\Services\InvoiceLinePreparationService;
use App\Services\InvoicePartySnapshot;
use App\Services\SuperPdp\InvoiceEmissionContext;
use InvalidArgumentException;

/** Fixed fiction: business calculations and snapshots, never a stored invoice. */
final class BusinessInvoiceFixture extends InvoiceExportDataBuilder
{
    private readonly Invoice $invoice;
    private readonly array $items;

    public static function formItems(): array
    {
        return [
            ['description' => 'Prestation fictive A', 'quantity' => '2.50', 'unit_price_ht' => '40.00',
                'tva_rate' => '20.00', 'tax_treatment' => 'taxable', 'unit_code' => 'C62'],
            ['description' => 'Prestation fictive B', 'quantity' => '4.00', 'unit_price_ht' => '25.00',
                'tva_rate' => '10.00', 'tax_treatment' => 'taxable', 'unit_code' => 'C62'],
        ];
    }

    public function __construct(InvoiceLinePreparationService $lines)
    {
        $prepared = $lines->prepare((new InvoiceFiscalItemTranslator)->translate(self::formItems()));
        $this->items = $prepared['items'];
        $this->invoice = (new Invoice)->fill([
            'invoice_number' => 'SANDBOX-METIER-0001', 'date' => '2026-11-11', 'due_date' => '2026-12-11',
            'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee',
            'amount_paid' => '0.00', 'subtotal_ht' => $prepared['totals']['total_ht'],
            'total_tva' => $prepared['totals']['total_vat'], 'total_ttc' => $prepared['totals']['total_ttc'],
            'french_business_process_type' => 'S1', 'emission_profile' => InvoiceEmissionContext::PROFILE,
        ]);
        // Same pure capture contracts as finalization; no transaction, save or logo archive.
        $snapshot = InvoicePartySnapshot::capture([
            'company_name' => 'Vendeur fictif SimpleDevis', 'siret' => '00000000000000',
            'vat_number' => 'FR00000000000', 'vat_regime' => 'subject_to_vat',
            'address' => '1 rue Fictive', 'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR',
            'invoice_recovery_indemnity_text' => '40 € dus en cas de retard de paiement.',
            'invoice_late_payment_penalties_text' => '10% du montant TTC.',
            'invoice_early_payment_discount_text' => 'Néant.',
        ], [
            'client_type' => 'professional', 'company_name' => 'Acheteur fictif SimpleDevis',
            'siret' => '00000000000000', 'vat_number' => 'FR00000000000',
            'address' => '2 rue Fictive', 'postal_code' => '69001', 'city' => 'Lyon', 'country_code' => 'FR',
        ], InvoicePartySnapshot::PRIVATE_LOGO_SCHEMA_VERSION);
        $this->invoice->party_snapshot = $snapshot->jsonSerialize();
        $this->invoice->emission_context = InvoiceEmissionContext::capture(
            InvoiceEmissionContext::PROFILE,
            ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
            ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'],
            $snapshot->toArray(),
        );
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function buildElectronic(Invoice $invoice): array
    {
        if ($invoice !== $this->invoice || $invoice->exists || $invoice->getKey() !== null) {
            throw new InvalidArgumentException('Unstored fictional business invoice required.');
        }

        return [
            ...$invoice->only(['invoice_number', 'document_status', 'calculation_version', 'status', 'amount_paid',
                'french_business_process_type', 'emission_profile']),
            'party_snapshot' => $invoice->getAttributes()['party_snapshot'],
            'emission_context' => $invoice->getAttributes()['emission_context'],
            'date' => $invoice->date->format('Y-m-d'), 'due_date' => $invoice->due_date->format('Y-m-d'),
            'payments' => [], 'issued_credit_notes' => [], 'items' => $this->items,
            'totals' => $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc']),
        ];
    }
}
