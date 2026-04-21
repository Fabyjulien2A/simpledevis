<?php

namespace App\Services;

use App\Models\Invoice;
use SimpleXMLElement;

class InvoiceXmlGenerator
{
    public function __construct(
        protected InvoiceExportDataBuilder $builder
    ) {}

    public function generate(Invoice $invoice): string
    {
        $data = $this->builder->build($invoice);

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Invoice/>');

        $xml->addChild('InvoiceNumber', htmlspecialchars($data['invoice_number']));
        $xml->addChild('IssueDate', $data['date'] ?? '');
        $xml->addChild('DueDate', $data['due_date'] ?? '');
        $xml->addChild('Status', htmlspecialchars($data['status']));

        $seller = $xml->addChild('Seller');
        $seller->addChild('Name', htmlspecialchars($data['seller']['name'] ?? ''));
        $seller->addChild('Address', htmlspecialchars($data['seller']['address'] ?? ''));
        $seller->addChild('PostalCode', htmlspecialchars($data['seller']['postal_code'] ?? ''));
        $seller->addChild('City', htmlspecialchars($data['seller']['city'] ?? ''));
        $seller->addChild('Email', htmlspecialchars($data['seller']['email'] ?? ''));
        $seller->addChild('Phone', htmlspecialchars($data['seller']['phone'] ?? ''));
        $seller->addChild('Siret', htmlspecialchars($data['seller']['siret'] ?? ''));
        $seller->addChild('VatNumber', htmlspecialchars($data['seller']['vat_number'] ?? ''));

        $buyer = $xml->addChild('Buyer');
        $buyer->addChild('FullName', htmlspecialchars($data['buyer']['full_name'] ?? ''));
        $buyer->addChild('CompanyName', htmlspecialchars($data['buyer']['company_name'] ?? ''));
        $buyer->addChild('Address', htmlspecialchars($data['buyer']['address'] ?? ''));
        $buyer->addChild('PostalCode', htmlspecialchars($data['buyer']['postal_code'] ?? ''));
        $buyer->addChild('City', htmlspecialchars($data['buyer']['city'] ?? ''));
        $buyer->addChild('Email', htmlspecialchars($data['buyer']['email'] ?? ''));
        $buyer->addChild('Phone', htmlspecialchars($data['buyer']['phone'] ?? ''));

        $itemsNode = $xml->addChild('Items');
        foreach ($data['items'] as $item) {
            $itemNode = $itemsNode->addChild('Item');
            $itemNode->addChild('Description', htmlspecialchars($item['description'] ?? ''));
            $itemNode->addChild('Quantity', number_format((float) $item['quantity'], 2, '.', ''));
            $itemNode->addChild('UnitPriceHT', number_format((float) $item['unit_price_ht'], 2, '.', ''));
            $itemNode->addChild('VatRate', number_format((float) $item['tva_rate'], 2, '.', ''));
            $itemNode->addChild('LineTotalHT', number_format((float) $item['line_total_ht'], 2, '.', ''));
        }

        $totals = $xml->addChild('Totals');
        $totals->addChild('SubtotalHT', number_format((float) $data['totals']['subtotal_ht'], 2, '.', ''));
        $totals->addChild('TotalVAT', number_format((float) $data['totals']['total_tva'], 2, '.', ''));
        $totals->addChild('TotalTTC', number_format((float) $data['totals']['total_ttc'], 2, '.', ''));
        $totals->addChild('AmountPaid', number_format((float) $data['totals']['amount_paid'], 2, '.', ''));
        $totals->addChild('RemainingAmount', number_format((float) $data['totals']['remaining_amount'], 2, '.', ''));

        return $xml->asXML() ?: '';
    }
}