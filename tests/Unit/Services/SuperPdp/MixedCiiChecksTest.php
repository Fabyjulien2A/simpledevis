<?php

use Tests\Support\CapturedFranchiseCii;
use Tests\Support\CapturedMixedCii;
use Tests\Support\CapturedMultiRateCii;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\FranchiseCiiChecks;
use Tests\Support\MixedCiiChecks as M1;
use Tests\Support\MultiRateCiiChecks;
use Tests\Support\SandboxCiiChecks as Cii;

afterEach(function () {
    foreach ([CapturedSandboxCii::class, CapturedFranchiseCii::class, CapturedMultiRateCii::class, CapturedMixedCii::class] as $source) {
        expect(hash_file('sha256', $source::PATH))->toBe($source::SHA256);
    }
});

it('checks each independent layer of the actual M1 response', function (string $layer) {
    M1::$layer(Cii::parse(CapturedMixedCii::xml()));
})->with(['structure', 'french', 'amounts']);

it('preserves all four independent captured references and their checks', function () {
    foreach ([CapturedSandboxCii::class => Cii::class, CapturedFranchiseCii::class => FranchiseCiiChecks::class, CapturedMultiRateCii::class => MultiRateCiiChecks::class, CapturedMixedCii::class => M1::class] as $source => $checks) {
        foreach (['structure', 'french', 'amounts'] as $layer) {
            $checks::$layer(Cii::parse($source::xml()));
        }
        expect(hash('sha256', $source::xml()))->toBe($source::SHA256);
    }
});

dataset('mixed M1 required nodes', [
    [Cii::DOCUMENT.'/ram:ID', 'structure'],
    [M1::line(1), 'structure'], [M1::line(2), 'structure'],
    [Cii::TAX, 'structure'],
    [M1::lineTax(1).'/ram:CategoryCode', 'structure'],
    [M1::lineTax(2).'/ram:CategoryCode', 'structure'],
    [Cii::TAX.'/ram:CategoryCode', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='PMT']", 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='PMD']", 'french'],
    [Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='AAB']", 'french'],
    [M1::lineTax(1).'/ram:RateApplicablePercent', 'amounts'],
    [M1::lineTax(2).'/ram:RateApplicablePercent', 'amounts'],
    [Cii::TAX.'/ram:RateApplicablePercent', 'amounts'],
    [Cii::TAX.'/ram:BasisAmount', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', 'amounts'],
    [Cii::TOTALS.'/ram:LineTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', 'amounts'],
]);

