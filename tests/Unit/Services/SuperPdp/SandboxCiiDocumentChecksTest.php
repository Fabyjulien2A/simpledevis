<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\BurgerQueenSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SandboxCiiChecks as Cii;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    // Previously loaded model represented in memory, no SQL or saving.
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $data = SuperPdpPayloadFixture::data();
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['seller'] = ['name' => 'Burger Queen', 'siret' => null, 'address' => 'Adresse vendeur de test',
        'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR'];
    $data['buyer'] = ['company_name' => 'Tricatel', 'client_type' => 'professional', 'siret' => null,
        'address' => 'Adresse acheteur de test', 'postal_code' => '69001', 'city' => 'Lyon', 'country_code' => 'FR'];
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $this->preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData($data, $this->context, $this->company, SuperPdpPayloadFixture::frenchContext());
    $this->xml = BurgerQueenSandboxCii::xml($this->preparation);
    $this->checks = new CiiDocumentChecks;
    $this->check = fn () => $this->checks->assertSandboxMatches($this->xml, $this->preparation, $this->context, $this->company);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('accepts the exact sandbox mapping without GlobalID and without promoting it to emission evidence', function () {
    $before = [$this->preparation, $this->context->toArray(), $this->company->getAttributes()];
    ($this->check)();
    expect([$this->preparation, $this->context->toArray(), $this->company->getAttributes()])->toBe($before)
        ->and($this->preparation['ready_for_emission'])->toBeFalse()
        ->and(config('superpdp_emission.sandbox_enabled'))->toBeFalse()
        ->and($this->context->toArray()['buyer']['routing_confirmation']['api_verified'])->toBeFalse()
        ->and(Cii::parse($this->xml)->query('//ram:GlobalID')->length)->toBe(0);
});

