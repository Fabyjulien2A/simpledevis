<?php

use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\Http;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SandboxCiiChecks;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $data['buyer'] += ['company_name' => 'Acheteur historique', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554'];
    $data['buyer']['client_type'] = 'professional';
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data,
        ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], SuperPdpPayloadFixture::frenchContext());
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $this->checks = new CiiDocumentChecks;
});

afterEach(function () {
    Http::assertNothingSent();
});

it('checks the simulated converted document and keeps the captured reference immutable', function () {
    $this->checks->assertMatches($this->xml, $this->payload);
    $this->checks->assertReport(PreparedCiiFixture::report($this->xml), $this->xml);
    expect(hash('sha256', CapturedSandboxCii::xml()))->toBe(CapturedSandboxCii::SHA256);
});

it('rejects unsafe malformed and unrelated documents without resolving entities', function (string $xml) {
    expect(fn () => $this->checks->assertMatches($xml, $this->payload))->toThrow(RuntimeException::class);
})->with([
    '', '<x/>', '<CrossIndustryInvoice/>', '<broken>', "<\0x/>",
    '<!DOCTYPE x SYSTEM "https://example.invalid/no-network"><x/>',
    '<!DOCTYPE x [<!ENTITY s SYSTEM "file:///not-read">]><x>&s;</x>',
    '<!DOCTYPE x [<!ENTITY s "expanded">]><x>&s;</x>',
]);

it('binds identities routing fiscal details lines and amounts to the existing payload', function (string $path, string $value) {
    $x = SandboxCiiChecks::parse($this->xml);
    PreparedCiiFixture::set($x, $path, $value);
    expect(fn () => $this->checks->assertMatches($x->document->saveXML(), $this->payload))->toThrow(RuntimeException::class);
})->with([
    [SandboxCiiChecks::DOCUMENT.'/ram:ID', 'OTHER-INVOICE'],
    [SandboxCiiChecks::DOCUMENT.'/ram:TypeCode', '381'],
    [SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString', '20260101'],
    [SandboxCiiChecks::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID', 'B1'],
    [SandboxCiiChecks::SELLER.'/ram:Name', 'Another seller'],
    [SandboxCiiChecks::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID', '552100554'],
    [SandboxCiiChecks::SELLER.'/ram:URIUniversalCommunication/ram:URIID', 'other@example.invalid'],
    [SandboxCiiChecks::SELLER.'/ram:URIUniversalCommunication/ram:URIID/@schemeID', 'EM'],
    [SandboxCiiChecks::SELLER.'/ram:PostalTradeAddress/ram:CountryID', 'BE'],
    [SandboxCiiChecks::LINE.'/ram:SpecifiedTradeProduct/ram:Name', 'Other item'],
    [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '2.00'],
    [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', 'HUR'],
    [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', '10.00'],
    [SandboxCiiChecks::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', 'E'],
    [SandboxCiiChecks::TAX.'/ram:RateApplicablePercent', '5.50'],
    [SandboxCiiChecks::TOTALS.'/ram:GrandTotalAmount', '120.01'],
    [SandboxCiiChecks::TOTALS.'/ram:DuePayableAmount', '0.00'],
    [SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD'],
    [SandboxCiiChecks::TOTALS.'/ram:TaxTotalAmount', '2e1'],
]);

it('rejects missing and duplicate critical nodes', function (string $mode) {
    $x = SandboxCiiChecks::parse($this->xml);
    $node = $x->query(SandboxCiiChecks::TOTALS.'/ram:GrandTotalAmount')->item(0);
    if ($mode === 'missing') {
        $node->parentNode->removeChild($node);
    } else {
        $node->parentNode->appendChild($node->cloneNode(true));
    }
    expect(fn () => $this->checks->assertMatches($x->document->saveXML(), $this->payload))->toThrow(RuntimeException::class);
})->with(['missing', 'duplicate']);

it('refuses insufficient or inconsistent provider validation reports', function (string $case) {
    $report = PreparedCiiFixture::report($this->xml);
    match ($case) {
        'empty' => $report['data'] = [],
        'multiple' => $report['data'][] = $report['data'][0],
        'name' => $report['data'][0]['file_name'] = 'other.xml',
        'size' => $report['data'][0]['file_size']++,
        'invalid' => $report['data'][0]['is_valid'] = false,
        'string_bool' => $report['data'][0]['is_valid'] = 'true',
        'format' => $report['data'][0]['format'] = 'ubl',
        'level' => $report['data'][0]['conformance_level'] = '',
        'duration' => $report['data'][0]['duration'] = -1,
        'error' => $report['data'][0]['error'] = 'failed',
        'subreports' => $report['data'][0]['subreports'] = [],
        'no_checks' => $report['data'][0]['subreports'][0]['checks_count'] = 0,
        'failures' => $report['data'][0]['subreports'][0]['failures'] = [['message' => 'failure']],
    };
    expect(fn () => $this->checks->assertReport($report, $this->xml))->toThrow(RuntimeException::class);
})->with(['empty', 'multiple', 'name', 'size', 'invalid', 'string_bool', 'format', 'level', 'duration', 'error', 'subreports', 'no_checks', 'failures']);
