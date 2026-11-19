<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use DateTimeImmutable;
use InvalidArgumentException;

/** Complete fictional preparation data. No Invoice model, SQL, numbering or issuance. */
final readonly class SandboxInvoiceScenario
{
    private function __construct(private array $data, private array $frenchContext) {}

    public static function forContext(SandboxEmissionContext $context, Company $company, string $date, string $dueDate): self
    {
        $parties = SandboxCiiProfile::forContext($context, $company)->toArray();
        foreach ([$date, $dueDate] as $value) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Date explicite valide requise pour le scénario sandbox.');
            }
        }
        if ($dueDate < $date) {
            throw new InvalidArgumentException('Échéance antérieure à la date du scénario sandbox.');
        }

        return new self([
            'company_id' => $company->getKey(), 'user_id' => auth()->id(),
            // A fixture label, never allocated from the real invoice sequence.
            'invoice_number' => 'SANDBOX-PREPARATION-BQ-TRICATEL',
            'date' => $date, 'due_date' => $dueDate, 'document_status' => 'issued',
            'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
            'payments' => [], 'issued_credit_notes' => [], 'company_vat_regime' => 'subject_to_vat',
            // Deliberately fictional postal addresses, not verified company addresses.
            'seller' => ['name' => $parties['seller']['name'], 'siret' => null,
                'address' => '1 rue Fictive', 'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR'],
            'buyer' => ['company_name' => $parties['buyer']['name'], 'client_type' => 'professional', 'siret' => null,
                'address' => '2 rue Fictive', 'postal_code' => '69001', 'city' => 'Lyon', 'country_code' => 'FR'],
            'items' => [[
                'description' => 'Prestation fictive sandbox — aucune opération commerciale',
                'quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00',
                'tva_rate' => '20.00', 'vat_category' => 'S', 'unit_code' => 'C62',
                'exemption_reason_code' => null, 'exemption_reason' => null,
            ]],
            'totals' => ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'],
        ], [
            'business_process_type' => 'S1',
            'notes' => [
                ['subject_code' => 'PMT', 'note' => 'Indemnité forfaitaire de recouvrement : 40 EUR — scénario fictif sandbox.'],
                ['subject_code' => 'PMD', 'note' => 'Pénalités de retard : 10 % — scénario fictif sandbox.'],
                ['subject_code' => 'AAB', 'note' => 'Aucun escompte pour paiement anticipé — scénario fictif sandbox.'],
            ],
        ]);
    }

    public function data(): array
    {
        return $this->data;
    }

    public function frenchContext(): array
    {
        return $this->frenchContext;
    }
}
