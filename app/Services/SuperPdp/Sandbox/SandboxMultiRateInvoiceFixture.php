<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Models\Invoice;

/** Two explicitly fictitious ordinary services; no real invoice or tax eligibility. */
final class SandboxMultiRateInvoiceFixture extends SandboxInvoiceFixture
{
    public function __construct()
    {
        parent::__construct();
        $this->invoice()->fill([
            'invoice_number' => 'SANDBOX-FICTIF-MULTITAUX-0003',
            'subtotal_ht' => '200.00', 'total_tva' => '30.00', 'total_ttc' => '230.00',
        ]);
    }

    public function buildElectronic(Invoice $invoice): array
    {
        // Preserve the identity/persistence guard of the original fictional source.
        $data = parent::buildElectronic($invoice);
        $data['seller']['name'] = 'Vendeur entièrement fictif — essai multi-taux';
        $data['buyer']['company_name'] = 'Acheteur entièrement fictif — essai multi-taux';
        $first = $data['items'][0];
        $data['items'] = [
            array_replace($first, ['description' => 'Prestation fictive à 20 % — aucune opération commerciale']),
            array_replace($first, [
                'description' => 'Prestation fictive à 10 % — aucune opération commerciale',
                'tva_rate' => '10.00',
            ]),
        ];

        return $data;
    }
}