it('rejects missing duplicated or misplaced M1 nodes on DOM copies only', function (string $path, string $layer, string $mutation) {
    $x = Cii::parse(CapturedMixedCii::xml());
    $node = $x->query($path)->item(0);
    if ($mutation === 'remove') {
        $node->parentNode->removeChild($node);
    } elseif ($mutation === 'duplicate') {
        $node->parentNode->appendChild($node->cloneNode(true));
    } else {
        $x->document->documentElement->appendChild($node);
    }
    expect(fn () => M1::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with('mixed M1 required nodes')->with(['remove', 'duplicate', 'move']);

it('rejects incorrect M1 identity descriptions tax categories rates and amounts on copies', function (string $path, string $value, string $layer) {
    $x = Cii::parse(CapturedMixedCii::xml());
    $x->query($path)->item(0)->nodeValue = $value;
    expect(fn () => M1::$layer($x))->toThrow(RuntimeException::class, $layer.':');
})->with([
    [Cii::DOCUMENT.'/ram:ID', 'SANDBOX-FICTIF-MULTITAUX-0003', 'structure'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'S1', 'french'],
    [Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'B1', 'french'],
    [M1::line(2).'/ram:AssociatedDocumentLineDocument/ram:LineID', '1', 'structure'],
    [M1::line(1).'/ram:SpecifiedTradeProduct/ram:Name', 'Installation', 'structure'],
    [M1::line(2).'/ram:SpecifiedTradeProduct/ram:Name', 'Fourniture', 'structure'],
    [M1::lineTax(1).'/ram:CategoryCode', 'E', 'structure'],
    [M1::lineTax(2).'/ram:CategoryCode', 'Z', 'structure'],
    [Cii::TAX.'/ram:CategoryCode', 'E', 'structure'],
    [M1::lineTax(1).'/ram:RateApplicablePercent', '10.00', 'amounts'],
    [M1::lineTax(2).'/ram:RateApplicablePercent', '10.00', 'amounts'],
    [Cii::TAX.'/ram:RateApplicablePercent', '10.00', 'amounts'],
    [M1::line(1).'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '150', 'amounts'],
    [M1::line(2).'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '100', 'amounts'],
    [M1::line(1).'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '99.99', 'amounts'],
    [M1::line(2).'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '150.01', 'amounts'],
    [Cii::TAX.'/ram:BasisAmount', '249.99', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', '49.99', 'amounts'],
    [Cii::TOTALS.'/ram:LineTotalAmount', '249.99', 'amounts'],
    [Cii::TOTALS.'/ram:TaxBasisTotalAmount', '250.01', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '50.01', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '299.99', 'amounts'],
    [Cii::TOTALS.'/ram:DuePayableAmount', '300.01', 'amounts'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '300.00000000000000001', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount', '5e1', 'amounts'],
    [Cii::TAX.'/ram:BasisAmount', '250,00', 'amounts'],
    [Cii::TAX.'/ram:CalculatedAmount', '-50', 'amounts'],
    [Cii::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD', 'structure'],
    [Cii::SETTLEMENT.'/ram:InvoiceCurrencyCode', 'USD', 'structure'],
    [M1::line(2).'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '2', 'amounts'],
    [M1::line(2).'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', '2', 'amounts'],
]);

it('rejects redistributed line amounts even when the HT sum stays 250', function () {
    $x = Cii::parse(CapturedMixedCii::xml());
    foreach ([1 => '110', 2 => '140'] as $id => $amount) {
        foreach (['/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'] as $suffix) {
            $x->query(M1::line($id).$suffix)->item(0)->nodeValue = $amount;
        }
    }
    expect(fn () => M1::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
});

it('rejects exemptions at every tax location on copies', function (string $target, string $name) {
    $x = Cii::parse(CapturedMixedCii::xml());
    $x->query($target)->item(0)->appendChild($x->document->createElementNS(Cii::NS['ram'], 'ram:'.$name, 'Unexpected exemption'));
    expect(fn () => M1::structure($x))->toThrow(RuntimeException::class, 'structure:');
})->with([M1::lineTax(1), M1::lineTax(2), Cii::TAX])->with(['ExemptionReason', 'ExemptionReasonCode']);

it('rejects nonzero adjustments on copies of the unpaid M1 reference', function (string $name) {
    $x = Cii::parse(CapturedMixedCii::xml());
    $x->query(Cii::TOTALS)->item(0)->appendChild($x->document->createElementNS(Cii::NS['ram'], 'ram:'.$name, '0.01'));
    expect(fn () => M1::amounts($x))->toThrow(RuntimeException::class, 'amounts:');
})->with(['TotalPrepaidAmount', 'RoundingAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount']);

it('rejects incorrect namespace URIs in strings held in memory', function (string $uri) {
    $xml = str_replace($uri, 'urn:incorrect', CapturedMixedCii::xml());
    expect(fn () => M1::structure(Cii::parse($xml)))->toThrow(RuntimeException::class, 'structure:');
})->with(array_values(Cii::NS));

it('accepts equivalent decimal scales and line reordering without rewriting the original', function () {
    $x = Cii::parse(CapturedMixedCii::xml());
    $line = $x->query(M1::line(1))->item(0);
    $line->parentNode->appendChild($line);
    $x->query(Cii::TAX.'/ram:RateApplicablePercent')->item(0)->nodeValue = '20.0000';
    $x->query(Cii::TOTALS.'/ram:GrandTotalAmount')->item(0)->nodeValue = '300.0000';
    foreach (['structure', 'french', 'amounts'] as $layer) {
        M1::$layer($x);
    }
});
