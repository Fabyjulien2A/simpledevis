<?php

use App\Services\InvoiceAmountCalculator;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\InvoiceFiscalItemTranslator;
use App\Services\InvoiceLinePreparationService;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\Sandbox\BusinessInvoiceFixture;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Saloon\Config;
use SimpleDevis\Tools\SuperPdp\CiiDiagnostic;
use SimpleDevis\Tools\SuperPdp\CiiProbeInput;
use SimpleDevis\Tools\SuperPdp\CiiProbeStorage;

require_once dirname(__DIR__, 3).'/tools/support/SuperPdpCiiDiagnostic.php';

beforeEach(function () {
    $this->previousResolver = Model::getConnectionResolver();
    $this->previousValidator = Validator::getFacadeRoot();
    Validator::swap(new Factory(new Translator(new ArrayLoader, 'fr')));
    $connection = new Connection(static fn () => throw new RuntimeException('database_forbidden'));
    $connection->beforeExecuting(static fn () => throw new RuntimeException('database_forbidden'));
    $resolver = new ConnectionResolver(['offline' => $connection]);
    $resolver->setDefaultConnection('offline');
    Model::setConnectionResolver($resolver);
    Config::allowStrayRequests(); // Every send below terminates in MockHandler.
    $this->lines = new InvoiceLinePreparationService(new InvoiceAmountCalculator);
    $this->fixture = new BusinessInvoiceFixture($this->lines);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->fixture, new InvoiceFiscalDataPreparer($this->lines),
        new SuperPdpInvoicePayloadValidator(dirname(__DIR__, 3).'/resources/schemas/superpdp/1.39.0.beta/en-invoice.schema.json'));
    $this->source = $this->fixture->buildElectronic($this->fixture->invoice());
    $this->payload = $this->builder->buildFromData($this->source);
    $this->xml = file_get_contents(dirname(__DIR__, 2).'/Fixtures/SuperPdp/business-simulated.cii.xml');
    $this->directory = sys_get_temp_dir().'/sd-business-cii-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    $this->storage = new CiiProbeStorage($this->directory);
    $this->report = ['data' => [[
        'file_name' => 'invoice.xml', 'file_size' => strlen($this->xml), 'is_valid' => true,
        'format' => CiiDocumentChecks::NS, 'conformance_level' => 'synthetic-test-only', 'duration' => 1,
        'subreports' => [['validator' => 'synthetic-test-only', 'checks_count' => 1, 'failures' => [], 'messages' => []]],
    ]]];
});

afterEach(function () {
    $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
    $this->previousValidator === null ? Validator::clearResolvedInstance('validator') : Validator::swap($this->previousValidator);
    foreach (glob($this->directory.'/*') as $run) {
        foreach (glob($run.'/*') as $file) {
            unlink($file);
        }
        rmdir($run);
    }
    rmdir($this->directory);
});

