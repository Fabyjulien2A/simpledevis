<?php

namespace Tests\Support;

use Brick\Math\BigDecimal;
use DOMXPath;
use RuntimeException;

/** Test-only regression of the captured two-rate invoice, not a regulatory validator. */
final class MultiRateCiiChecks
{
    public static function line(int $id): string
    {
        return SandboxCiiChecks::LINE."[ram:AssociatedDocumentLineDocument/ram:LineID='$id']";
    }

    public static function tax(int $rate): string
    {
        return SandboxCiiChecks::TAX.'[ram:RateApplicablePercent='.$rate.']';
    }

    public static function lineTax(int $id): string
    {
        return self::line($id).'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';
    }

    private static function equal(DOMXPath $x, string $path, string $value, string $layer = 'structure'): void
    {
        if (SandboxCiiChecks::one($x, $path, $layer) !== $value) {
            throw new RuntimeException($layer.': unexpected multi-rate value at '.$path);
        }
    }

    public static function structure(DOMXPath $x): void
    {
        if ($x->query(SandboxCiiChecks::LINE)->length !== 2 || $x->query(SandboxCiiChecks::TAX)->length !== 2) {
            throw new RuntimeException('structure: expected exactly two lines and two VAT breakdowns');
        }
        foreach ([
            SandboxCiiChecks::DOCUMENT.'/ram:ID' => 'SANDBOX-FICTIF-MULTITAUX-0003',
            SandboxCiiChecks::DOCUMENT.'/ram:TypeCode' => '380',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => '20261106',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString/@format' => '102',
            SandboxCiiChecks::CONTEXT.'/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' => 'urn:cen.eu:en16931:2017',
            SandboxCiiChecks::SETTLEMENT.'/ram:InvoiceCurrencyCode' => 'EUR',
            SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount/@currencyID' => 'EUR',
            SandboxCiiChecks::SELLER.'/ram:GlobalID' => '00000000000000',
            SandboxCiiChecks::SELLER.'/ram:GlobalID/@schemeID' => '0009',
            SandboxCiiChecks::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID' => '000000000',
            SandboxCiiChecks::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => '0002',
            SandboxCiiChecks::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID' => 'FR00000000000',
            SandboxCiiChecks::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' => 'VA',
        ] as $path => $value) {
            self::equal($x, $path, $value);
        }
        SandboxCiiChecks::one($x, SandboxCiiChecks::SELLER);
        SandboxCiiChecks::one($x, SandboxCiiChecks::TOTALS);
        foreach ([1 => 20, 2 => 10] as $id => $rate) {
            $line = self::line($id);
            SandboxCiiChecks::one($x, $line);
            SandboxCiiChecks::one($x, self::tax($rate));
            self::equal($x, $line.'/ram:SpecifiedTradeProduct/ram:Name', 'Prestation fictive à '.$rate.' % — aucune opération commerciale');
            self::equal($x, $line.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', 'C62');
            self::equal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode', 'C62');
            foreach ([self::lineTax($id), self::tax($rate)] as $tax) {
                SandboxCiiChecks::one($x, $tax);
                self::equal($x, $tax.'/ram:CategoryCode', 'S');
                self::equal($x, $tax.'/ram:TypeCode', 'VAT');
                if ($x->query($tax.'/ram:ExemptionReason | '.$tax.'/ram:ExemptionReasonCode')->length !== 0) {
                    throw new RuntimeException('structure: no exemption reason for this taxable reference');
                }
            }
        }
    }

    public static function french(DOMXPath $x): void
    {
        SandboxCiiChecks::french($x);
    }

    private static function decimal(DOMXPath $x, string $path, string $expected): BigDecimal
    {
        $text = SandboxCiiChecks::one($x, $path, 'amounts');
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $text)) {
            throw new RuntimeException('amounts: invalid multi-rate decimal at '.$path);
        }
        $value = BigDecimal::of($text);
        if (! $value->isEqualTo($expected)) {
            throw new RuntimeException('amounts: unexpected multi-rate amount at '.$path);
        }

        return $value;
    }

    public static function amounts(DOMXPath $x): void
    {
        $lineSum = BigDecimal::of('0');
        $taxSum = BigDecimal::of('0');
        $baseSum = BigDecimal::of('0');
        foreach ([1 => 20, 2 => 10] as $id => $rate) {
            $line = self::line($id);
            $quantity = self::decimal($x, $line.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '1');
            $price = self::decimal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '100');
            $priceBasis = self::decimal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '1');
            $net = self::decimal($x, $line.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '100');
            $lineRate = self::decimal($x, self::lineTax($id).'/ram:RateApplicablePercent', (string) $rate);
            $headerRate = self::decimal($x, self::tax($rate).'/ram:RateApplicablePercent', (string) $rate);
            $base = self::decimal($x, self::tax($rate).'/ram:BasisAmount', '100');
            $tax = self::decimal($x, self::tax($rate).'/ram:CalculatedAmount', (string) $rate);
            if (! $quantity->multipliedBy($price)->isEqualTo($net->multipliedBy($priceBasis))
                || ! $net->isEqualTo($base)
                || ! $lineRate->isEqualTo($headerRate)
                || ! $base->multipliedBy($headerRate)->isEqualTo($tax->multipliedBy('100'))) {
                throw new RuntimeException('amounts: inconsistent line or VAT breakdown');
            }
            $lineSum = $lineSum->plus($net);
            $baseSum = $baseSum->plus($base);
            $taxSum = $taxSum->plus($tax);
        }
        $totalLines = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:LineTotalAmount', '200');
        $ht = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:TaxBasisTotalAmount', '200');
        $vat = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount', '30');
        $ttc = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:GrandTotalAmount', '230');
        $due = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:DuePayableAmount', '230');
        if (! $lineSum->isEqualTo($totalLines) || ! $baseSum->isEqualTo($ht)
            || ! $totalLines->isEqualTo($ht) || ! $taxSum->isEqualTo($vat)
            || ! $ht->plus($vat)->isEqualTo($ttc) || ! $ttc->isEqualTo($due)) {
            throw new RuntimeException('amounts: inconsistent multi-rate totals');
        }
        foreach (['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount'] as $optional) {
            $path = SandboxCiiChecks::TOTALS.'/ram:'.$optional;
            if ($x->query($path)->length > 0) {
                self::decimal($x, $path, '0');
            }
        }
    }
}
