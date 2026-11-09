<?php

namespace Tests\Support;

use App\Services\InvoicePartySnapshot;

class SuperPdpPayloadFixture
{
    /** Instantané fictif explicite pour les tests de préparation, jamais une reprise métier. */
    public static function partySnapshot(array $data): string
    {
        $seller = array_intersect_key($data['seller'] ?? [], array_flip([
            'company_name', 'name', 'user_name', 'legal_status', 'siret', 'vat_number', 'tva_number',
            'address', 'postal_code', 'city', 'country_code', 'email', 'phone', 'iban', 'bic', 'payment_terms', 'logo',
        ]));
        if (array_key_exists('company_vat_regime', $data)) {
            $seller['vat_regime'] = $data['company_vat_regime'];
        }
        foreach (['PMT' => 'invoice_recovery_indemnity_text', 'PMD' => 'invoice_late_payment_penalties_text', 'AAB' => 'invoice_early_payment_discount_text'] as $code => $field) {
            if (array_key_exists($code, $data['company_invoice_mentions'] ?? [])) {
                $seller[$field] = $data['company_invoice_mentions'][$code];
            }
        }

        // Sérialisation brute afin de pouvoir aussi simuler une structure invalide dans les tests.
        return json_encode([
            'schema_version' => InvoicePartySnapshot::SCHEMA_VERSION,
            'seller' => (object) $seller, 'buyer' => (object) ($data['buyer'] ?? []),
            'electronic_qualification' => ['status' => 'not_assessed'],
        ], JSON_THROW_ON_ERROR);
    }

    public static function frenchContext(): array
    {
        return [
            'business_process_type' => 'S1',
            'notes' => [
                ['subject_code' => 'PMT', 'note' => 'Mention PMT explicitement fournie pour le test.'],
                ['subject_code' => 'PMD', 'note' => 'Mention PMD explicitement fournie pour le test.'],
                ['subject_code' => 'AAB', 'note' => 'Mention AAB explicitement fournie pour le test.'],
            ],
        ];
    }

    public static function data(): array
    {
        return [
            'invoice_number' => 'FAC-2026-0001',
            'date' => '2026-11-05',
            'due_date' => '2026-12-05',
            'document_status' => 'issued',
            'calculation_version' => 'decimal-v1',
            'status' => 'non_payee',
            'amount_paid' => '0.00',
            'payments' => [],
            'issued_credit_notes' => [],
            'seller' => [
                'name' => 'Atelier Martin', 'siret' => '73282932000074', 'vat_number' => 'FR44732829320',
                'address' => '12 rue des Artisans', 'postal_code' => '75012', 'city' => 'Paris',
                'country_code' => 'FR', 'payment_terms' => 'Paiement à trente jours.',
            ],
            'buyer' => [
                'client_type' => 'individual', 'full_name' => 'Marie Dupont',
                'address' => '3 rue du Moulin', 'postal_code' => '69002', 'city' => 'Lyon', 'country_code' => 'FR',
            ],
            'items' => [[
                'id' => 42, 'description' => 'Réparation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
                'tva_rate' => '20.00', 'line_total_ht' => '60.00', 'vat_category' => 'S', 'unit_code' => 'HUR',
                'exemption_reason_code' => null, 'exemption_reason' => null,
            ]],
            'totals' => ['subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00'],
        ];
    }
}