it('maps the business form and frozen parties through the real builder without persistence', function () {
    expect($this->fixture->invoice()->exists)->toBeFalse()->and($this->fixture->invoice()->getKey())->toBeNull();
    expect($this->source['totals'])->toBe(['subtotal_ht' => '200.00', 'total_tva' => '30.00', 'total_ttc' => '230.00']);
    expect(CiiProbeInput::payload('business'))->toBe($this->payload);
    expect($this->payload)->toMatchArray(['number' => 'SANDBOX-METIER-0001', 'issue_date' => '2026-11-11',
        'payment_due_date' => '2026-12-11', 'type_code' => 380, 'currency_code' => 'EUR']);
    foreach (['seller' => ['Vendeur fictif SimpleDevis', '1 rue Fictive', '75001', 'Paris', 'vendeur-fictif@example.invalid'],
        'buyer' => ['Acheteur fictif SimpleDevis', '2 rue Fictive', '69001', 'Lyon', 'acheteur-fictif@example.invalid']] as $role => $values) {
        expect($this->payload[$role])->toMatchArray([
            'name' => $values[0], 'vat_identifier' => 'FR00000000000',
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => '000000000'],
            'electronic_address' => ['scheme' => 'EM', 'value' => $values[4]],
            'postal_address' => ['address_line1' => $values[1], 'post_code' => $values[2], 'city' => $values[3], 'country_code' => 'FR'],
        ]);
    }
    foreach ([['2.50', '40.00', '20.00'], ['4.00', '25.00', '10.00']] as $i => [$quantity, $price, $rate]) {
        expect($this->payload['lines'][$i])->toMatchArray([
            'identifier' => (string) ($i + 1), 'invoiced_quantity' => $quantity, 'invoiced_quantity_code' => 'C62', 'net_amount' => '100.00',
            'item_information' => ['name' => 'Prestation fictive '.($i === 0 ? 'A' : 'B')],
            'price_details' => ['item_net_price' => $price, 'item_price_base_quantity' => '1.00', 'quantity_unit_code' => 'C62'],
            'vat_information' => ['invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => $rate],
        ]);
    }
    expect($this->payload['vat_break_down'])->toBe([
        ['vat_category_code' => 'S', 'vat_category_rate' => '20.00', 'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '20.00'],
        ['vat_category_code' => 'S', 'vat_category_rate' => '10.00', 'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '10.00'],
    ])->and($this->payload['totals'])->toBe([
        'sum_invoice_lines_amount' => '200.00', 'total_without_vat' => '200.00',
        'total_vat_amount' => ['value' => '30.00', 'currency_code' => 'EUR'], 'total_with_vat' => '230.00', 'amount_due_for_payment' => '230.00',
    ]);
});

it('ignores browser amounts and rounds VAT once per category and rate', function () {
    $items = array_fill(0, 2, ['description' => 'Fiction arrondi', 'quantity' => '1.00', 'unit_price_ht' => '0.03',
        'tva_rate' => '20.00', 'tax_treatment' => 'taxable', 'unit_code' => 'C62', 'line_total_ht' => '999.00']);
    $items[] = [...$items[0], 'quantity' => '1.01', 'unit_price_ht' => '0.50'];
    $prepared = $this->lines->prepare((new InvoiceFiscalItemTranslator)->translate($items));
    expect($prepared['items'][2]['line_total_ht'])->toBe('0.51')
        ->and($prepared['totals'])->toBe(['total_ht' => '0.57', 'total_vat' => '0.11', 'total_ttc' => '0.68']);
});

it('rejects contradictory fiscal form choices through the shared translator', function () {
    $items = BusinessInvoiceFixture::formItems();
    $items[0]['vat_category'] = 'E';
    expect(fn () => (new InvoiceFiscalItemTranslator)->translate($items))->toThrow(ValidationException::class);
});

it('refuses altered source amounts before conversion', function (string $field) {
    if ($field === 'line') {
        $this->source['items'][0]['line_total_ht'] = '100.01';
    } else {
        $this->source['totals'][$field] = '999.99';
    }
    expect(fn () => $this->builder->buildFromData($this->source))->toThrow(ValidationException::class);
})->with(['line', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('refuses incomplete or conflicting business evidence', function (string $case) {
    switch ($case) {
        case 'draft': $this->source['document_status'] = 'draft'; break;
        case 'payment': $this->source['payments'] = [1]; break;
        case 'credit': $this->source['issued_credit_notes'] = [1]; break;
        case 'snapshot': $this->source['party_snapshot'] = null; break;
        case 'context': $this->source['emission_context'] = null; break;
        case 'version': $this->source['calculation_version'] = null; break;
        case 'process': $this->source['french_business_process_type'] = null; break;
    }
    expect(fn () => $this->builder->buildFromData($this->source))->toThrow(Exception::class);
})->with(['draft', 'payment', 'credit', 'snapshot', 'context', 'version', 'process']);

it('runs the guarded two-request business simulation and preserves synthetic provenance and hashes', function () {
    $handler = new MockHandler([new Response(200, ['Content-Type' => 'application/xml'], $this->xml),
        new Response(200, ['Content-Type' => 'application/json'], json_encode($this->report))]);
    $result = CiiDiagnostic::simulate($handler, $this->storage, 'business');
    expect($result)->toMatchArray(['fixture_scenario' => 'business', 'mode' => 'simulated', 'requests' => 2,
        'conversion' => 'simulated_success', 'local_cii_consistency' => 'passed', 'validation' => 'simulated_pass', 'ready_for_emission' => false]);
    $manifest = json_decode(file_get_contents($result['artifact_directory'].'/manifest.json'), true);
    expect($manifest)->toMatchArray(['fixture_scenario' => 'business', 'official_archive' => false,
        'payload_sha256' => hash_file('sha256', $result['artifact_directory'].'/input.en16931.json'),
        'cii_sha256' => hash('sha256', $this->xml), 'validation_response_sha256' => hash('sha256', json_encode($this->report))]);
});

it('blocks validation when the synthetic CII differs from the original business data', function (string $path, string $value) {
    $doc = new DOMDocument;
    $doc->loadXML($this->xml, LIBXML_NONET);
    $x = new DOMXPath($doc);
    $x->registerNamespace('rsm', CiiDocumentChecks::NS);
    $x->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $x->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
    $node = $x->query($path)->item(0);
    expect($node)->not->toBeNull();
    $node->nodeValue = $value;
    $xml = $doc->saveXML();
    $handler = new MockHandler([new Response(200, ['Content-Type' => 'application/xml'], $xml)]);
    $result = CiiDiagnostic::simulate($handler, $this->storage, 'business');
    expect($result)->toMatchArray(['requests' => 1, 'local_cii_consistency' => 'failed', 'validation' => 'not_attempted']);
})->with([
    ['//rsm:ExchangedDocument/ram:ID', 'OTHER-REFERENCE'],
    ['//ram:SellerTradeParty/ram:Name', 'Other seller'],
    ['//ram:BuyerTradeParty/ram:Name', 'Other buyer'],
    ['//ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode', '99999'],
    ['//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID', 'other@example.invalid'],
    ['//ram:SpecifiedTradeProduct/ram:Name', 'Other line'],
    ['//ram:BilledQuantity', '2.51'],
    ['//ram:BilledQuantity/@unitCode', 'HUR'],
    ['//ram:NetPriceProductTradePrice/ram:ChargeAmount', '40.01'],
    ['//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', '100.01'],
    ['//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', '10.00'],
    ['//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:BasisAmount', '100.01'],
    ['//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount', '20.01'],
    ['//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount', '30.01'],
    ['//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount', '230.01'],
    ['//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount', '230.01'],
    ['//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString', '20261212'],
    ['//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', 'Z'],
    ['//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID', 'FR00000000001'],
    ['//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount/@currencyID', 'USD'],
]);

it('rejects a duplicated or missing business CII line before validation', function (bool $duplicate) {
    $doc = new DOMDocument;
    $doc->loadXML($this->xml, LIBXML_NONET);
    $line = $doc->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'IncludedSupplyChainTradeLineItem')->item(0);
    $duplicate ? $line->parentNode->appendChild($line->cloneNode(true)) : $line->parentNode->removeChild($line);
    $result = CiiDiagnostic::simulate(new MockHandler([new Response(200, ['Content-Type' => 'application/xml'], $doc->saveXML())]), $this->storage, 'business');
    expect($result)->toMatchArray(['requests' => 1, 'local_cii_consistency' => 'failed', 'validation' => 'not_attempted']);
})->with([true, false]);

it('rejects an unknown fixture scenario rather than falling back to the technical example', function () {
    expect(fn () => CiiProbeInput::payload('unapproved'))->toThrow(\SimpleDevis\Tools\SuperPdp\CiiProbeFailure::class);
});
