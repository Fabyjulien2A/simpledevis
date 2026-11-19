<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;
use Tests\Support\BurgerQueenSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(static fn () => throw new RuntimeException('No network in tests.'));
        }
    });
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00Z'));
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta', 'services.superpdp.client_id' => 'unit-client',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test', 'username' => 'fabyjulien_simpledevis_test']],
    ]);
    // In-memory representations only, no database queries or saved models.
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->connection = (new SuperPdpConnection)->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'status' => 'connected',
        'environment' => 'sandbox', 'oauth_client_id' => 'unit-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'directory_identifier' => '000000002', 'access_token_expires_at' => now()->addHour(),
        'access_token' => 'unit-access-only', 'refresh_token' => 'unit-refresh-only',
    ]);
    $this->connection->exists = true;
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $this->scenario = SandboxInvoiceScenario::forContext($this->context, $this->company, '2026-11-13', '2026-12-13');
    $preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData(
        $this->scenario->data(), $this->context, $this->company, $this->scenario->frenchContext());
    $this->xml = BurgerQueenSandboxCii::xml($preparation);
    $this->responses = [
        ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
    ];
    $this->gateway = fn () => SandboxCiiPreparationGateway::forScenario($this->context, $this->company, $this->connection,
        $this->scenario, responses: $this->responses);
    $this->requests = [];
    $requests = &$this->requests;
    Config::globalMiddleware()->onRequest(static function (PendingRequest $request) use (&$requests) {
        $requests[] = ['class' => $request->getRequest()::class, 'url' => $request->getUrl(),
            'method' => $request->getMethod()->value, 'authorization' => $request->headers()->get('Authorization'),
            'query' => $request->query()->all(), 'body' => $request->body()?->all()];
    });
});

afterEach(function () {
    Carbon::setTestNow();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Config::preventStrayRequests();
    Http::assertNothingSent();
});

