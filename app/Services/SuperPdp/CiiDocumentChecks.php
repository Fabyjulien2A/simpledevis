<?php

namespace App\Services\SuperPdp;

use Brick\Math\BigDecimal;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Liaison CII/payload déjà calculé, sans recalcul fiscal ni prétention XSD/Schematron locale. */
final class CiiDocumentChecks
{
    public const NS = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const ROOT = '/rsm:CrossIndustryInvoice';
    private const TRADE = self::ROOT.'/rsm:SupplyChainTradeTransaction';
    private const AGREEMENT = self::TRADE.'/ram:ApplicableHeaderTradeAgreement';
    private const SETTLEMENT = self::TRADE.'/ram:ApplicableHeaderTradeSettlement';

    public function assertMatches(string $xml, array $payload): void
    {
        $x = $this->parse($xml);
        $document = self::ROOT.'/rsm:ExchangedDocument';
        $context = self::ROOT.'/rsm:ExchangedDocumentContext';
        foreach ([
            $document.'/ram:ID' => $payload['number'],
            $document.'/ram:TypeCode' => (string) $payload['type_code'],
            $document.'/ram:IssueDateTime/udt:DateTimeString' => str_replace('-', '', $payload['issue_date']),
            $document.'/ram:IssueDateTime/udt:DateTimeString/@format' => '102',
            $context.'/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' => $payload['process_control']['specification_identifier'],
            $context.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID' => $payload['process_control']['business_process_type'],
            self::SETTLEMENT.'/ram:InvoiceCurrencyCode' => $payload['currency_code'],
        ] as $path => $value) {
            $this->equal($x, $path, $value);
        }
        foreach ($payload['notes'] as $note) {
            $code = $note['subject_code']; // PMT/PMD/AAB, validés par le builder.
            $this->equal($x, $document."/ram:IncludedNote[ram:SubjectCode='$code']/ram:Content", $note['note']);
        }
        $this->count($x, $document.'/ram:IncludedNote', count($payload['notes']));
        foreach (['seller' => 'Seller', 'buyer' => 'Buyer'] as $key => $role) {
            $party = self::AGREEMENT.'/ram:'.$role.'TradeParty';
            $expected = $payload[$key];
            $this->equal($x, $party.'/ram:Name', $expected['name']);
            $this->identifier($x, $party.'/ram:SpecifiedLegalOrganization/ram:ID', $expected['legal_registration_identifier']);
            foreach ($expected['identifiers'] as $identifier) {
                $this->identifier($x, $party.'/ram:GlobalID', $identifier, true);
            }
            $this->count($x, $party.'/ram:GlobalID', count($expected['identifiers']));
            $this->identifier($x, $party.'/ram:URIUniversalCommunication/ram:URIID', $expected['electronic_address']);
            foreach (['address_line1' => 'LineOne', 'post_code' => 'PostcodeCode', 'city' => 'CityName', 'country_code' => 'CountryID'] as $field => $node) {
                $this->equal($x, $party.'/ram:PostalTradeAddress/ram:'.$node, $expected['postal_address'][$field]);
            }
            foreach (['vat_identifier' => 'VA', 'tax_registration_identifier' => 'FC'] as $field => $scheme) {
                if (isset($expected[$field])) {
                    $this->equal($x, $party."/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='$scheme']", $expected[$field]);
                }
            }
            $this->count($x, $party.'/ram:SpecifiedTaxRegistration',
                (int) isset($expected['vat_identifier']) + (int) isset($expected['tax_registration_identifier']));
            foreach (['email_address' => 'ram:EmailURIUniversalCommunication/ram:URIID', 'phone_number' => 'ram:TelephoneUniversalCommunication/ram:CompleteNumber'] as $field => $node) {
                if (isset($expected['contact'][$field])) {
                    $this->equal($x, $party.'/ram:DefinedTradeContact/'.$node, $expected['contact'][$field]);
                }
            }
        }
        $lines = self::TRADE.'/ram:IncludedSupplyChainTradeLineItem';
        $this->count($x, $lines, count($payload['lines']));
        foreach ($payload['lines'] as $index => $line) {
            $path = $lines.'['.($index + 1).']';
            $tax = $path.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';
            $this->equal($x, $path.'/ram:AssociatedDocumentLineDocument/ram:LineID', $line['identifier']);
            $this->equal($x, $path.'/ram:SpecifiedTradeProduct/ram:Name', $line['item_information']['name']);
            $this->equal($x, $path.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', $line['invoiced_quantity_code']);
            $this->decimal($x, $path.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $line['invoiced_quantity']);
            $this->decimal($x, $path.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $line['price_details']['item_net_price']);
            $basis = $path.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity';
            if ($x->query($basis)->length > 0) {
                $this->decimal($x, $basis, $line['price_details']['item_price_base_quantity']);
                $this->equal($x, $basis.'/@unitCode', $line['price_details']['quantity_unit_code']);
            }
            $this->decimal($x, $path.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line['net_amount']);
            $this->equal($x, $tax.'/ram:TypeCode', 'VAT');
            $this->equal($x, $tax.'/ram:CategoryCode', $line['vat_information']['invoiced_item_vat_category_code']);
            $this->decimal($x, $tax.'/ram:RateApplicablePercent', $line['vat_information']['invoiced_item_vat_rate']);
        }
        $taxes = self::SETTLEMENT.'/ram:ApplicableTradeTax';
        $this->count($x, $taxes, count($payload['vat_break_down']));
        foreach ($payload['vat_break_down'] as $index => $tax) {
            $path = $taxes.'['.($index + 1).']';
            $this->equal($x, $path.'/ram:TypeCode', 'VAT');
            $this->equal($x, $path.'/ram:CategoryCode', $tax['vat_category_code']);
            foreach (['vat_category_rate' => 'RateApplicablePercent', 'vat_category_taxable_amount' => 'BasisAmount', 'vat_category_tax_amount' => 'CalculatedAmount'] as $field => $node) {
                $this->decimal($x, $path.'/ram:'.$node, $tax[$field]);
            }
            foreach (['vat_exemption_reason_code' => 'ExemptionReasonCode', 'vat_exemption_reason' => 'ExemptionReason'] as $field => $node) {
                if (isset($tax[$field])) {
                    $this->equal($x, $path.'/ram:'.$node, $tax[$field]);
                }
            }
        }
        $totals = self::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        foreach (['sum_invoice_lines_amount' => 'LineTotalAmount', 'total_without_vat' => 'TaxBasisTotalAmount', 'total_with_vat' => 'GrandTotalAmount', 'amount_due_for_payment' => 'DuePayableAmount'] as $field => $node) {
            $this->decimal($x, $totals.'/ram:'.$node, $payload['totals'][$field]);
        }
        $this->decimal($x, $totals.'/ram:TaxTotalAmount', $payload['totals']['total_vat_amount']['value']);
        $this->equal($x, $totals.'/ram:TaxTotalAmount/@currencyID', 'EUR');
        if (isset($payload['payment_terms'])) {
            $this->equal($x, self::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:Description', $payload['payment_terms']);
        }
        if (isset($payload['payment_due_date'])) {
            $this->equal($x, self::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString', str_replace('-', '', $payload['payment_due_date']));
            $this->equal($x, self::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString/@format', '102');
        }
    }

    public function assertReport(array $report, string $xml): void
    {
        $rows = $report['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1) {
            throw new RuntimeException('Rapport de validation absent ou ambigu.');
        }
        $row = $rows[0];
        if (! is_array($row) || ($row['file_name'] ?? null) !== 'invoice.xml'
            || ($row['file_size'] ?? null) !== strlen($xml) || ($row['is_valid'] ?? null) !== true
            || ($row['format'] ?? null) !== self::NS || ! is_string($row['conformance_level'] ?? null)
            || trim($row['conformance_level']) === '' || ! is_int($row['duration'] ?? null) || $row['duration'] < 0
            || (array_key_exists('error', $row) && $row['error'] !== '')
            || ! is_array($row['subreports'] ?? null) || ! array_is_list($row['subreports']) || $row['subreports'] === []) {
            throw new RuntimeException('Le rapport ne prouve pas la validation du CII préparé.');
        }
        $checks = 0;
        foreach ($row['subreports'] as $subreport) {
            if (! is_array($subreport) || ! is_string($subreport['validator'] ?? null) || trim($subreport['validator']) === ''
                || ! is_int($subreport['checks_count'] ?? null) || $subreport['checks_count'] < 0
                || ($subreport['failures'] ?? null) !== [] || ! is_array($subreport['messages'] ?? null)
                || ! array_is_list($subreport['messages'])) {
                throw new RuntimeException('Un sous-rapport de validation est incomplet ou en échec.');
            }
            $checks += $subreport['checks_count'];
        }
        if ($checks === 0) {
            throw new RuntimeException('Aucun contrôle de validation attesté.');
        }
    }

    private function parse(string $xml): DOMXPath
    {
        if ($xml === '' || strlen($xml) > 5242880 || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            throw new RuntimeException('CII vide, trop volumineux, DTD ou entité interdite.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $doc->validateOnParse = false;
            if (! $doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) {
                throw new RuntimeException('XML CII mal formé.');
            }
            $x = new DOMXPath($doc, false);
            foreach (['rsm' => self::NS, 'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'] as $prefix => $uri) {
                $x->registerNamespace($prefix, $uri);
            }
            $this->count($x, self::ROOT, 1);

            return $x;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function count(DOMXPath $x, string $path, int $expected): void
    {
        if ($x->query($path)->length !== $expected) {
            throw new RuntimeException('Cardinalité CII incorrecte : '.$path);
        }
    }

    private function value(DOMXPath $x, string $path): string
    {
        $this->count($x, $path, 1);

        return trim($x->query($path)->item(0)->textContent);
    }

    private function equal(DOMXPath $x, string $path, string $expected): void
    {
        if ($this->value($x, $path) !== trim($expected)) {
            throw new RuntimeException('Le CII ne correspond pas au payload : '.$path);
        }
    }

    private function decimal(DOMXPath $x, string $path, string $expected): void
    {
        $value = $this->value($x, $path);
        if (! preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value) || ! BigDecimal::of($value)->isEqualTo($expected)) {
            throw new RuntimeException('Montant ou quantité CII non conforme au payload : '.$path);
        }
    }

    private function identifier(DOMXPath $x, string $path, array $expected, bool $multiple = false): void
    {
        $scheme = $expected['scheme']; // Schémas du builder et du contexte validé.
        if (! preg_match('/\A[A-Z0-9]+\z/D', $scheme)) {
            throw new RuntimeException('Schéma CII inattendu.');
        }
        if ($multiple) {
            $path .= "[@schemeID='$scheme']";
        }
        $this->equal($x, $path, $expected['value']);
        $this->equal($x, $path.'/@schemeID', $scheme);
    }
}
