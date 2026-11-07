<?php

use Tests\Support\CapturedSandboxCii;
use Tests\Support\SandboxCiiChecks as Cii;
use Tests\Support\SyntheticSandboxCii;

dataset('cii sources', ['synthetic' => [SyntheticSandboxCii::class], 'captured SUPER PDP' => [CapturedSandboxCii::class]]);

it('checks the immutable real SUPER PDP response in each independent layer', function (string $layer) {
    $xml = CapturedSandboxCii::xml();
    Cii::$layer(Cii::parse($xml));
    expect(hash('sha256', $xml))->toBe(CapturedSandboxCii::SHA256);
})->with(['structure', 'french', 'amounts']);

it('keeps only reviewed fictitious party data in the captured reference', function () {
    $x = Cii::parse(CapturedSandboxCii::xml());
    $buyer = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';
    foreach ([
        [Cii::SELLER, 'Vendeur entièrement fictif — essai sandbox', '1 rue Fictive', '75001', 'Paris', 'vendeur-fictif@example.invalid'],
        [$buyer, 'Acheteur entièrement fictif — essai sandbox', '2 rue Fictive', '69001', 'Lyon', 'acheteur-fictif@example.invalid'],
    ] as [$party, $name, $street, $postcode, $city, $email]) {
        foreach ([
            '/ram:Name' => $name,
            '/ram:GlobalID' => '00000000000000',
            '/ram:GlobalID/@schemeID' => '0009',
            '/ram:SpecifiedLegalOrganization/ram:ID' => '000000000',
            '/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => '0002',
            '/ram:SpecifiedTaxRegistration/ram:ID' => 'FR00000000000',
            '/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' => 'VA',
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
    expect($x->query('//comment() | //processing-instruction() | //ram:AttachmentBinaryObject | //ram:PayeeParty | //ram:PayeePartyCreditorFinancialAccount | //ram:PayerPartyDebtorFinancialAccount')->length)->toBe(0);
    expect(Cii::one($x, Cii::LINE.'/ram:SpecifiedTradeProduct/ram:Name'))->toBe('Prestation fictive de test — aucune opération commerciale');
    expect(Cii::one($x, Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'))->toBe('20261206');
    expect(Cii::one($x, Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString/@format'))->toBe('102');
});

it('checks the handwritten XML by namespace and CII location in three separate layers', function () {
    $x = Cii::parse(SyntheticSandboxCii::xml());
    Cii::structure($x);
    Cii::french($x);
    Cii::amounts($x);
    expect($x->document->doctype)->toBeNull();
});

it('accepts alternate namespace prefixes without changing the namespace URIs', function () {
    $xml = str_replace(['rsm:', 'xmlns:rsm=', 'ram:', 'xmlns:ram=', 'udt:', 'xmlns:udt='], ['i:', 'xmlns:i=', 'a:', 'xmlns:a=', 'u:', 'xmlns:u='], SyntheticSandboxCii::xml());
    $x = Cii::parse($xml);
    Cii::structure($x);
    Cii::french($x);
    Cii::amounts($x);
    expect(Cii::one($x, Cii::DOCUMENT.'/ram:TypeCode'))->toBe('380');
});

it('refuses malformed XML DTDs and external or internal entities before resolution', function (string $xml) {
    expect(fn () => Cii::parse($xml))->toThrow(RuntimeException::class, 'structure:');
})->with([
    '', '<CrossIndustryInvoice>', '<CrossIndustryInvoice/>',
    '<?xml version="1.0"?><!DOCTYPE invoice SYSTEM "file:///unread-local-file"><invoice/>',
    '<!DOCTYPE invoice [<!ENTITY x SYSTEM "https://example.invalid/no-network">]><invoice>&x;</invoice>',
    '<!DOCTYPE invoice [<!ENTITY x "expanded">]><invoice>&x;</invoice>',
    "<\0invoice/>",
]);

it('rejects wrong namespace URIs even when all local names and values are preserved', function (string $uri, string $source) {
    $xml = str_replace($uri, 'urn:incorrect', $source::xml());
    expect(function () use ($xml) {
        Cii::structure(Cii::parse($xml));
    })->toThrow(RuntimeException::class, 'structure:');
})->with(array_values(Cii::NS))->with('cii sources');

dataset('cii required nodes', [
    [Cii::DOCUMENT.'/ram:ID', 'structure'],
    [Cii::DOCUMENT.'/ram:TypeCode', 'structure'],
    [Cii::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString', 'structure'],
    [Cii::SELLER.'/ram:GlobalID', 'structure'],
    [Cii::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID', 'structure'],
    [Cii::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID', 'structure'],
    [Cii::LINE, 'structure'],
    [Cii::TAX, 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='PMT']", 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='PMD']", 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='AAB']", 'french'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', 'amounts'],
]);

it('rejects missing duplicate and misplaced required elements', function (string $path, string $layer, string $mutation, string $source) {
    $x = Cii::parse($source::xml());
    $node = $x->query($path)->item(0);
    if ($mutation === 'remove') {
        $node->parentNode->removeChild($node);
    } elseif ($mutation === 'duplicate') {
        $node->parentNode->appendChild($node->cloneNode(true));
    } else {
        // The value still exists in the XML but at a wrong CII location.
        $x->document->documentElement->appendChild($node);
    }
    expect(fn () => Cii::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with('cii required nodes')->with(['remove', 'duplicate', 'move'])->with('cii sources');

it('rejects inconsistent values in the appropriate layer', function (string $path, string $value, string $layer, string $source) {
    $x = Cii::parse($source::xml());
    $x->query($path)->item(0)->nodeValue = $value;
    expect(fn () => Cii::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with([
    [Cii::DOCUMENT.'/ram:TypeCode', '381', 'structure'],
    [Cii::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString', '20260230', 'structure'],
    [Cii::SELLER.'/ram:GlobalID/@schemeID', '0002', 'structure'],
    [Cii::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'B1', 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='PMT']/ram:Content", ' ', 'french'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '119.99', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', '119.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', '99.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '19.99', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', '19.99', 'amounts'],
    [Cii::TAX.'/ram:RateApplicablePercent', '10.00', 'amounts'],
    [Cii::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '2', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '120.00000000000000001', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '1.2e2', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '120,00', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '-120.00', 'amounts'],
])->with('cii sources');

it('rejects a nonzero prepayment or rounding adjustment for this unpaid fixture', function (string $name, string $source) {
    $x = Cii::parse($source::xml());
    $node = $x->document->createElementNS(Cii::NS['ram'], 'ram:'.$name, '0.01');
    $x->query(Cii::TOTALS)->item(0)->appendChild($node);
    expect(fn () => Cii::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
})->with(['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount'])->with('cii sources');

it('uses exact decimals while accepting equivalent lexical scales', function (string $source) {
    $x = Cii::parse($source::xml());
    $x->query(Cii::TOTALS.'/ram:GrandTotalAmount')->item(0)->nodeValue = '120.0000';
    Cii::amounts($x);
    expect(Cii::one($x, Cii::TOTALS.'/ram:GrandTotalAmount'))->toBe('120.0000');
})->with('cii sources');

it('rejects an inconsistent price basis in an in-memory copy of the real response', function (string $value) {
    $x = Cii::parse(CapturedSandboxCii::xml());
    $path = Cii::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity';
    $x->query($path)->item(0)->nodeValue = $value;
    expect(fn () => Cii::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
})->with(['0', '2', '1.00000000000000001']);