it('runs only documentary conversion and validation with existing locally decrypted OAuth credentials', function () {
    $before = [$this->connection->getAttributes(), $this->company->getAttributes(), $this->scenario->data(), config('superpdp_emission')];
    $gateway = ($this->gateway)();
    $result = app(InvoiceCiiPreparation::class)->convertSandboxScenario($this->context, $this->company, $this->scenario, $gateway);
    expect($result['status'])->toBe('documentary_test_completed')
        ->and($result['http_statuses'])->toBe(['conversion' => 200, 'validation' => 200])
        ->and($result['document_validation'])->toBe('passed_simulation')
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['deposit_performed'])->toBeFalse()
        ->and($result['official_archive'])->toBeFalse()->and($result['routing_api_verified'])->toBeFalse()
        ->and($result['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and(array_column($this->requests, 'class'))->toBe([ConvertInvoiceRequest::class, CreateValidationReportRequest::class])
        ->and(array_column($this->requests, 'url'))->toBe(['https://api.superpdp.tech/v1.beta/invoices/convert', 'https://api.superpdp.tech/v1.beta/validation_reports'])
        ->and(array_column($this->requests, 'method'))->toBe(['POST', 'POST'])
        ->and(array_column($this->requests, 'authorization'))->toBe([null, null])
        ->and([$this->connection->getAttributes(), $this->company->getAttributes(), $this->scenario->data(), config('superpdp_emission')])->toBe($before);
    expect(json_encode($result))->not->toContain('unit-access-only', 'unit-refresh-only');
    expect(print_r($gateway, true))->not->toContain('unit-access-only', 'unit-refresh-only');
});

it('never defaults to a real call and rejects mixed real and mocked authorization', function () {
    expect(fn () => SandboxCiiPreparationGateway::forScenario($this->context, $this->company, $this->connection, $this->scenario))
        ->toThrow(RuntimeException::class, 'Explicit local CLI');
    expect(fn () => SandboxCiiPreparationGateway::forScenario($this->context, $this->company, $this->connection,
        $this->scenario, authorizeNetwork: true, responses: $this->responses))->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
});

it('rejects foreign or expired OAuth company and environment contexts without refreshing', function (string $field, mixed $value) {
    $this->connection->setAttribute($field, $value);
    expect(fn () => ($this->gateway)())->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
})->with([
    ['id', 2], ['company_id', 4], ['superpdp_company_id', 18171], ['environment', 'production'],
    ['oauth_client_id', 'wrong-client'], ['api_url', 'https://example.invalid'], ['status', 'disconnected'],
    ['directory_identifier', '000000001'], ['access_token', null], ['refresh_token', null],
    ['access_token_expires_at', '2026-11-13T17:04:00Z'],
]);

it('refuses undecryptable tokens without propagating their content or exception chain', function () {
    $attributes = $this->connection->getAttributes();
    $attributes['access_token'] = 'unusable-private-ciphertext';
    $this->connection->setRawAttributes($attributes);
    try {
        ($this->gateway)();
        $this->fail('An undecryptable token must be refused.');
    } catch (RuntimeException $exception) {
        expect($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('unusable-private-ciphertext');
    }
});

it('rejects activation in production or by another local owner', function (string $case) {
    match ($case) {
        'production' => $this->app->instance('env', 'production'),
        'config' => config(['app.env' => 'production']),
        'owner' => $this->company->user_id = 4,
        'user' => $this->actingAs((new User)->forceFill(['id' => 4])),
        'database' => config(['database.connections.superpdp_sandbox.database' => 'main']),
    };
    expect(fn () => ($this->gateway)())->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
})->with(['production', 'config', 'owner', 'user', 'database']);

it('binds validation to the exact converted bytes and prevents replays', function () {
    $gateway = ($this->gateway)();
    expect(fn () => $gateway->validate($this->xml))->toThrow(RuntimeException::class);
    $conversion = $gateway->convert();
    expect(fn () => $gateway->convert())->toThrow(RuntimeException::class);
    expect(fn () => $gateway->validate($conversion->data['xml']."\n"))->toThrow(RuntimeException::class);
    $gateway->validate($conversion->data['xml']);
    expect(fn () => $gateway->validate($conversion->data['xml']))->toThrow(RuntimeException::class);
    expect($this->requests)->toHaveCount(2);
});

it('rechecks the five-minute authorization scope and credential binding before every operation', function (string $case) {
    $gateway = ($this->gateway)();
    match ($case) {
        'expiry' => Carbon::setTestNow(now()->addMinutes(5)),
        'credentials' => $this->connection->access_token = 'changed-unit-only',
        'client' => config(['services.superpdp.client_id' => 'changed-client']),
    };
    expect(fn () => $gateway->convert())->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
})->with(['expiry', 'credentials', 'client']);

it('refuses a different scenario despite matching company ownership', function () {
    $gateway = ($this->gateway)();
    $other = SandboxInvoiceScenario::forContext($this->context, $this->company, '2026-11-14', '2026-12-14');
    expect(fn () => app(InvoiceCiiPreparation::class)->convertSandboxScenario($this->context, $this->company, $other, $gateway))
        ->toThrow(RuntimeException::class);
    expect($this->requests)->toBe([]);
});

it('refuses deposits and callbacks in the mock response allowlist', function () {
    $this->responses['deposit'] = MockResponse::make([]);
    expect(fn () => ($this->gateway)())->toThrow(RuntimeException::class, 'Only in-memory');
    $this->responses = [ConvertInvoiceRequest::class => fn () => MockResponse::make('')];
    expect(fn () => ($this->gateway)())->toThrow(RuntimeException::class);
});

it('blocks an unexpected SDK operation before it can leave the allowlisted connector', function () {
    $gateway = ($this->gateway)();
    $connector = (new ReflectionProperty($gateway, 'connector'))->getValue($gateway);
    // Ensure rejection comes from the request guard, not a missing mock match.
    $connector->withMockClient(new MockClient(['*' => MockResponse::make([])]));
    $request = new class('irrelevant', 'en16931', 'cii') extends ConvertInvoiceRequest
    {
        public function resolveEndpoint(): string
        {
            return '/v1.beta/invoices';
        }
    };
    expect(fn () => $connector->send($request))->toThrow(RuntimeException::class, 'outside the documentary allowlist');
});

it('stops on invalid CII failed validation or HTTP failure without retrying', function (string $case, string $reason, int $count) {
    $badReport = PreparedCiiFixture::report($this->xml);
    $badReport['data'][0]['is_valid'] = false;
    match ($case) {
        'http' => $this->responses[ConvertInvoiceRequest::class] = MockResponse::make('private response not printed', 403),
        'xml' => $this->responses[ConvertInvoiceRequest::class] = MockResponse::make('<broken>', 200, ['Content-Type' => 'application/xml']),
        'report' => $this->responses[CreateValidationReportRequest::class] = MockResponse::make($badReport),
    };
    $result = app(InvoiceCiiPreparation::class)->convertSandboxScenario($this->context, $this->company, $this->scenario, ($this->gateway)());
    expect($result['status'])->toBe('blocked')->and($result['reason'])->toBe($reason)
        ->and($result['ready_for_emission'])->toBeFalse()->and($this->requests)->toHaveCount($count);
    expect(json_encode($result))->not->toContain('private response not printed');
})->with([['http', 'conversion_failed', 1], ['xml', 'conversion_failed', 1], ['report', 'validation_failed', 2]]);

it('offers a CLI default that performs neither SQL nor SDK requests', function () {
    DB::shouldReceive('connection')->never();
    $this->artisan('superpdp:sandbox-documentary-test')->assertExitCode(0);
    expect($this->requests)->toBe([]);
});

it('stops if the short-lived OAuth scope expires during either documentary response', function (string $requestClass, int $count) {
    Config::globalMiddleware()->onResponse(static function (Response $response) use ($requestClass) {
        if ($response->getPendingRequest()->getRequest()::class === $requestClass) {
            Carbon::setTestNow(now()->addMinutes(5));
        }
    });
    $gateway = ($this->gateway)();
    if ($requestClass === ConvertInvoiceRequest::class) {
        expect(fn () => $gateway->convert())->toThrow(RuntimeException::class, 'Sandbox OAuth scope');
    } else {
        $conversion = $gateway->convert();
        expect(fn () => $gateway->validate($conversion->data['xml']))->toThrow(RuntimeException::class, 'Sandbox OAuth scope');
    }
    expect($this->requests)->toHaveCount($count);
})->with([[ConvertInvoiceRequest::class, 1], [CreateValidationReportRequest::class, 2]]);

it('rejects missing or invalid CLI dates and non-sandbox environments before SQL', function (string $case) {
    DB::shouldReceive('connection')->never();
    if ($case === 'production') {
        $this->app->instance('env', 'production');
    }
    $this->artisan('superpdp:sandbox-documentary-test', [
        '--execute' => true, '--date' => $case === 'date' ? '2026-02-30' : '2026-11-13',
        '--due-date' => $case === 'missing' ? null : '2026-12-13',
    ])->assertExitCode(1);
    expect($this->requests)->toBe([]);
})->with(['production', 'date', 'missing']);

it('loads only the three existing records in a rolled-back read-only transaction before the network gate', function () {
    $rows = ['users' => ['id' => 3], 'companies' => $this->company->getAttributes(),
        'superpdp_connections' => $this->connection->getAttributes()];
    $fake = new class($rows) extends Connection
    {
        public array $operations = [];

        public function __construct(private array $rows)
        {
            parent::__construct(fn () => throw new RuntimeException('PDO forbidden in this test.'), 'fabyjulien_simpledevis_test');
        }

        public function statement($query, $bindings = [])
        {
            if ($query !== 'SET TRANSACTION READ ONLY') {
                throw new RuntimeException('Only the read-only transaction directive is permitted.');
            }
            $this->operations[] = $query;

            return true;
        }

        public function beginTransaction()
        {
            $this->operations[] = 'begin';
        }

        public function rollBack($toLevel = null)
        {
            $this->operations[] = 'rollback';
        }

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            $this->operations[] = $query;
            if ($query === 'SELECT DATABASE() AS selected_database') {
                return [(object) ['selected_database' => 'fabyjulien_simpledevis_test']];
            }
            foreach ($this->rows as $table => $row) {
                if (preg_match('/\bfrom ["`]?'.preg_quote($table, '/').'["`]?\b/i', $query)) {
                    return [(object) $row];
                }
            }
            throw new RuntimeException('Unexpected query: '.$query);
        }
    };
    $resolver = Model::getConnectionResolver();
    Model::setConnectionResolver(new ConnectionResolver(['superpdp_sandbox' => $fake]));
    DB::shouldReceive('connection')->once()->with('superpdp_sandbox')->andReturn($fake);
    try {
        // The real-call flag is parsed, but Saloon's test network gate refuses
        // every unmocked request. No external operation or PDO can occur.
        $this->artisan('superpdp:sandbox-documentary-test', [
            '--execute' => true, '--date' => '2026-11-13', '--due-date' => '2026-12-13',
        ])->assertExitCode(1);
        expect($fake->operations[0])->toBe('SET TRANSACTION READ ONLY')
            ->and($fake->operations[1])->toBe('begin')
            ->and(end($fake->operations))->toBe('rollback')
            ->and($fake->operations)->toHaveCount(7);
        expect(implode(' ', $fake->operations))->not->toContain('insert ', 'update ', 'delete ', 'invoices');
    } finally {
        Model::setConnectionResolver($resolver);
    }
});
