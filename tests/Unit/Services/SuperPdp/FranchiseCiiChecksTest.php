<?php

use Tests\Support\CapturedFranchiseCii;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\FranchiseCiiChecks as Franchise;
use Tests\Support\SandboxCiiChecks as Cii;

it('checks each independent layer of the unchanged real franchise response', function (string $layer) {
    Franchise::$layer(Cii::parse(CapturedFranchiseCii::xml()));
    expect(hash_file('sha256', CapturedFranchiseCii::PATH))->toBe(CapturedFranchiseCii::SHA256);
})->with(['structure', 'french', 'amounts']);

it('preserves both real reference files and the original taxable checks', function () {
    foreach (['structure', 'french', 'amounts'] as $layer) {
        Cii::$layer(Cii::parse(CapturedSandboxCii::xml()));
        Franchise::$layer(Cii::parse(CapturedFranchiseCii::xml()));
    }
    expect(hash_file('sha256', CapturedSandboxCii::PATH))->toBe(CapturedSandboxCii::SHA256)
        ->and(hash_file('sha256', CapturedFranchiseCii::PATH))->toBe(CapturedFranchiseCii::SHA256);
});

it('contains only the reviewed fictitious parties and no ancillary sensitive data', function () {
    $x = Cii::parse(CapturedFranchiseCii::xml());
    $buyer = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';
    foreach ([
        [Cii::SELLER, 'Vendeur entièrement fictif — franchise sandbox', '1 rue Fictive', '75001', 'Paris', 'vendeur-fictif@example.invalid'],
        [$buyer, 'Acheteur entièrement fictif — essai sandbox', '2 rue Fictive', '69001', 'Lyon', 'acheteur-fictif@example.invalid'],
    ] as [$party, $name, $street, $postcode, $city, $email]) {
        foreach ([
            '/ram:Name' => $name,
            '/ram:GlobalID' => '00000000000000',
            '/ram:GlobalID/@schemeID' => '0009',
            '/ram:SpecifiedLegalOrganization/ram:ID' => '000000000',
            '/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => '0002',
            '/ram:PostalTradeAddress/ram:LineOne' => $street,
            '/ram:PostalTradeAddress/ram:PostcodeCode' => $postcode,
            '/ram:PostalTradeAddress/ram:CityName' => $city,
            '/ram:PostalTradeAddress/ram:CountryID' => 'FR',
            '/ram:URIUniversalCommunication/ram:URIID' => $email,
            '/ram:URIUniversalCommunication/ram:URIID/@schemeID' => 'EM',
        ] as $path => $expected) {
            expect(Cii::one($x, $party.$path))->toBe($expected);
        }
    }
    expect($x->query(Cii::SELLER."/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']")->length)->toBe(0);
    expect(Cii::one($x, $buyer.'/ram:SpecifiedTaxRegistration/ram:ID'))->toBe('FR00000000000');
    expect(Cii::one($x, Cii::LINE.'/ram:SpecifiedTradeProduct/ram:Name'))->toBe('Prestation fictive en franchise — aucune opération commerciale');
    expect(Cii::one($x, Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'))->toBe('20261206');
    expect($x->query('//comment() | //processing-instruction() | //ram:AttachmentBinaryObject | //ram:PayeeParty | //ram:PayeePartyCreditorFinancialAccount | //ram:PayerPartyDebtorFinancialAccount')->length)->toBe(0);
});

dataset('franchise required CII nodes', [
    [Cii::DOCUMENT.'/ram:ID', 'structure'],
    [Franchise::BT30, 'structure'], [Franchise::BT32, 'structure'],
    [Cii::LINE, 'structure'], [Cii::TAX, 'structure'],
    [Franchise::LINE_TAX.'/ram:CategoryCode', 'structure'],
    [Cii::TAX.'/ram:CategoryCode', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'french'],
    [Franchise::LINE_TAX.'/ram:ExemptionReasonCode', 'french'],
    [Franchise::LINE_TAX.'/ram:ExemptionReason', 'french'],
    [Cii::TAX.'/ram:ExemptionReasonCode', 'french'],
    [Cii::TAX.'/ram:ExemptionReason', 'french'],
    [Cii::TAX.'/ram:RateApplicablePercent', 'amounts'],
    [Franchise::LINE_TAX.'/ram:RateApplicablePercent', 'amounts'],
    [Cii::TAX.'/ram:BasisAmount', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', 'amounts'],
]);

it('rejects missing duplicate or misplaced elements on in-memory copies only', function (string $path, string $layer, string $mutation) {
    $x = Cii::parse(CapturedFranchiseCii::xml());
    $node = $x->query($path)->item(0);
    if ($mutation === 'remove') {
        $node->parentNode->removeChild($node);
    } elseif ($mutation === 'duplicate') {
        $node->parentNode->appendChild($node->cloneNode(true));
    } else {
        $x->document->documentElement->appendChild($node);
    }
    expect(fn () => Franchise::$layer($x))->toThrow(RuntimeException::class, $layer.':');
    expect(hash_file('sha256', CapturedFranchiseCii::PATH))->toBe(CapturedFranchiseCii::SHA256);
})->with('franchise required CII nodes')->with(['remove', 'duplicate', 'move']);

it('rejects incorrect fiscal identifiers categories reasons rates and amounts', function (string $path, string $value, string $layer) {
    $x = Cii::parse(CapturedFranchiseCii::xml());
    $x->query($path)->item(0)->nodeValue = $value;
    expect(fn () => Franchise::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with([
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'B1', 'french'],
    [Franchise::BT30, '000000001', 'structure'],
    [Franchise::BT30.'/@schemeID', 'FC', 'structure'],
    [Franchise::BT32, 'FR000000000', 'structure'],
    [Franchise::BT32, '000000001', 'structure'],
    [Franchise::BT32.'/@schemeID', 'VA', 'structure'],
    [Franchise::BT32.'/@schemeID', '0002', 'structure'],
    [Franchise::LINE_TAX.'/ram:CategoryCode', 'Z', 'structure'],
    [Cii::TAX.'/ram:CategoryCode', 'Z', 'structure'],
    [Cii::TAX.'/ram:CategoryCode', 'S', 'structure'],
    [Franchise::LINE_TAX.'/ram:ExemptionReasonCode', 'VATEX-EU-132', 'french'],
    [Cii::TAX.'/ram:ExemptionReasonCode', 'VATEX-EU-132', 'french'],
    [Cii::TAX.'/ram:ExemptionReason', 'VATEX-FR-FRANCHISE', 'french'],
    [Franchise::LINE_TAX.'/ram:ExemptionReason', ' ', 'french'],
    [Cii::TAX.'/ram:RateApplicablePercent', '20.00', 'amounts'],
    [Franchise::LINE_TAX.'/ram:RateApplicablePercent', '20.00', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', '0.01', 'amounts'],
    [Cii::TAX.'/ram:BasisAmount', '99.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', '99.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '0.01', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '100.01', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', '99.99', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '100.00000000000000001', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '1e2', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '100,00', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '-0.01', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD', 'structure'],
    [Cii::SETTLEMENT.'/ram:InvoiceCurrencyCode', 'USD', 'structure'],
    [Cii::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '2', 'amounts'],
]);

it('rejects nonzero adjustments for this unpaid unadjusted franchise fixture', function (string $name) {
    $x = Cii::parse(CapturedFranchiseCii::xml());
    $x->query(Cii::TOTALS)->item(0)->appendChild($x->document->createElementNS(Cii::NS['ram'], 'ram:'.$name, '0.01'));
    expect(fn () => Franchise::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
})->with(['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount']);

it('rejects altered namespace URIs in real franchise XML copies', function (string $uri) {
    $xml = str_replace($uri, 'urn:incorrect', CapturedFranchiseCii::xml());
    expect(function () use ($xml) {
        $x = Cii::parse($xml);
        Franchise::structure($x);
    })->toThrow(RuntimeException::class, 'structure:');
})->with(array_values(Cii::NS));

it('accepts equivalent exact decimal scales without rewriting the fixture', function () {
    $x = Cii::parse(CapturedFranchiseCii::xml());
    $x->query(Cii::TOTALS.'/ram:TaxTotalAmount')->item(0)->nodeValue = '0.0000';
    $x->query(Cii::TOTALS.'/ram:GrandTotalAmount')->item(0)->nodeValue = '100.0000';
    Franchise::amounts($x);
    expect(hash_file('sha256', CapturedFranchiseCii::PATH))->toBe(CapturedFranchiseCii::SHA256);
});
