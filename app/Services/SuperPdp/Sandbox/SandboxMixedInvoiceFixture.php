<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Models\Invoice;

/** Fiction indépendante : fourniture et installation ordinaires, cadre M1 explicite. */
final class SandboxMixedInvoiceFixture extends SandboxInvoiceFixture
{
    public function __construct()
    {
        parent::__construct();
        $this->invoice()->fill([
            'invoice_number' => 'SANDBOX-FICTIF-MIXTE-0004',
            'subtotal_ht' => '250.00', 'total_tva' => '50.00', 'total_ttc' => '300.00',
        ]);
    }

    public function frenchContext(): array
    {
        return array_replace(parent::frenchContext(), ['business_process_type' => 'M1']);
    }

    public function buildElectronic(Invoice $invoice): array
    {
        // Conserver le contrôle d'identité et l'interdiction de persistance de la source.
        $data = parent::buildElectronic($invoice);
        $data['seller']['name'] = 'Vendeur entièrement fictif — essai mixte M1';
        $data['buyer']['company_name'] = 'Acheteur entièrement fictif — essai mixte M1';
        $line = $data['items'][0];
        $data['items'] = [
            array_replace($line, ['description' => 'Fourniture fictive d’un robinet — aucune opération commerciale']),
            array_replace($line, [
                'description' => 'Prestation fictive d’installation — aucune opération commerciale',
                'unit_price_ht' => '150.00', 'line_total_ht' => '150.00',
            ]),
        ];

        return $data;
    }
}
