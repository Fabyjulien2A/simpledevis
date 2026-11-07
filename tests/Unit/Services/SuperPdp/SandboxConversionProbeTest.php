<?php

use App\Models\Invoice;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\Sandbox\SandboxConversionProbe;
use App\Services\SuperPdp\Sandbox\SandboxInvoiceFixture;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

uses(TestCase::class);

function sandboxCiiResponse(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"><rsm:ExchangedDocumentContext/></rsm:CrossIndustryInvoice>';
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config([
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.client_id' => 'unused-client-id',
        'services.superpdp.client_secret' => 'NEVER-PRINT-THIS-SECRET',
        'superpdp_sandbox.conversion_enabled' => true,
    ]);
    // No SQL, even SELECT, is needed for this isolated fictitious example.
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès base autorisé pour cet outil fictif.');
    });
    Http::preventStrayRequests();
    $oauth = $this->createMock(SuperPdpOAuthService::class);
    $oauth->expects($this->never())->method('ensureValidAccessToken');
    $oauth->expects($this->never())->method('refreshAccessToken');
    $this->app->instance(SuperPdpOAuthService::class, $oauth);
    $this->fixture = app(SandboxInvoiceFixture::class);
    $this->probe = new SandboxConversionProbe($this->fixture, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
});

it('simulates by default with the genuine builder fiscal checks and local schema', function () {
    Http::fake();
    $result = $this->probe->run();
    expect($result['mode'])->toBe('simulation')->and($result['status'])->toBeNull()
        ->and($this->fixture->invoice()->exists)->toBeFalse()->and($this->fixture->invoice()->getKey())->toBeNull();
    $payload = $result['payload'];
    expect($payload['number'])->toBe('SANDBOX-FICTIF-0001')->and($payload['type_code'])->toBe(380)
        ->and($payload['currency_code'])->toBe('EUR')
        ->and($payload['lines'][0]['invoiced_quantity'])->toBe('1.00')
        ->and($payload['lines'][0]['price_details']['item_net_price'])->toBe('100.00')
        ->and($payload['lines'][0]['net_amount'])->toBe('100.00')
        ->and($payload['vat_break_down'][0]['vat_category_tax_amount'])->toBe('20.00')
        ->and($payload['totals']['amount_due_for_payment'])->toBe('120.00')
        ->and($payload['seller']['electronic_address']['value'])->toEndWith('@example.invalid')
        ->and($payload['buyer']['electronic_address']['value'])->toEndWith('@example.invalid');
    array_walk_recursive($payload, fn ($value) => expect(is_float($value) || $value === null)->toBeFalse());
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
    Http::assertNothingSent();
});

