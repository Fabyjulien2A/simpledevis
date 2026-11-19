<?php

namespace Tests\Support;

/** Synthetic test XML only. No provider conversion or validation is claimed. */
final class SandboxOwnInvoiceCii
{
    public static function xml(array $payload): string
    {
        $x = SandboxCiiChecks::parse(BurgerQueenSandboxCii::xml(['payload' => $payload]));
        $set = fn ($path, $value) => PreparedCiiFixture::set($x, $path, (string) $value);
        foreach (['seller' => 'Seller', 'buyer' => 'Buyer'] as $key => $role) {
            $party = SandboxCiiChecks::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:'.$role.'TradeParty';
            foreach (['address_line1' => 'LineOne', 'post_code' => 'PostcodeCode', 'city' => 'CityName', 'country_code' => 'CountryID'] as $field => $node) {
                $set($party.'/ram:PostalTradeAddress/ram:'.$node, $payload[$key]['postal_address'][$field]);
            }
        }
        $template = $x->query(SandboxCiiChecks::LINE)->item(0);
        foreach (iterator_to_array($x->query(SandboxCiiChecks::LINE)) as $node) {
            $node->parentNode->removeChild($node);
        }
        $trade = $x->query(SandboxCiiChecks::TRADE)->item(0);
        foreach ($payload['lines'] as $i => $line) {
            $trade->insertBefore($template->cloneNode(true), $trade->firstChild);
        }
        foreach ($payload['lines'] as $i => $line) {
            $path = SandboxCiiChecks::LINE.'['.($i + 1).']';
            foreach ([
                '/ram:AssociatedDocumentLineDocument/ram:LineID' => $line['identifier'],
                '/ram:SpecifiedTradeProduct/ram:Name' => $line['item_information']['name'],
                '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity' => $line['invoiced_quantity'],
                '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode' => $line['invoiced_quantity_code'],
                '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount' => $line['price_details']['item_net_price'],
                '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount' => $line['net_amount'],
                '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' => $line['vat_information']['invoiced_item_vat_category_code'],
                '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' => $line['vat_information']['invoiced_item_vat_rate'],
            ] as $suffix => $value) {
                $set($path.$suffix, $value);
            }
            $basis = $path.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity';
            if ($x->query($basis)->length) {
                $set($basis, $line['price_details']['item_price_base_quantity']);
                $set($basis.'/@unitCode', $line['price_details']['quantity_unit_code']);
            }
        }
        $taxPath = SandboxCiiChecks::SETTLEMENT.'/ram:ApplicableTradeTax';
        $template = $x->query($taxPath)->item(0);
        foreach (iterator_to_array($x->query($taxPath)) as $node) {
            $node->parentNode->removeChild($node);
        }
        $settlement = $x->query(SandboxCiiChecks::SETTLEMENT)->item(0);
        foreach ($payload['vat_break_down'] as $tax) {
            $settlement->insertBefore($template->cloneNode(true), $settlement->firstChild);
        }
        foreach ($payload['vat_break_down'] as $i => $tax) {
            $path = $taxPath.'['.($i + 1).']';
            foreach (['CategoryCode' => 'vat_category_code', 'RateApplicablePercent' => 'vat_category_rate',
                'BasisAmount' => 'vat_category_taxable_amount', 'CalculatedAmount' => 'vat_category_tax_amount'] as $node => $field) {
                $set($path.'/ram:'.$node, $tax[$field]);
            }
        }
        $totals = SandboxCiiChecks::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        foreach (['LineTotalAmount' => 'sum_invoice_lines_amount', 'TaxBasisTotalAmount' => 'total_without_vat',
            'GrandTotalAmount' => 'total_with_vat', 'DuePayableAmount' => 'amount_due_for_payment'] as $node => $field) {
            $set($totals.'/ram:'.$node, $payload['totals'][$field]);
        }
        $set($totals.'/ram:TaxTotalAmount', $payload['totals']['total_vat_amount']['value']);

        return $x->document->saveXML();
    }
}
