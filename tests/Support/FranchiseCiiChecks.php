<?php

namespace Tests\Support;

use Brick\Math\BigDecimal;
use DOMXPath;
use RuntimeException;

/** Test-only checks for this single captured fiction, not a regulatory validator. */
final class FranchiseCiiChecks
{
    public const LINE_TAX = SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';

    public const BT30 = SandboxCiiChecks::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID';

    public const BT32 = SandboxCiiChecks::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID';

    private static function equal(DOMXPath $x, string $path, string $expected, string $layer): void
    {
        if (SandboxCiiChecks::one($x, $path, $layer) !== $expected) {
            throw new RuntimeException($layer.': unexpected franchise value at '.$path);
        }
    }

    public static function structure(DOMXPath $x): void
    {
        foreach ([
            SandboxCiiChecks::DOCUMENT.'/ram:ID' => 'SANDBOX-FICTIF-FRANCHISE-0002',
            SandboxCiiChecks::DOCUMENT.'/ram:TypeCode' => '380',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => '20261106',
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString/@format' => '102',
            SandboxCiiChecks::CONTEXT.'/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' => 'urn:cen.eu:en16931:2017',
            SandboxCiiChecks::SETTLEMENT.'/ram:InvoiceCurrencyCode' => 'EUR',
            self::BT30 => '000000000',
            self::BT30.'/@schemeID' => '0002',
            self::BT32 => '000000000',
            self::BT32.'/@schemeID' => 'FC',
            SandboxCiiChecks::SELLER.'/ram:GlobalID' => '00000000000000',
            SandboxCiiChecks::SELLER.'/ram:GlobalID/@schemeID' => '0009',
            SandboxCiiChecks::LINE.'/ram:AssociatedDocumentLineDocument/ram:LineID' => '1',
            SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode' => 'C62',
            SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount/@currencyID' => 'EUR',
        ] as $path => $value) {
            self::equal($x, $path, $value, 'structure');
        }
        foreach ([SandboxCiiChecks::LINE, SandboxCiiChecks::TAX, self::LINE_TAX, SandboxCiiChecks::SELLER, SandboxCiiChecks::TOTALS] as $path) {
            SandboxCiiChecks::one($x, $path);
        }
        foreach ([self::LINE_TAX, SandboxCiiChecks::TAX] as $tax) {
            self::equal($x, $tax.'/ram:TypeCode', 'VAT', 'structure');
            self::equal($x, $tax.'/ram:CategoryCode', 'E', 'structure');
        }
    }

    public static function french(DOMXPath $x): void
    {
        // Reuse only the unchanged S1/PMT/PMD/AAB checks, not the taxable fixture checks.
        SandboxCiiChecks::french($x);
        foreach ([self::LINE_TAX, SandboxCiiChecks::TAX] as $tax) {
            self::equal($x, $tax.'/ram:ExemptionReasonCode', 'VATEX-FR-FRANCHISE', 'french');
            self::equal($x, $tax.'/ram:ExemptionReason', 'TVA non applicable, art. 293 B du CGI', 'french');
        }
    }

    private static function decimal(DOMXPath $x, string $path): BigDecimal
    {
        $text = SandboxCiiChecks::one($x, $path, 'amounts');
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $text)) {
            throw new RuntimeException('amounts: invalid franchise decimal at '.$path);
        }

        return BigDecimal::of($text);
    }

    public static function amounts(DOMXPath $x): void
    {
        $values = [];
        foreach ([
            'ht' => [SandboxCiiChecks::TOTALS.'/ram:TaxBasisTotalAmount', '100'],
            'vat' => [SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount', '0'],
            'ttc' => [SandboxCiiChecks::TOTALS.'/ram:GrandTotalAmount', '100'],
            'due' => [SandboxCiiChecks::TOTALS.'/ram:DuePayableAmount', '100'],
            'lines' => [SandboxCiiChecks::TOTALS.'/ram:LineTotalAmount', '100'],
            'line' => [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '100'],
            'quantity' => [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '1'],
            'price' => [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '100'],
            'priceBasis' => [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '1'],
            'rate' => [SandboxCiiChecks::TAX.'/ram:RateApplicablePercent', '0'],
            'lineRate' => [self::LINE_TAX.'/ram:RateApplicablePercent', '0'],
            'basis' => [SandboxCiiChecks::TAX.'/ram:BasisAmount', '100'],
            'tax' => [SandboxCiiChecks::TAX.'/ram:CalculatedAmount', '0'],
        ] as $key => [$path, $expected]) {
            $values[$key] = self::decimal($x, $path);
            if (! $values[$key]->isEqualTo($expected)) {
                throw new RuntimeException('amounts: unexpected franchise amount at '.$path);
            }
        }
        self::equal($x, SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode', 'C62', 'amounts');
        foreach (['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount'] as $optional) {
            $path = SandboxCiiChecks::TOTALS.'/ram:'.$optional;
            if ($x->query($path)->length > 0 && ! self::decimal($x, $path)->isZero()) {
                throw new RuntimeException('amounts: franchise fixture requires zero '.$optional);
            }
        }
        if (! $values['ht']->plus($values['vat'])->isEqualTo($values['ttc'])
            || ! $values['ttc']->isEqualTo($values['due'])
            || ! $values['quantity']->multipliedBy($values['price'])->isEqualTo($values['line']->multipliedBy($values['priceBasis']))
            || ! $values['line']->isEqualTo($values['lines'])
            || ! $values['lines']->isEqualTo($values['ht'])
            || ! $values['basis']->isEqualTo($values['ht'])
            || ! $values['basis']->multipliedBy($values['rate'])->isEqualTo($values['tax']->multipliedBy('100'))
            || ! $values['tax']->isEqualTo($values['vat'])) {
            throw new RuntimeException('amounts: inconsistent franchise totals');
        }
    }
}