it('rejects divergent XML identities schemes addresses and tax identifiers', function (string $role, string $suffix, string $value) {
    $x = Cii::parse($this->xml);
    $path = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:'.$role.'TradeParty';
    PreparedCiiFixture::set($x, $path.$suffix, $value);
    $this->xml = $x->document->saveXML();
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with(['Seller', 'Buyer'])->with([
    ['/ram:Name', 'Another company'],
    ['/ram:SpecifiedLegalOrganization/ram:ID', '000000009'],
    ['/ram:SpecifiedLegalOrganization/ram:ID', '00000000200000'],
    ['/ram:SpecifiedLegalOrganization/ram:ID', ' 000000002 '],
    ['/ram:SpecifiedLegalOrganization/ram:ID/@schemeID', '0009'],
    ['/ram:SpecifiedLegalOrganization/ram:ID/@schemeID', 'sandbox'],
    ['/ram:URIUniversalCommunication/ram:URIID', '315143296_18171_replyto'],
    ['/ram:URIUniversalCommunication/ram:URIID', '315143296_18172_REPLYTO'],
    ['/ram:URIUniversalCommunication/ram:URIID', '000000002'],
    ['/ram:URIUniversalCommunication/ram:URIID/@schemeID', 'EM'],
    ['/ram:URIUniversalCommunication/ram:URIID/@schemeID', '225'],
    ['/ram:SpecifiedTaxRegistration/ram:ID', 'FR00000000000'],
    ['/ram:SpecifiedTaxRegistration/ram:ID/@schemeID', 'FC'],
]);

it('rejects unexpected general IDs and hidden or duplicated identifiers', function (string $role, string $case) {
    $x = Cii::parse($this->xml);
    $path = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:'.$role.'TradeParty';
    $party = $x->query($path)->item(0);
    if (in_array($case, ['global', 'siret', 'general', 'hidden'], true)) {
        $node = $x->document->createElementNS(Cii::NS['ram'], $case === 'general' ? 'ram:ID' : 'ram:GlobalID');
        $node->setAttribute('schemeID', $case === 'siret' ? '0009' : '0225');
        $node->appendChild($x->document->createTextNode($case === 'siret' ? '00000000100000' : '000000001'));
        ($case === 'hidden' ? $x->query($path.'/ram:SpecifiedLegalOrganization')->item(0) : $party)->appendChild($node);
    } else {
        $suffix = match ($case) {
            'legal' => '/ram:SpecifiedLegalOrganization/ram:ID',
            'electronic' => '/ram:URIUniversalCommunication/ram:URIID',
            'tax' => '/ram:SpecifiedTaxRegistration/ram:ID',
        };
        $node = $x->query($path.$suffix)->item(0);
        $node->parentNode->appendChild($node->cloneNode(true));
    }
    $this->xml = $x->document->saveXML();
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with(['Seller', 'Buyer'])->with(['global', 'siret', 'general', 'hidden', 'legal', 'electronic', 'tax']);

it('binds the document to the immutable profile even when XML and payload agree on a forged identity', function (string $field, mixed $value, string $exception) {
    data_set($this->preparation['payload']['seller'], $field, $value);
    $suffix = match ($field) {
        'name' => '/ram:Name',
        'legal_registration_identifier.value' => '/ram:SpecifiedLegalOrganization/ram:ID',
        'legal_registration_identifier.scheme' => '/ram:SpecifiedLegalOrganization/ram:ID/@schemeID',
        'electronic_address.value' => '/ram:URIUniversalCommunication/ram:URIID',
        'vat_identifier' => '/ram:SpecifiedTaxRegistration/ram:ID',
        default => null,
    };
    if ($suffix !== null) {
        $x = Cii::parse($this->xml);
        PreparedCiiFixture::set($x, Cii::SELLER.$suffix, $value);
        $this->xml = $x->document->saveXML();
    }
    expect(fn () => ($this->check)())->toThrow($exception);
})->with([
    ['name', 'Tricatel', RuntimeException::class], ['legal_registration_identifier.value', '000000001', RuntimeException::class],
    ['legal_registration_identifier.scheme', '0009', RuntimeException::class], ['electronic_address.value', '315143296_18171', RuntimeException::class],
    ['vat_identifier', 'FR15000000001', RuntimeException::class], ['identifiers', [], RuntimeException::class],
    ['siret', '00000000200000', ValidationException::class],
]);

it('refuses the inverse role assignment even if the payload and XML are swapped together', function () {
    [$this->preparation['payload']['seller'], $this->preparation['payload']['buyer']] =
        [$this->preparation['payload']['buyer'], $this->preparation['payload']['seller']];
    $this->xml = BurgerQueenSandboxCii::xml($this->preparation);
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
});

it('rejects missing duplicate and misplaced critical nodes', function (string $path, string $operation) {
    $x = Cii::parse($this->xml);
    $node = $x->query($path)->item(0);
    if ($operation === 'missing') {
        $node->parentNode->removeChild($node);
    } elseif ($operation === 'duplicate') {
        $node->parentNode->appendChild($node->cloneNode(true));
    } else {
        $x->document->documentElement->appendChild($node);
    }
    $this->xml = $x->document->saveXML();
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with([
    Cii::SELLER.'/ram:SpecifiedLegalOrganization', Cii::SELLER.'/ram:URIUniversalCommunication',
    Cii::SELLER.'/ram:SpecifiedTaxRegistration', Cii::LINE, Cii::TOTALS.'/ram:GrandTotalAmount',
    Cii::SELLER.'/ram:PostalTradeAddress/ram:LineOne',
])->with(['missing', 'duplicate', 'misplaced']);

it('refuses an unapproved envelope', function (string $field, mixed $value) {
    $this->preparation[$field] = $value;
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with([
    ['profile', 'fr_b2b_380_eur'], ['profile', null], ['ready_for_emission', true],
    ['ready_for_emission', 'false'], ['payload', null], ['extra', true],
]);

it('rechecks the authorized company owner environment and database', function (string $case) {
    match ($case) {
        'production' => $this->app->instance('env', 'production'),
        'config' => config(['app.env' => 'production']),
        'provider' => config(['services.superpdp.environment' => 'production']),
        'database' => config(['database.connections.superpdp_sandbox.database' => 'main']),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
        'company' => $this->company->id = 4,
        'owner' => $this->company->user_id = 4,
        'user' => $this->actingAs((new User)->forceFill(['id' => 4])),
        'guest' => auth()->logout(),
    };
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with(['production', 'config', 'provider', 'database', 'binding', 'company', 'owner', 'user', 'guest']);

it('retains the common date postal line VAT amount and payment checks', function (string $path, string $value) {
    $x = Cii::parse($this->xml);
    PreparedCiiFixture::set($x, $path, $value);
    $this->xml = $x->document->saveXML();
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with([
    [Cii::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString', '20260230'],
    [Cii::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString/@format', '101'],
    [Cii::SELLER.'/ram:PostalTradeAddress/ram:LineOne', ''],
    [Cii::SELLER.'/ram:PostalTradeAddress/ram:PostcodeCode', '99999'],
    [Cii::SELLER.'/ram:PostalTradeAddress/ram:CityName', 'Other city'],
    [Cii::SELLER.'/ram:PostalTradeAddress/ram:CountryID', 'BE'],
    [Cii::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', '2.00'],
    [Cii::LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', 'HUR'],
    [Cii::LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', 'E'],
    [Cii::TAX.'/ram:RateApplicablePercent', '5.5'],
    [Cii::TAX.'/ram:CalculatedAmount', '19.99'],
    [Cii::TOTALS.'/ram:GrandTotalAmount', '120.01'],
    [Cii::TOTALS.'/ram:DuePayableAmount', '0.00'],
    [Cii::TOTALS.'/ram:TaxTotalAmount/@currencyID', 'USD'],
    [Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString', '20260101'],
    [Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString/@format', '101'],
]);

it('retains payment descriptions and rejects their alteration', function () {
    $this->preparation['payload']['payment_terms'] = 'Conditions explicites de test';
    $x = Cii::parse($this->xml);
    $parent = $x->query(Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms')->item(0);
    $description = $x->document->createElementNS(Cii::NS['ram'], 'ram:Description');
    $description->appendChild($x->document->createTextNode('Conditions explicites de test'));
    $parent->appendChild($description);
    $this->xml = $x->document->saveXML();
    ($this->check)();
    $description->textContent = 'Other terms';
    $this->xml = $x->document->saveXML();
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
});

it('keeps malformed XML DTD and entity protections active', function (string $xml) {
    $this->xml = $xml;
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
})->with(['', '<broken>', '<x/>', '<!DOCTYPE x SYSTEM "https://example.invalid/no-network"><x/>',
    '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///not-read">]><x>&secret;</x>']);

it('retains production SIRET GlobalID requirements and the existing report contract', function () {
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $data['buyer'] += ['company_name' => 'Acheteur historique', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554'];
    $data['buyer']['client_type'] = 'professional';
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data,
        ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], SuperPdpPayloadFixture::frenchContext());
    $xml = PreparedCiiFixture::xml($payload);
    $this->checks->assertMatches($xml, $payload);
    $this->checks->assertReport(PreparedCiiFixture::report($xml), $xml);
    $x = Cii::parse($xml);
    $node = $x->query(Cii::SELLER.'/ram:GlobalID')->item(0);
    $node->parentNode->removeChild($node);
    expect(fn () => $this->checks->assertMatches($x->document->saveXML(), $payload))->toThrow(RuntimeException::class);
    $this->xml = $xml;
    expect(fn () => ($this->check)())->toThrow(RuntimeException::class);
});
