<?php

namespace Tests\Support;

use Brick\Math\BigDecimal;
use DOMXPath;
use RuntimeException;

/** Test-only checks of this captured M1 fiction, not a regulatory validator. */
final class MixedCiiChecks
{
    public static function line(int $id): string
    {
        return SandboxCiiChecks::LINE."[ram:AssociatedDocumentLineDocument/ram:LineID='$id']";
    }

    public static function lineTax(int $id): string
    {
        return self::line($id).'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';
    }

    private static function equal(DOMXPath $x, string $path, string $value, string $layer = 'structure'): void
    {
        if (SandboxCiiChecks::one($x, $path, $layer) !== $value) {
            throw new RuntimeException($layer.': unexpected M1 value at '.$path);
        }
    }

    public static function structure(DOMXPath $x): void
    {
        if ($x->query(SandboxCiiChecks::LINE)->length !== 2 || $x->query(SandboxCiiChecks::TAX)->length !== 1) {
            throw new RuntimeException('structure: expected two lines and one VAT breakdown');
        }
        foreach ([
            SandboxCiiChecks::DOCUMENT.'/ram:ID' => 'SANDBOX-FICTIF-MIXTE-0004',
            SandboxCiiChecks::DOCUMENT.'/ram:TypeCode' => '380',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => '20261106',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString/@format' => '102',
            SandboxCiiChecks::CONTEXT.'/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' => 'urn:cen.eu:en16931:2017',
            SandboxCiiChecks::SETTLEMENT.'/ram:InvoiceCurrencyCode' => 'EUR',
            SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount/@currencyID' => 'EUR',
        ] as $path => $value) {
            self::equal($x, $path, $value);
        }
        SandboxCiiChecks::one($x, SandboxCiiChecks::TOTALS);
        foreach ([
            1 => 'Fourniture fictive d’un robinet — aucune opération commerciale',
            2 => 'Prestation fictive d’installation — aucune opération commerciale',
        ] as $id => $name) {
            $line = self::line($id);
            SandboxCiiChecks::one($x, $line);
            self::equal($x, $line.'/ram:SpecifiedTradeProduct/ram:Name', $name);
            self::equal($x, $line.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', 'C62');
            self::equal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode', 'C62');
        }
        foreach ([self::lineTax(1), self::lineTax(2), SandboxCiiChecks::TAX] as $tax) {
            SandboxCiiChecks::one($x, $tax);
            self::equal($x, $tax.'/ram:CategoryCode', 'S');
            self::equal($x, $tax.'/ram:TypeCode', 'VAT');
            if ($x->query($tax.'/ram:ExemptionReason | '.$tax.'/ram:ExemptionReasonCode')->length !== 0) {
                throw new RuntimeException('structure: no exemption for this taxable M1 reference');
            }
        }
    }

    public static function french(DOMXPath $x): void
    {
        self::equal($x, SandboxCiiChecks::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'M1', 'french');
        foreach (['PMT' => '40 € dus en cas de retard de paiement.', 'PMD' => '10% du montant TTC.', 'AAB' => 'Néant.'] as $code => $text) {
            $note = SandboxCiiChecks::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='$code']";
            self::equal($x, $note.'/ram:SubjectCode', $code, 'french');
            self::equal($x, $note.'/ram:Content', $text, 'french');
        }
    }

    private static function decimal(DOMXPath $x, string $path, string $expected): BigDecimal
    {
        $text = SandboxCiiChecks::one($x, $path, 'amounts');
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $text)) {
            throw new RuntimeException('amounts: invalid M1 decimal at '.$path);
        }
        $value = BigDecimal::of($text);
        if (! $value->isEqualTo($expected)) {
            throw new RuntimeException('amounts: unexpected M1 amount at '.$path);
        }

        return $value;
    }

    public static function amounts(DOMXPath $x): void
    {
        $sum = BigDecimal::of('0');
        foreach ([1 => '100', 2 => '150'] as $id => $expected) {
            $line = self::line($id);
            $quantity = self::decimal($x, $line.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '1');
            $price = self::decimal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $expected);
            $basis = self::decimal($x, $line.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '1');
            $net = self::decimal($x, $line.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $expected);
            self::decimal($x, self::lineTax($id).'/ram:RateApplicablePercent', '20');
            if (! $quantity->multipliedBy($price)->isEqualTo($net->multipliedBy($basis))) {
                throw new RuntimeException('amounts: inconsistent M1 line');
            }
            $sum = $sum->plus($net);
        }
        $rate = self::decimal($x, SandboxCiiChecks::TAX.'/ram:RateApplicablePercent', '20');
        $base = self::decimal($x, SandboxCiiChecks::TAX.'/ram:BasisAmount', '250');
        $tax = self::decimal($x, SandboxCiiChecks::TAX.'/ram:CalculatedAmount', '50');
        $lines = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:LineTotalAmount', '250');
        $ht = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:TaxBasisTotalAmount', '250');
        $vat = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount', '50');
        $ttc = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:GrandTotalAmount', '300');
        $due = self::decimal($x, SandboxCiiChecks::TOTALS.'/ram:DuePayableAmount', '300');
        if (! $sum->isEqualTo($lines) || ! $lines->isEqualTo($base) || ! $base->isEqualTo($ht)
            || ! $base->multipliedBy($rate)->isEqualTo($tax->multipliedBy('100'))
            || ! $tax->isEqualTo($vat) || ! $ht->plus($vat)->isEqualTo($ttc) || ! $ttc->isEqualTo($due)) {
            throw new RuntimeException('amounts: inconsistent M1 totals');
        }
        foreach (['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount'] as $optional) {
            $path = SandboxCiiChecks::TOTALS.'/ram:'.$optional;
            if ($x->query($path)->length > 0) {
                self::decimal($x, $path, '0');
            }
        }
    }
}