it('declares S1 only for the fictitious service and preserves the three exact existing PDF formulations', function () {
    $result = $this->probe->run();
    $payload = $result['payload'];
    $notes = [
        ['subject_code' => 'PMT', 'note' => '40 € dus en cas de retard de paiement.'],
        ['subject_code' => 'PMD', 'note' => '10% du montant TTC.'],
        ['subject_code' => 'AAB', 'note' => 'Néant.'],
    ];
    expect($payload['notes'])->toBe($notes)
        ->and($payload['process_control'])->toBe(['specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => 'S1'])
        ->and($payload['totals'])->toBe([
            'sum_invoice_lines_amount' => '100.00', 'total_without_vat' => '100.00',
            'total_vat_amount' => ['value' => '20.00', 'currency_code' => 'EUR'],
            'total_with_vat' => '120.00', 'amount_due_for_payment' => '120.00',
        ])
        ->and($payload['seller']['legal_registration_identifier'])->toBe(['value' => '000000000', 'scheme' => '0002'])
        ->and($payload['seller']['identifiers'])->toBe([['value' => '00000000000000', 'scheme' => '0009']])
        ->and($payload['seller']['vat_identifier'])->toBe('FR00000000000')
        ->and($payload['buyer']['vat_identifier'])->toBe('FR00000000000');
    $template = file_get_contents(resource_path('views/invoices/pdf.blade.php'));
    foreach ($notes as $note) {
        expect($template)->toContain($note['note']);
    }
    Http::assertNothingSent();
});

it('never performs HTTP for the command without the execute option even if enabled', function (bool $enabled) {
    config(['superpdp_sandbox.conversion_enabled' => $enabled]);
    Http::fake();
    $this->artisan('superpdp:sandbox-convert')
        ->expectsOutputToContain('SIMULATION : validation locale réussie ; aucun appel réseau.')->assertExitCode(0);
    Http::assertNothingSent();
})->with([false, true]);

it('refuses explicit execution unless separately enabled in configuration', function () {
    config(['superpdp_sandbox.conversion_enabled' => false]);
    Http::fake();
    $this->artisan('superpdp:sandbox-convert --execute')
        ->expectsOutputToContain('SUPERPDP_SANDBOX_CONVERSION_ENABLED=true')->assertExitCode(1);
    Http::assertNothingSent();
});

it('rejects every environment outside the local and testing allowlist', function (string $environment, bool $execute) {
    $this->app->instance('env', $environment);
    Http::fake();
    expect(fn () => $this->probe->run($execute))->toThrow(RuntimeException::class, 'réservé aux environnements local et testing');
    Http::assertNothingSent();
})->with(['production', 'staging', 'sandbox'])->with([false, true]);

it('refuses alternate base URLs rather than exposing a configurable endpoint', function (string $url) {
    config(['services.superpdp.api_url' => $url]);
    Http::fake();
    expect(fn () => $this->probe->run(true))->toThrow(RuntimeException::class, 'URL de conversion officielle');
    Http::assertNothingSent();
})->with(['https://other.example/v1.beta', 'https://api.superpdp.tech/v1.beta/invoices', 'http://api.superpdp.tech/v1.beta']);

it('sends exactly one conversion POST with the direct JSON body and XML accept header without any authentication', function () {
    $optionsSeen = [];
    Http::fake(function (Request $request, array $options) use (&$optionsSeen) {
        expect($request->url())->toBe(SandboxConversionProbe::URL);
        $optionsSeen = $options;

        return Http::response(sandboxCiiResponse(), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    });
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('converted')->and($result['xml'])->toBe(sandboxCiiResponse())
        ->and($optionsSeen['allow_redirects'])->toBeFalse();
    Http::assertSent(function (Request $request) use ($result) {
        expect($request->method())->toBe('POST')->and($request->url())->toBe(SandboxConversionProbe::URL)
            ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
            ->and($request->hasHeader('Accept', 'application/xml'))->toBeTrue()
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and($request->hasHeader('X-Impersonate-Company'))->toBeFalse()
            ->and(json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR))->toBe($result['payload']);
        expect($request->data())->not->toHaveKey('en_invoice')->not->toHaveKey('client_secret');

        return true;
    });
    Http::assertSentCount(1);
});

it('does not bypass builder controls in order to build a fictitious payload', function (string $field, mixed $value, string $exception) {
    $this->fixture->invoice()->setAttribute($field, $value);
    Http::fake();
    expect(fn () => $this->probe->run(true))->toThrow($exception);
    Http::assertNothingSent();
})->with([
    ['document_status', 'draft', InvalidArgumentException::class],
    ['invoice_number', '', InvalidArgumentException::class],
    ['calculation_version', null, ValidationException::class],
    ['total_ttc', '119.99', ValidationException::class],
    ['amount_paid', '1.00', InvalidArgumentException::class],
    ['status', 'payee', InvalidArgumentException::class],
]);

it('still invokes the genuine local schema validation before any simulated transport', function () {
    $validator = $this->createMock(SuperPdpInvoicePayloadValidator::class);
    $validator->expects($this->once())->method('validate')->willThrowException(
        ValidationException::withMessages(['payload.number' => 'Refus du contrat local'])
    );
    $probe = new SandboxConversionProbe($this->fixture, app(InvoiceFiscalDataPreparer::class), $validator);
    Http::fake();
    expect(fn () => $probe->run(true))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('rejects any real or foreign invoice at the fictitious data source boundary', function () {
    expect(fn () => $this->fixture->buildElectronic(new Invoice))->toThrow(InvalidArgumentException::class);
    $this->fixture->invoice()->exists = true;
    expect(fn () => $this->probe->run())->toThrow(InvalidArgumentException::class, 'exemple fictif non enregistré');
});

it('reports HTTP errors and optional diagnostics without assuming a validation report contract', function (int $status) {
    Http::fake([SandboxConversionProbe::URL => Http::response([
        'http_status_code' => $status, 'message' => 'Erreur de conversion fictive', 'code' => 42,
        'diagnostic_unexpected_by_openapi' => ['checks' => [['location' => '/Invoice/Tax', 'message' => 'Détail de contrôle fictif']]],
        'access_token' => 'HIDDEN-ACCESS-TOKEN', 'nested' => ['refresh_token' => 'HIDDEN-REFRESH-TOKEN'],
    ], $status)]);
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('http_error')->and($result['status'])->toBe($status)
        ->and($result['diagnostic'])->toContain('Erreur de conversion fictive', '/Invoice/Tax', '[masqué]')
        ->not->toContain('HIDDEN-ACCESS-TOKEN', 'HIDDEN-REFRESH-TOKEN');
    Http::assertSentCount(1);
})->with([400, 500, 401, 403, 429]);

it('prints sanitized diagnostics for a failed command without logging raw HTTP exceptions', function () {
    Http::fake([SandboxConversionProbe::URL => Http::response([
        'http_status_code' => 400, 'message' => 'Diagnostic NEVER-PRINT-THIS-SECRET Bearer HIDDEN-BEARER',
        'access_token' => 'HIDDEN-ACCESS-TOKEN', 'refresh_token' => 'HIDDEN-REFRESH-TOKEN',
        'client_secret' => 'HIDDEN-OTHER-SECRET',
    ], 400)]);
    $output = new BufferedOutput;
    $status = $this->app->make(Kernel::class)->call('superpdp:sandbox-convert', ['--execute' => true], $output);
    $text = $output->fetch();
    expect($status)->toBe(1)->and($text)->toContain('HTTP 400', '[masqué]')
        ->not->toContain('NEVER-PRINT-THIS-SECRET', 'HIDDEN-BEARER', 'HIDDEN-ACCESS-TOKEN', 'HIDDEN-REFRESH-TOKEN', 'HIDDEN-OTHER-SECRET');
    Http::assertSentCount(1);
});

it('rejects redirects without following them to any endpoint', function () {
    Http::fake([SandboxConversionProbe::URL => Http::response('', 302, ['Location' => 'https://api.superpdp.tech/v1.beta/invoices'])]);
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('http_error')->and($result['status'])->toBe(302);
    Http::assertSentCount(1);
});

it('handles transport failures without retry or printing exception contents', function () {
    Http::fake([SandboxConversionProbe::URL => Http::failedConnection('Bearer SECRET-IN-EXCEPTION')]);
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('transport_error')->and($result['status'])->toBeNull()
        ->and($result['diagnostic'])->not->toContain('SECRET-IN-EXCEPTION');
    Http::assertSentCount(1);
});

it('rejects unexpected XML or JSON success responses without treating them as converted invoices', function (string $body, string $contentType) {
    Http::fake([SandboxConversionProbe::URL => Http::response($body, 200, ['Content-Type' => $contentType])]);
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('invalid_response')->and($result['xml'])->toBeNull();
    Http::assertSentCount(1);
})->with([
    ['{"message":"Unexpected JSON report"}', 'application/json'], ['', 'application/xml'],
    ['<CrossIndustryInvoice>', 'application/xml'], ['<validationReport/>', 'application/xml'],
    [sandboxCiiResponse(), 'text/html'],
    ['<!DOCTYPE CrossIndustryInvoice [<!ENTITY remote SYSTEM "https://invalid.example/entity">]><CrossIndustryInvoice>&remote;</CrossIndustryInvoice>', 'application/xml'],
]);

it('successfully runs the opt-in command with only a fake conversion response', function () {
    Http::fake([SandboxConversionProbe::URL => Http::response(sandboxCiiResponse(), 200, ['Content-Type' => 'application/xml'])]);
    $this->artisan('superpdp:sandbox-convert --execute')->expectsOutputToContain('CII reçu')
        ->expectsOutputToContain('HTTP 200')->assertExitCode(0);
    Http::assertSentCount(1);
});

it('shows local validation errors without attempting the configured conversion', function () {
    $this->fixture->invoice()->total_ttc = '119.00';
    $this->app->instance(SandboxInvoiceFixture::class, $this->fixture);
    Http::fake();
    $this->artisan('superpdp:sandbox-convert --execute')->expectsOutputToContain('Exemple refusé par les contrôles locaux.')
        ->expectsOutputToContain('total_ttc:')->assertExitCode(1);
    Http::assertNothingSent();
});

it('masks secrets in plain text or malformed JSON diagnostics', function (string $body, string $hidden) {
    Http::fake([SandboxConversionProbe::URL => Http::response($body, 500, ['Content-Type' => 'text/plain'])]);
    $result = $this->probe->run(true);
    expect($result['mode'])->toBe('http_error')->and($result['diagnostic'])->toContain('[masqué]')->not->toContain($hidden);
    Http::assertSentCount(1);
})->with([
    ['Erreur Bearer RAW-BEARER-TOKEN', 'RAW-BEARER-TOKEN'],
    ['Erreur access_token=RAW-ACCESS-TOKEN', 'RAW-ACCESS-TOKEN'],
    ['{"access_token":"BROKEN-JSON-TOKEN"', 'BROKEN-JSON-TOKEN'],
    ['Erreur NEVER-PRINT-THIS-SECRET', 'NEVER-PRINT-THIS-SECRET'],
]);

it('bounds unexpected server diagnostics', function () {
    Http::fake([SandboxConversionProbe::URL => Http::response(str_repeat('A', 100000), 500)]);
    $result = $this->probe->run(true);
    expect(mb_strlen($result['diagnostic']))->toBeLessThanOrEqual(8000);
    Http::assertSentCount(1);
});

it('keeps the isolated request aligned with the pinned official conversion operation', function () {
    $spec = json_decode(file_get_contents(resource_path('schemas/superpdp/1.39.0.beta/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/v1.beta/invoices/convert']['post'];
    expect($operation['security'])->toBe([])
        ->and($operation['requestBody']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/en_invoice')
        ->and($operation['responses']['200']['content'])->toHaveKey('application/xml')
        ->and(array_keys($operation['responses']))->toBe([200, 400, 500])
        ->and(array_column($operation['parameters'], 'name'))->toBe(['from', 'to']);
});
