<?php

namespace App\Services;

use App\Models\Invoice;
use Atgp\FacturX\Utils\ProfileHandler;
use Atgp\FacturX\Writer;
use Barryvdh\DomPDF\Facade\Pdf;

class FacturXService
{
    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing('client', 'items', 'quote', 'payments', 'user.company');

        $company = $invoice->user->company ?? $invoice->user;

        $pdfContent = Pdf::loadView('invoices.pdf', compact('invoice', 'company'))->output();

        $xmlContent = $this->generateXml($invoice, $company);

        $xmlPath = storage_path("app/facturx/invoice-{$invoice->id}.xml");

        if (! file_exists(dirname($xmlPath))) {
            mkdir(dirname($xmlPath), 0777, true);
        }

        file_put_contents($xmlPath, $xmlContent);

        $facturxPath = storage_path("app/facturx/invoice-{$invoice->id}-facturx.pdf");

        $writer = new Writer;

        $facturxPdf = $writer->generate(
            $pdfContent,
            $xmlContent,
            ProfileHandler::PROFILE_FACTURX_EN16931,
            false,
            [],
            false,
            'Data'
        );

        file_put_contents($facturxPath, $facturxPdf);

        return $facturxPath;
    }

    private function generateXml(Invoice $invoice, $company): string
    {
        $invoiceNumber = htmlspecialchars($invoice->invoice_number ?: "INV-{$invoice->id}");
        $issueDate = $invoice->date?->format('Ymd') ?? date('Ymd');
        $dueDate = $invoice->due_date?->format('Ymd') ?? $issueDate;

        $sellerName = htmlspecialchars($company->company_name ?? $company->name ?? $invoice->user->name);
        $sellerAddress = htmlspecialchars($company->address ?? '');
        $sellerCity = htmlspecialchars($company->city ?? '');
        $sellerZip = htmlspecialchars($company->postal_code ?? '');
        $sellerCountry = 'FR';
        $sellerVatNumber = htmlspecialchars($company->tva_number ?? $company->vat_number ?? '');

        $buyerName = htmlspecialchars($invoice->client->company_name ?: $invoice->client->full_name ?: 'Client');
        $buyerAddress = htmlspecialchars($invoice->client->address ?? '');
        $buyerCity = htmlspecialchars($invoice->client->city ?? '');
        $buyerZip = htmlspecialchars($invoice->client->postal_code ?? '');
        $buyerCountry = 'FR';
        $buyerVatNumber = htmlspecialchars($invoice->client->vat_number ?? '');
        $buyerSiret = htmlspecialchars($invoice->client->siret ?? '');
        $buyerType = $invoice->client->client_type ?? 'individual';

        $currency = 'EUR';

        $totalHt = number_format((float) $invoice->subtotal_ht, 2, '.', '');
        $totalVat = number_format((float) $invoice->total_tva, 2, '.', '');
        $totalTtc = number_format((float) $invoice->total_ttc, 2, '.', '');

        $profileId = 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931';

        $linesXml = '';

        foreach ($invoice->items as $index => $item) {
            $lineNumber = $index + 1;
            $description = htmlspecialchars($item->description ?? 'Prestation');
            $quantity = number_format((float) $item->quantity, 2, '.', '');
            $unitPrice = number_format((float) $item->unit_price_ht, 2, '.', '');
            $lineTotal = number_format((float) $item->line_total_ht, 2, '.', '');

            $linesXml .= <<<XML
        <ram:IncludedSupplyChainTradeLineItem>
            <ram:AssociatedDocumentLineDocument>
                <ram:LineID>{$lineNumber}</ram:LineID>
            </ram:AssociatedDocumentLineDocument>
            <ram:SpecifiedTradeProduct>
                <ram:Name>{$description}</ram:Name>
            </ram:SpecifiedTradeProduct>
            <ram:SpecifiedLineTradeAgreement>
                <ram:NetPriceProductTradePrice>
                    <ram:ChargeAmount>{$unitPrice}</ram:ChargeAmount>
                </ram:NetPriceProductTradePrice>
            </ram:SpecifiedLineTradeAgreement>
            <ram:SpecifiedLineTradeDelivery>
                <ram:BilledQuantity unitCode="C62">{$quantity}</ram:BilledQuantity>
            </ram:SpecifiedLineTradeDelivery>
            <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
                <ram:SpecifiedTradeSettlementLineMonetarySummation>
                    <ram:LineTotalAmount>{$lineTotal}</ram:LineTotalAmount>
                </ram:SpecifiedTradeSettlementLineMonetarySummation>
            </ram:SpecifiedLineTradeSettlement>
        </ram:IncludedSupplyChainTradeLineItem>

XML;
        }

        $buyerTaxXml = '';

        if (
            $buyerType === 'professional'
            && ! empty($buyerVatNumber)
        ) {
            $buyerTaxXml = <<<XML
    <ram:SpecifiedTaxRegistration>
        <ram:ID schemeID="VA">{$buyerVatNumber}</ram:ID>
    </ram:SpecifiedTaxRegistration>

XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">

    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>{$profileId}</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>

    <rsm:ExchangedDocument>
        <ram:ID>{$invoiceNumber}</ram:ID>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">{$issueDate}</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>

    <rsm:SupplyChainTradeTransaction>

{$linesXml}

        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>{$sellerName}</ram:Name>

                <ram:PostalTradeAddress>
                    <ram:PostcodeCode>{$sellerZip}</ram:PostcodeCode>
                    <ram:LineOne>{$sellerAddress}</ram:LineOne>
                    <ram:CityName>{$sellerCity}</ram:CityName>
                    <ram:CountryID>{$sellerCountry}</ram:CountryID>
                </ram:PostalTradeAddress>

                <ram:SpecifiedTaxRegistration>
                    <ram:ID schemeID="VA">{$sellerVatNumber}</ram:ID>
                </ram:SpecifiedTaxRegistration>
            </ram:SellerTradeParty>

 <ram:BuyerTradeParty>
    <ram:Name>{$buyerName}</ram:Name>

    <ram:PostalTradeAddress>
        <ram:PostcodeCode>{$buyerZip}</ram:PostcodeCode>
        <ram:LineOne>{$buyerAddress}</ram:LineOne>
        <ram:CityName>{$buyerCity}</ram:CityName>
        <ram:CountryID>{$buyerCountry}</ram:CountryID>
    </ram:PostalTradeAddress>

    {$buyerTaxXml}

</ram:BuyerTradeParty>

        </ram:ApplicableHeaderTradeAgreement>

        <ram:ApplicableHeaderTradeDelivery />

        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>{$currency}</ram:InvoiceCurrencyCode>

            <ram:ApplicableTradeTax>
                <ram:CalculatedAmount>{$totalVat}</ram:CalculatedAmount>
                <ram:TypeCode>VAT</ram:TypeCode>
                <ram:BasisAmount>{$totalHt}</ram:BasisAmount>
                <ram:CategoryCode>S</ram:CategoryCode>
                <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
            </ram:ApplicableTradeTax>

            <ram:SpecifiedTradePaymentTerms>
                <ram:DueDateDateTime>
                    <udt:DateTimeString format="102">{$dueDate}</udt:DateTimeString>
                </ram:DueDateDateTime>
            </ram:SpecifiedTradePaymentTerms>

            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>{$totalHt}</ram:LineTotalAmount>
                <ram:TaxBasisTotalAmount>{$totalHt}</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="EUR">{$totalVat}</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>{$totalTtc}</ram:GrandTotalAmount>
                <ram:DuePayableAmount>{$totalTtc}</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>

    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }
}
