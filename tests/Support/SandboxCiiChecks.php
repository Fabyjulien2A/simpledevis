<?php

namespace Tests\Support;

use Brick\Math\BigDecimal;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Local regression checks for the single fictitious 100 + 20 = 120 invoice.
 * Not an XSD, Schematron, EN16931 or French regulatory validator.
 */
final class SandboxCiiChecks
{
    public const NS = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
    ];

    public const ROOT = '/rsm:CrossIndustryInvoice';

    public const DOCUMENT = self::ROOT.'/rsm:ExchangedDocument';

    public const CONTEXT = self::ROOT.'/rsm:ExchangedDocumentContext';

    public const TRADE = self::ROOT.'/rsm:SupplyChainTradeTransaction';

    public const SELLER = self::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';

    public const LINE = self::TRADE.'/ram:IncludedSupplyChainTradeLineItem';

    public const SETTLEMENT = self::TRADE.'/ram:ApplicableHeaderTradeSettlement';

    public const TAX = self::SETTLEMENT.'/ram:ApplicableTradeTax';

    public const TOTALS = self::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    public static function parse(string $xml): DOMXPath
    {
        // Reject UTF-16/32 as well: the captured fixture must be UTF-8, so the
        // DTD guard cannot be bypassed with null-separated markup.
        if ($xml === '' || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            throw new RuntimeException('structure: empty XML, DTD or entity declaration forbidden');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $doc->validateOnParse = false;
            if (! $doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) {
                throw new RuntimeException('structure: malformed XML');
            }
            // Do not let document prefixes override the trusted URI bindings.
            $xpath = new DOMXPath($doc, false);
            foreach (self::NS as $prefix => $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }
            self::one($xpath, self::ROOT);

            return $xpath;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function one(DOMXPath $xpath, string $path, string $layer = 'structure'): string
    {
        $nodes = $xpath->query($path);
        if ($nodes === false || $nodes->length !== 1 || trim($nodes->item(0)->textContent) === '') {
            throw new RuntimeException($layer.': expected exactly one nonempty node at '.$path);
        }

        return trim($nodes->item(0)->textContent);
    }

    private static function equal(DOMXPath $xpath, string $path, string $expected, string $layer = 'structure'): void
    {
        if (self::one($xpath, $path, $layer) !== $expected) {
            throw new RuntimeException($layer.': unexpected value at '.$path);
        }
    }

    public static function structure(DOMXPath $x): void
    {
        foreach ([
            self::DOCUMENT.'/ram:ID' => 'SANDBOX-FICTIF-0001',
            self::DOCUMENT.'/ram:TypeCode' => '380',
            self::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => '20261106',
            self::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString/@format' => '102',
            self::CONTEXT.'/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' => 'urn:cen.eu:en16931:2017',
            self::SETTLEMENT.'/ram:InvoiceCurrencyCode' => 'EUR',
            self::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID' => '000000000',
            self::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => '0002',
            self::SELLER.'/ram:GlobalID' => '00000000000000',
            self::SELLER.'/ram:GlobalID/@schemeID' => '0009',
            self::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID' => 'FR00000000000',
            self::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' => 'VA',
            self::LINE.'/ram:AssociatedDocumentLineDocument/ram:LineID' => '1',
            self::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode' => 'C62',
            self::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:TypeCode' => 'VAT',
            self::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' => 'S',
            self::TAX.'/ram:TypeCode' => 'VAT',
            self::TAX.'/ram:CategoryCode' => 'S',
            self::TOTALS.'/ram:TaxTotalAmount/@currencyID' => 'EUR',
        ] as $path => $expected) {
            self::equal($x, $path, $expected);
        }
        foreach ([self::LINE, self::TAX, self::SELLER, self::TOTALS] as $path) {
            self::one($x, $path);
        }
        self::one($x, self::LINE.'/ram:SpecifiedTradeProduct/ram:Name');
    }

    public static function french(DOMXPath $x): void
    {
        self::equal($x, self::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'S1', 'french');
        foreach (['PMT' => '40 € dus en cas de retard de paiement.', 'PMD' => '10% du montant TTC.', 'AAB' => 'Néant.'] as $code => $text) {
            $note = self::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='$code']";
            self::equal($x, $note.'/ram:SubjectCode', $code, 'french');
            self::equal($x, $note.'/ram:Content', $text, 'french');
        }
    }

    private static function decimal(DOMXPath $x, string $path): BigDecimal
    {
        $value = self::one($x, $path, 'amounts');
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new RuntimeException('amounts: invalid nonnegative decimal at '.$path);
        }

        return BigDecimal::of($value);
    }

    public static function amounts(DOMXPath $x): void
    {
        $priceBasisPath = self::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity';
        $priceBasis = BigDecimal::of('1');
        if ($x->query($priceBasisPath)->length > 0) {
            $priceBasis = self::decimal($x, $priceBasisPath);
            if (! $priceBasis->isEqualTo('1')) {
                throw new RuntimeException('amounts: fixture requires price basis quantity 1');
            }
            self::equal($x, $priceBasisPath.'/@unitCode', 'C62', 'amounts');
        }
        $values = [];
        foreach ([
            'ht' => [self::TOTALS.'/ram:TaxBasisTotalAmount', '100'],
            'vat' => [self::TOTALS.'/ram:TaxTotalAmount', '20'],
            'ttc' => [self::TOTALS.'/ram:GrandTotalAmount', '120'],
            'due' => [self::TOTALS.'/ram:DuePayableAmount', '120'],
            'lines' => [self::TOTALS.'/ram:LineTotalAmount', '100'],
            'line' => [self::LINE.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '100'],
            'quantity' => [self::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '1'],
            'price' => [self::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '100'],
            'rate' => [self::TAX.'/ram:RateApplicablePercent', '20'],
            'lineRate' => [self::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', '20'],
            'basis' => [self::TAX.'/ram:BasisAmount', '100'],
            'tax' => [self::TAX.'/ram:CalculatedAmount', '20'],
        ] as $name => [$path, $expected]) {
            $values[$name] = self::decimal($x, $path);
            if (! $values[$name]->isEqualTo($expected)) {
                throw new RuntimeException('amounts: unexpected fixture amount at '.$path);
            }
        }
        foreach (['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount'] as $optional) {
            $path = self::TOTALS.'/ram:'.$optional;
            if ($x->query($path)->length > 0 && ! self::decimal($x, $path)->isZero()) {
                throw new RuntimeException('amounts: fixture requires zero '.$optional);
            }
        }
        if (! $values['ht']->plus($values['vat'])->isEqualTo($values['ttc'])
            || ! $values['ttc']->isEqualTo($values['due'])
            || ! $values['quantity']->multipliedBy($values['price'])->isEqualTo($values['line']->multipliedBy($priceBasis))
            || ! $values['basis']->multipliedBy($values['rate'])->isEqualTo($values['tax']->multipliedBy('100'))
            || ! $values['line']->isEqualTo($values['lines'])
            || ! $values['lines']->isEqualTo($values['ht'])
            || ! $values['tax']->isEqualTo($values['vat'])) {
            throw new RuntimeException('amounts: inconsistent totals');
        }
    }
}
