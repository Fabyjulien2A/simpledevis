<?php

use Tests\Support\CapturedFranchiseCii;
use Tests\Support\CapturedMultiRateCii;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\FranchiseCiiChecks;
use Tests\Support\MultiRateCiiChecks as Multi;
use Tests\Support\SandboxCiiChecks as Cii;

it('checks each independent layer of the actual multi-rate response', function (string $layer) {
    Multi::$layer(Cii::parse(CapturedMultiRateCii::xml()));
    expect(hash_file('sha256', CapturedMultiRateCii::PATH))->toBe(CapturedMultiRateCii::SHA256);
})->with(['structure', 'french', 'amounts']);

it('preserves all three captured references and their original checks', function () {
    foreach ([CapturedSandboxCii::class => Cii::class, CapturedFranchiseCii::class => FranchiseCiiChecks::class, CapturedMultiRateCii::class => Multi::class] as $source => $checks) {
        foreach (['structure', 'french', 'amounts'] as $layer) {
            $checks::$layer(Cii::parse($source::xml()));
        }
        expect(hash_file('sha256', $source::PATH))->toBe($source::SHA256);
    }
});

it('retains only the reviewed fictitious party data and no ancillary sensitive data', function () {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $buyer = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';
    foreach ([
        [Cii::SELLER, 'Vendeur entièrement fictif — essai multi-taux', '1 rue Fictive', '75001', 'Paris', 'vendeur-fictif@example.invalid'],
        [$buyer, 'Acheteur entièrement fictif — essai multi-taux', '2 rue Fictive', '69001', 'Lyon', 'acheteur-fictif@example.invalid'],
    ] as [$party, $name, $street, $postcode, $city, $email]) {
        foreach ([
            '/ram:Name' => $name,
            '/ram:GlobalID' => '00000000000000', '/ram:GlobalID/@schemeID' => '0009',
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
    expect(Cii::one($x, Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'))->toBe('20261206');
});

dataset('multi-rate required CII nodes', [
    [Cii::DOCUMENT.'/ram:ID', 'structure'],
    [Multi::line(1), 'structure'], [Multi::line(2), 'structure'],
    [Multi::tax(20), 'structure'], [Multi::tax(10), 'structure'],
    [Multi::lineTax(1).'/ram:CategoryCode', 'structure'],
    [Multi::lineTax(2).'/ram:CategoryCode', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'french'],
    [Multi::lineTax(1).'/ram:RateApplicablePercent', 'amounts'],
    [Multi::lineTax(2).'/ram:RateApplicablePercent', 'amounts'],
    [Multi::tax(20).'/ram:BasisAmount', 'amounts'],
    [Multi::tax(10).'/ram:BasisAmount', 'amounts'],
    [Multi::tax(20).'/ram:CalculatedAmount', 'amounts'],
    [Multi::tax(10).'/ram:CalculatedAmount', 'amounts'],
    [Cii::TOTALS.'/ram:LineTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', 'amounts'],
]);

it('rejects missing duplicated or misplaced nodes on copies in memory', function (string $path, string $layer, string $mutation) {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $node = $x->query($path)->item(0);
    if ($mutation === 'remove') {
        $node->parentNode->removeChild($node);
    } elseif ($mutation === 'duplicate') {
        $node->parentNode->appendChild($node->cloneNode(true));
    } else {
        $x->document->documentElement->appendChild($node);
    }
    expect(fn () => Multi::$layer($x))->toThrow(RuntimeException::class, $layer.':');
    expect(hash_file('sha256', CapturedMultiRateCii::PATH))->toBe(CapturedMultiRateCii::SHA256);
})->with('multi-rate required CII nodes')->with(['remove', 'duplicate', 'move']);

it('rejects incorrect identities rates categories currencies and exact monetary values', function (string $path, string $value, string $layer) {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $x->query($path)->item(0)->nodeValue = $value;
    expect(fn () => Multi::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with([
    [Cii::DOCUMENT.'/ram:ID', 'SANDBOX-FICTIF-0001', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'M1', 'french'],
    [Multi::line(2).'/ram:AssociatedDocumentLineDocument/ram:LineID', '1', 'structure'],
    [Multi::lineTax(1).'/ram:CategoryCode', 'E', 'structure'],
    [Multi::lineTax(2).'/ram:CategoryCode', 'Z', 'structure'],
    [Multi::tax(10).'/ram:CategoryCode', 'E', 'structure'],
    [Multi::lineTax(1).'/ram:RateApplicablePercent', '10.00', 'amounts'],
    [Multi::lineTax(2).'/ram:RateApplicablePercent', '20.00', 'amounts'],
    [Multi::tax(10).'/ram:RateApplicablePercent', '20.00', 'structure'],
    [Multi::tax(20).'/ram:BasisAmount', '99.99', 'amounts'],
    [Multi::tax(10).'/ram:BasisAmount', '99.99', 'amounts'],
    [Multi::tax(20).'/ram:CalculatedAmount', '19.99', 'amounts'],
    [Multi::tax(10).'/ram:CalculatedAmount', '10.01', 'amounts'],
    [Cii::TOTALS.'/ram:LineTotalAmount', '100.00', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', '199.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '29.99', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '229.99', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', '230.01', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '230.00000000000000001', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '3e1', 'amounts'],
    [Multi::tax(10).'/ram:BasisAmount', '100,00', 'amounts'],
    [Multi::tax(10).'/ram:CalculatedAmount', '-10', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD', 'structure'],
    [Cii::SETTLEMENT.'/ram:InvoiceCurrencyCode', 'USD', 'structure'],
    [Multi::line(2).'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '2', 'amounts'],
    [Multi::line(2).'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '2', 'amounts'],
]);

it('rejects shifted VAT between breakdowns even when the overall VAT total is unchanged', function () {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $x->query(Multi::tax(20).'/ram:CalculatedAmount')->item(0)->nodeValue = '19.00';
    $x->query(Multi::tax(10).'/ram:CalculatedAmount')->item(0)->nodeValue = '11.00';
    expect(fn () => Multi::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
});

it('rejects exemption reasons and nonzero adjustments for this taxable unpaid reference', function (string $name) {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $reason = str_starts_with($name, 'Exemption');
    $target = $reason ? Multi::tax(10) : Cii::TOTALS;
    $x->query($target)->item(0)->appendChild($x->document->createElementNS(Cii::NS['ram'], 'ram:'.$name, $reason ? 'Unexpected exemption' : '0.01'));
    $layer = $reason ? 'structure' : 'amounts';
    expect(fn () => Multi::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with(['ExemptionReason', 'ExemptionReasonCode', 'TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount']);

it('rejects incorrect namespace URIs on copies of the captured multi-rate response', function (string $uri) {
    $xml = str_replace($uri, 'urn:incorrect', CapturedMultiRateCii::xml());
    expect(fn () => Multi::structure(Cii::parse($xml)))->toThrow(RuntimeException::class, 'structure:');
})->with(array_values(Cii::NS));

it('accepts equivalent decimal scales and changed breakdown order without rewriting the reference', function () {
    $x = Cii::parse(CapturedMultiRateCii::xml());
    $tax = $x->query(Multi::tax(20))->item(0);
    $tax->parentNode->appendChild($tax);
    $x->query(Multi::tax(10).'/ram:RateApplicablePercent')->item(0)->nodeValue = '10.0000';
    $x->query(Cii::TOTALS.'/ram:GrandTotalAmount')->item(0)->nodeValue = '230.0000';
    Multi::structure($x);
    Multi::amounts($x);
    expect(hash_file('sha256', CapturedMultiRateCii::PATH))->toBe(CapturedMultiRateCii::SHA256);
});
