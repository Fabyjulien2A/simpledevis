<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Models\Invoice;

/** Explicit fictional franchise, never a determination of a real company's tax regime. */
final class SandboxFranchiseInvoiceFixture extends SandboxInvoiceFixture
{
    public const REASON_CODE = 'VATEX-FR-FRANCHISE';

    public const REASON = 'TVA non applicable, art. 293 B du CGI';

    public function __construct()
    {
        parent::__construct();
        $this->invoice()->fill([
            'invoice_number' => 'SANDBOX-FICTIF-FRANCHISE-0002',
            'total_tva' => '0.00', 'total_ttc' => '100.00',
        ]);
    }

    public function buildElectronic(Invoice $invoice): array
    {
        // Keep the parent's identity check: only this instance, without ID or persistence.
        $data = parent::buildElectronic($invoice);
        $data['seller']['name'] = 'Vendeur entièrement fictif — franchise sandbox';
        // A VAT identifier is not inferred for this fictional exempt seller.
        unset($data['seller']['vat_number']);
        $data['items'][0] = array_replace($data['items'][0], [
            'description' => 'Prestation fictive en franchise — aucune opération commerciale',
            'vat_category' => 'E', 'tva_rate' => '0.00',
            'exemption_reason_code' => self::REASON_CODE,
            'exemption_reason' => self::REASON,
        ]);
        // XP Z12-012 BR-FR-CO-16 (excerpt supplied by the user): this fiction is
        // explicitly in franchise without a VAT number. Repeat its SIREN in BT-32,
        // without FR prefix; BT-30 remains independently mapped by the builder.
        $data['seller']['tax_registration_identifier'] = substr($data['seller']['siret'], 0, 9);

        return $data;
    }
}
