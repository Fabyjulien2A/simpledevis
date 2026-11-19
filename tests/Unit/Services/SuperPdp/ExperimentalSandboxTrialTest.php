<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\Experimental\TrialArtifactInspection;
use App\Services\SuperPdp\Experimental\TrialDiagnostic;
use App\Services\SuperPdp\Experimental\TrialEvents;
use App\Services\SuperPdp\Experimental\TrialFailure;
use App\Services\SuperPdp\Experimental\TrialGate;
use App\Services\SuperPdp\Experimental\TrialLedger;
use App\Services\SuperPdp\Experimental\TrialOutcome;
use App\Services\SuperPdp\Experimental\TrialPackage;
use App\Services\SuperPdp\Experimental\TrialRuntime;
use App\Services\SuperPdp\Experimental\TrialSellerEvidence;
use App\Services\SuperPdp\Experimental\TrialStorage;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationObservation;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Saloon\Config;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;
use Symfony\Component\Process\Process;
use Tests\Support\BurgerQueenSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->previousResolver = Model::getConnectionResolver();
    // Fail BEFORE any connection could be made, including SQLite :memory:.
    foreach (['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv'] as $driver) {
        DB::extend($driver, static fn () => throw new RuntimeException('SQL forbidden.'));
    }
    Model::setConnectionResolver(new class implements ConnectionResolverInterface
    {
        public function connection($name = null)
        {
            throw new RuntimeException('SQL forbidden.');
        }

        public function getDefaultConnection()
        {
            return 'forbidden';
        }

        public function setDefaultConnection($name)
        {
            throw new RuntimeException('SQL forbidden.');
        }
    });
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-13T17:00:00Z');
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->connection = (new SuperPdpConnection)->setDateFormat('Y-m-d H:i:s')->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'status' => 'connected',
        'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'directory_identifier' => '000000002', 'access_token_expires_at' => now()->addHour(),
        'access_token' => 'offline-access', 'refresh_token' => 'offline-refresh',
    ]);
    $this->connection->exists = true;
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $this->scenario = SandboxInvoiceScenario::forContext($this->context, $this->company, '2026-11-13', '2026-12-13');
    $this->preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData(
        $this->scenario->data(), $this->context, $this->company, $this->scenario->frenchContext());
    $this->xml = BurgerQueenSandboxCii::xml($this->preparation);
    // Synthetic SDK response objects ONLY. The 'live' branch tests provenance semantics;
    // these objects are never actual live evidence and never leave the temp fixture directory.
    $this->response = function (string $path, string $body, string $source = 'live', string $token = 'offline-access') {
        $response = new Response(new PsrResponse(200, [], $body), $this->createMock(PendingRequest::class),
            new Request('GET', 'https://api.superpdp.tech/v1.beta/'.$path, ['Authorization' => 'Bearer '.$token]));
        if ($source === 'simulated') {
            $response->setMocked(true);
        }
        if ($source === 'cached') {
            $response->setCached(true);
        }

        return $response;
    };
    $this->package = function (string $source = 'live', ?string $xml = null, ?string $validationInput = null) {
        $xml ??= $this->xml;
        $report = PreparedCiiFixture::report($xml);
        $conversion = VerificationObservation::fromResponse(['operation' => 'conversion', 'xml' => $xml,
            'input_sha256' => CiiPreparationEvidence::hash($this->preparation['payload'])], ($this->response)('invoices/convert', $xml, $source));
        $validation = VerificationObservation::fromResponse(['operation' => 'validation', 'report' => $report,
            'input_sha256' => $validationInput ?? hash('sha256', $xml)], ($this->response)('validation_reports', json_encode($report), $source));

        return TrialPackage::freeze($this->context, $this->company, $this->scenario, $conversion, $validation, 'Offline fixture operator', now()->toIso8601String());
    };
    $remote = ['id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002', 'env' => 'sandbox', 'country' => 'FR'];
    $this->sellerData = ['companies/me' => $remote,
        'oauth2_sessions/me' => ['client_id' => 'offline-client', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'],
        'directory_entries' => ['data' => [['company' => $remote, 'identifier' => '0225:315143296_18172', 'directory' => 'peppol', 'status' => 'created']]]];
    $this->seller = fn (string $source = 'live', string $token = 'offline-access') => array_map(
        fn ($data, $path) => TrialSellerEvidence::fromResponse(($this->response)($path, json_encode($data), $source, $token)),
        array_values($this->sellerData), array_keys($this->sellerData));
    $this->inspect = fn (TrialPackage $package, ?array $seller = null) => (new TrialGate)->inspect(
        $package, $this->context, $this->company, $this->connection, ...($seller ?? ($this->seller)()));
    $this->approval = fn (TrialPackage $p, string $reference) => [
        'profile' => TrialPackage::PROFILE, 'environment' => 'sandbox', 'owner_user_id' => 3,
        'purpose' => 'single_deposit',
        'local_company_id' => 3, 'provider_company_id' => 18172,
        'buyer_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
        'recipient' => '0225:315143296_18171', 'routing_evidence' => 'operator_confirmed_without_api_proof',
        'disable_pre_check' => false, 'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'package_sha256' => $p->sha256(), 'cii_sha256' => $p->manifest['cii_sha256'], 'attempt_reference' => $reference,
        'approved_by' => 'Offline fixture operator', 'approved_at' => now()->timestamp, 'expires_at' => now()->timestamp + 300,
    ];
    $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'simpledevis-offline-trial-'.bin2hex(random_bytes(8));
    mkdir($this->temp, 0700);
    $this->ledger = new TrialLedger($this->temp);
    $this->claim = function (TrialPackage $p, array $approval, ?TrialLedger $ledger = null) {
        $seller = ($this->seller)();

        return (new TrialGate)->prepareClaim($p, $this->context, $this->company, $this->connection,
            $seller[0], $seller[1], $seller[2], $p->archive($this->temp), $ledger ?? $this->ledger, $approval);
    };
});

afterEach(function () {
    Config::setSenderResolver(static fn () => throw new RuntimeException('No HTTP sender outside synthetic runtime tests.'));
    Config::preventStrayRequests();
    foreach (glob($this->temp.DIRECTORY_SEPARATOR.'*') as $path) {
        unlink($path);
    }
    rmdir($this->temp);
    Carbon::setTestNow();
    Http::assertNothingSent();
    expect(DB::getConnections())->toBe([]);
    Model::setConnectionResolver($this->previousResolver);
});

// Real SDK request construction with a mandatory in-memory Guzzle handler. No
// sockets, MockClient fallback, PDO, or real live evidence is involved.
function offlineTrialRuntime($test): array
{
    $root = $test->temp;
    $storage = new class($root) extends TrialStorage
    {
        public function __construct(private string $temporaryRoot) {}

        public function root(): string
        {
            return $this->temporaryRoot;
        }
    };
    $runtime = new class($storage, [$test->company, $test->connection, $test->context]) extends TrialRuntime
    {
        public function __construct(TrialStorage $storage, private array $loadedIdentity)
        {
            parent::__construct($storage);
        }

        protected function identity(): array
        {
            auth()->setUser((new User)->forceFill(['id' => 3]));
            $this->assertOAuth($this->loadedIdentity[2], $this->loadedIdentity[0], $this->loadedIdentity[1]);

            return $this->loadedIdentity;
        }
    };
    $state = (object) ['requests' => [], 'timeout' => false, 'foreign' => false, 'hasAfter' => true, 'invalidReport' => false];
    $handler = function (RequestInterface $request, array $options) use ($test, $state, $root) {
        $path = $request->getUri()->getPath();
        parse_str($request->getUri()->getQuery(), $query);
        $state->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'query' => $query,
            'body' => (string) $request->getBody(), 'options' => $options];
        expect(is_file($root.'/requests.jsonl'))->toBeTrue();
        $status = 200;
        $headers = ['Content-Type' => 'application/json'];
        if ($path === '/v1.beta/invoices/convert') {
            $body = $test->xml;
            $headers['Content-Type'] = 'application/xml';
        } elseif ($path === '/v1.beta/validation_reports') {
            $report = PreparedCiiFixture::report($test->xml);
            if ($state->invalidReport) {
                $report['data'][0]['is_valid'] = false;
            }
            $body = json_encode($report, JSON_PRETTY_PRINT);
        } elseif (isset($test->sellerData[ltrim(substr($path, strlen('/v1.beta/')), '/')])) {
            $body = json_encode($test->sellerData[ltrim(substr($path, strlen('/v1.beta/')), '/')]);
        } elseif ($path === '/v1.beta/invoices' && $request->getMethod() === 'POST') {
            $history = (new TrialLedger($root))->history();
            expect(end($history)['phase'])->toBe('claimed');
            expect((string) $request->getBody())->toBe($test->xml)->and($query)->not->toHaveKey('disable_pre_check');
            expect($options['allow_redirects'])->toBeFalse()->and($options['verify'])->toBeTrue();
            if ($state->timeout) {
                throw new RuntimeException('Synthetic connection timeout');
            }
            $body = json_encode(['id' => 42, 'company_id' => 18172, 'direction' => 'out',
                'external_id' => $query['external_id'], 'created_at' => now()->toIso8601String()]);
        } elseif ($path === '/v1.beta/invoices/42') {
            $index = json_decode(file_get_contents($root.'/prepared.json'), true);
            $body = isset($query['format']) ? $test->xml : json_encode(['id' => 42,
                'company_id' => $state->foreign ? 18171 : 18172, 'direction' => 'out',
                'external_id' => $index['attempt_reference'], 'created_at' => now()->toIso8601String()]);
        } elseif ($path === '/v1.beta/invoice_events') {
            expect((int) $query['invoice_id'])->toBe(42)->and((int) $query['limit'])->toBe(100);
            $cursor = (int) ($query['starting_after_id'] ?? 0);
            $body = json_encode(['data' => [['id' => $cursor + 1, 'invoice_id' => 42,
                'status_code' => $cursor === 0 ? 'api:sent' : 'api:acknowledged', 'created_at' => now()->toIso8601String()]],
                'has_after' => $state->hasAfter]);
        } else {
            throw new RuntimeException('Unexpected synthetic SDK request.');
        }

        return Create::promiseFor(new PsrResponse($status, $headers, $body));
    };
    Config::allowStrayRequests(); // The handler below has no network fallback.
    Config::setSenderResolver(fn () => new class($handler) extends GuzzleSender
    {
        public function __construct(private Closure $offlineHandler)
        {
            parent::__construct();
        }

        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create($this->offlineHandler);
        }
    });

    return [$runtime, $state];
}

it('prepares durable raw artifacts through the SDK, then sends once and follows event pages', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $prepared = $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator');
    expect($prepared['status'])->toBe('reserved')->and($prepared['routing_api_verified'])->toBeFalse();
    expect(hash_file('sha256', $prepared['cii']))->toBe($prepared['cii_sha256'])
        ->and(hash_file('sha256', $prepared['validation_report']))->toBe($prepared['validation_report_sha256']);
    $count = count($state->requests);
    $again = $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator');
    expect($again['attempt_reference'])->toBe($prepared['attempt_reference'])->and(count($state->requests))->toBe($count);
    $expected = array_intersect_key($prepared, array_flip(['package_sha256', 'cii_sha256', 'attempt_reference']));
    $result = $runtime->deposit($expected, 'Fixture operator');
    expect($result['status'])->toBe('accepted')->and($result['provider_invoice_id'])->toBe(42);
    $count = count($state->requests);
    expect(fn () => $runtime->deposit($expected, 'Fixture operator'))->toThrow(RuntimeException::class);
    expect(count($state->requests))->toBe($count);
    $result = $runtime->consult($expected, 'Fixture reader');
    expect($result['events']['cursor'])->toBe(1)->and($result['events']['has_after'])->toBeTrue();
    $state->hasAfter = false;
    $result = $runtime->consult($expected, 'Fixture reader');
    expect($result['events']['cursor'])->toBe(2)->and($result['events']['remote_acknowledgment'])->toBeTrue();
});

it('never retries a timed out SDK deposit and reconciles only a positive original', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $prepared = $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator');
    $expected = array_intersect_key($prepared, array_flip(['package_sha256', 'cii_sha256', 'attempt_reference']));
    $state->timeout = true;
    expect($runtime->deposit($expected, 'Fixture operator')['status'])->toBe('unknown');
    $posts = fn () => count(array_filter($state->requests, fn ($r) => $r['path'] === '/v1.beta/invoices' && $r['method'] === 'POST'));
    expect($posts())->toBe(1);
    expect(fn () => $runtime->deposit($expected, 'Fixture operator'))->toThrow(RuntimeException::class);
    $state->foreign = true;
    expect(fn () => $runtime->consult($expected, 'Fixture reader', 42))->toThrow(RuntimeException::class);
    $state->foreign = false;
    expect($runtime->consult($expected, 'Fixture reader', 42)['status'])->toBe('accepted')->and($posts())->toBe(1);
});

it('refuses mismatched approval digests before any SDK read or write', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $prepared = $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator');
    $expected = array_intersect_key($prepared, array_flip(['package_sha256', 'cii_sha256', 'attempt_reference']));
    $expected['cii_sha256'] = str_repeat('0', 64);
    $count = count($state->requests);
    expect(fn () => $runtime->deposit($expected, 'Fixture operator'))->toThrow(RuntimeException::class);
    expect(count($state->requests))->toBe($count);
});

it('keeps Artisan operations closed and rejects incomplete deposit authorization without SQL', function () {
    $this->artisan('superpdp:sandbox-trial')->assertExitCode(0);
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'prepare'])->assertExitCode(0);
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'deposit', '--execute' => true, '--operator' => 'Fixture operator'])->assertExitCode(1);
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'prepare', '--execute' => true, '--operator' => 'Fixture operator',
        '--confirm-tricatel-address' => true, '--date' => '2026-02-30', '--due-date' => '2026-12-13'])->assertExitCode(1);
});

it('reports safe stage and type without raw exceptions, credentials, queries or traces', function () {
    try {
        TrialDiagnostic::step('identity.oauth_record', fn () => throw new TypeError('Bearer SECRET refresh_token=SECRET password=SECRET SELECT private FROM secret C:/private/file'));
        test()->fail('Expected a safe failure.');
    } catch (TrialFailure $failure) {
        $report = TrialDiagnostic::describe($failure);
        expect($report['stage'])->toBe('identity.oauth_record')->and($report['error_type'])->toBe('TypeError')
            ->and($failure->getPrevious())->toBeNull()
            ->and(json_encode($report))->not->toContain('SECRET', 'SELECT private', 'C:/private');
        expect($report['corrective_action'])->not->toBe('');
    }
});

it('diagnoses local artifacts without changing them or calling prepare identity SQL or HTTP', function (bool $incomplete) {
    if ($incomplete) {
        file_put_contents($this->temp.'/preparation.started', 'fixture');
    }
    $storage = new class($this->temp) extends TrialStorage
    {
        public bool $readOnly = false;

        public function __construct(private string $fixtureRoot) {}

        protected function verify(bool $readOnly): array
        {
            $this->readOnly = $readOnly;

            return ['root' => $this->fixtureRoot, 'exists' => true];
        }

        public function root(): string
        {
            throw new RuntimeException('Mutating storage must never run.');
        }
    };
    $runtime = new class($storage) extends TrialRuntime
    {
        protected function identity(): array
        {
            throw new RuntimeException('Identity must never load.');
        }

        public function prepare(string $date, string $dueDate, string $operator): array
        {
            throw new RuntimeException('Preparation must never run.');
        }
    };
    app()->instance(TrialRuntime::class, $runtime);
    $before = glob($this->temp.'/*');
    $exit = Artisan::call('superpdp:sandbox-trial', [
        'operation' => 'prepare', '--diagnostic' => true, '--operator' => 'Fixture operator',
        '--confirm-tricatel-address' => true, '--date' => '2026-11-13', '--due-date' => '2026-12-13',
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($storage->readOnly)->toBeTrue()->and($report['mode'])->toBe('read_only_diagnostic')
        ->and($report['sql'])->toBe('not_checked_no_connection')->and($report['deposit'])->toBe('not_executed')
        ->and(glob($this->temp.'/*'))->toBe($before)->and($exit)->toBe($incomplete ? 1 : 0);
    if ($incomplete) {
        expect($report['stage'])->toBe('preparation.marker')->and($report['reason'])->toBe('preparation_incomplete');
    }
})->with([false, true]);

it('rejects diagnostic combined with execute without resolving the runtime', function () {
    app()->bind(TrialRuntime::class, fn () => throw new RuntimeException('Runtime must not resolve.'));
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'prepare', '--diagnostic' => true, '--execute' => true])
        ->expectsOutput('Diagnostic requires prepare without --execute. No SQL, API or filesystem write performed.')
        ->assertExitCode(1);
    expect(glob($this->temp.'/*'))->toBe([]);
});

it('labels a preparation failure at the exact documentary stage without leaking its response', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $state->invalidReport = true;
    app()->instance(TrialRuntime::class, $runtime);
    $exit = Artisan::call('superpdp:sandbox-trial', ['operation' => 'prepare', '--execute' => true,
        '--operator' => 'Fixture operator', '--confirm-tricatel-address' => true,
        '--date' => '2026-11-13', '--due-date' => '2026-12-13']);
    $output = Artisan::output();
    expect($exit)->toBe(1)->and($output)->toContain('document.validation', 'RuntimeException', 'corrective_action')
        ->and($output)->not->toContain('offline-access', 'offline-refresh', 'subreports', 'SIMULATION-EN16931-CII');
});

it('reports a missing private directory as inconclusive rather than inventing an OAuth failure', function () {
    $storage = new class($this->temp) extends TrialStorage
    {
        public function __construct(private string $fixtureRoot) {}

        protected function verify(bool $readOnly): array
        {
            return ['root' => $this->fixtureRoot, 'exists' => false];
        }
    };
    $report = (new TrialRuntime($storage))->diagnosePreparation();
    expect($report['status'])->toBe('inconclusive')->and($report['stage'])->toBe('storage.path')
        ->and($report['oauth'])->toBe('not_checked_no_sql_or_api')
        ->and($report['historical_failure'])->toBe('not_recorded_by_previous_version')
        ->and(glob($this->temp.'/*'))->toBe([]);
});

it('distinguishes failure to create an artifact from an existing artifact', function () {
    $storage = new TrialStorage;
    try {
        $storage->create($this->temp.'/nonexistent-parent', 'test.json', '{}');
        test()->fail('Expected write failure.');
    } catch (RuntimeException $exception) {
        $report = TrialDiagnostic::describe(TrialDiagnostic::failure($exception, 'preparation.marker'));
        expect($report['reason'])->toBe('artifact_write_failed')->and($report['stage'])->toBe('preparation.marker');
    }
});

it('accepts only the sanitized PowerShell diagnostic protocol', function (string $output, string $reason, string $stage) {
    $storage = new class($output) extends TrialStorage
    {
        public string $script = '';

        public function __construct(private string $fixtureOutput) {}

        protected function probe(string $script): array
        {
            $this->script = $script;

            return [false, $this->fixtureOutput];
        }
    };
    try {
        $storage->diagnose();
        test()->fail('Expected safe storage failure.');
    } catch (TrialFailure $failure) {
        $report = TrialDiagnostic::describe($failure);
        expect($report['reason'])->toBe($reason)->and($report['stage'])->toBe($stage)
            ->and($storage->script)->toStartWith('$readOnly=$true')
            ->and(json_encode($report))->not->toContain('SECRET');
    }
})->with([
    ['ERROR|storage.acl|storage_acl|RuntimeException', 'storage_acl', 'storage.acl'],
    ['ERROR|storage.create|storage_access|UnauthorizedAccessException', 'storage_access', 'storage.create'],
    ['ERROR|storage.acl|storage_acl|SECRET', 'storage_process', 'storage.powershell'],
    ['password=SECRET Bearer SECRET', 'storage_process', 'storage.powershell'],
]);

it('executes Artisan preparation and the separately authorized deposit against synthetic SDK responses', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    app()->instance(TrialRuntime::class, $runtime);
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'prepare', '--execute' => true,
        '--operator' => 'Fixture operator', '--confirm-tricatel-address' => true,
        '--date' => '2026-11-13', '--due-date' => '2026-12-13'])->assertExitCode(0);
    $index = json_decode(file_get_contents($this->temp.'/prepared.json'), true);
    $this->artisan('superpdp:sandbox-trial', ['operation' => 'deposit', '--execute' => true,
        '--operator' => 'Fixture operator', '--authorize-single-deposit' => true,
        '--attempt' => $index['attempt_reference'], '--package-sha256' => $index['package_sha256'],
        '--cii-sha256' => $index['cii_sha256']])->assertExitCode(0);
    expect(count(array_filter($state->requests, fn ($r) => $r['path'] === '/v1.beta/invoices')))->toBe(1);
});

it('stops a failed preparation permanently instead of regenerating silently', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $state->invalidReport = true;
    expect(fn () => $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator'))->toThrow(RuntimeException::class);
    expect(is_file($this->temp.'/document.cii.xml'))->toBeTrue()->and(is_file($this->temp.'/prepared.json'))->toBeFalse();
    $count = count($state->requests);
    $state->invalidReport = false;
    expect(fn () => $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator'))->toThrow(RuntimeException::class);
    expect(count($state->requests))->toBe($count);
});

it('holds the global operation lock before any preparation request', function () {
    [$runtime, $state] = offlineTrialRuntime($this);
    $handle = fopen($this->temp.'/operation.lock', 'c+b');
    flock($handle, LOCK_EX);
    try {
        expect(fn () => $runtime->prepare('2026-11-13', '2026-12-13', 'Fixture operator'))->toThrow(RuntimeException::class);
        expect($state->requests)->toBe([])->and(is_file($this->temp.'/preparation.started'))->toBeFalse();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('loads the runtime identity in a read-only rolled-back sandbox transaction using no PDO', function () {
    config(['database.connections.superpdp_sandbox.username' => 'fabyjulien_simpledevis_test']);
    SandboxDatabaseIsolation::install(app());
    $rows = ['users' => ['id' => 3], 'companies' => $this->company->getAttributes(), 'superpdp_connections' => $this->connection->getAttributes()];
    $fake = new class($rows) extends Connection
    {
        public array $operations = [];

        public function __construct(private array $rows)
        {
            parent::__construct(null, 'fabyjulien_simpledevis_test');
        }

        public function statement($query, $bindings = [])
        {
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
                if (str_contains($query, '"'.$table.'"')) {
                    return [(object) $row];
                }
            }
            throw new RuntimeException('Unexpected fake query.');
        }
    };
    $resolver = Model::getConnectionResolver();
    $fakeResolver = new ConnectionResolver(['superpdp_sandbox' => $fake]);
    $fakeResolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($fakeResolver);
    DB::shouldReceive('connection')->once()->with('superpdp_sandbox')->andReturn($fake);
    DB::shouldReceive('getConnections')->andReturn([]);
    $runtime = new class(new TrialStorage) extends TrialRuntime
    {
        public function inspectIdentity(): array
        {
            return $this->identity();
        }
    };
    try {
        $identity = $runtime->inspectIdentity();
        expect($identity[0]->getKey())->toBe(3)->and($fake->operations[0])->toBe('SET TRANSACTION READ ONLY')
            ->and($fake->operations[1])->toBe('begin')->and(end($fake->operations))->toBe('rollback')
            ->and($fake->operations)->toHaveCount(7);
        expect(strtolower(implode(' ', $fake->operations)))->not->toContain('insert ', 'update ', 'delete ');
    } finally {
        Model::setConnectionResolver($resolver);
    }
});

it('freezes a separate invoice and archive while preserving manual provenance and production locks', function () {
    $p = ($this->package)();
    $path = $p->archive($this->temp);
    TrialPackage::verifyArchive($path, $p->sha256());
    expect(TrialPackage::readArchive($path, $p->sha256())->bytes())->toBe($p->bytes());
    $report = ($this->inspect)($p);
    expect($report['experimental_prerequisites_passed'])->toBeTrue()
        ->and($report['ready_for_emission'])->toBeFalse()->and($report['routing_api_verified'])->toBeFalse()
        ->and($p->manifest['routing']['source'])->toBe('superpdp_interface')
        ->and($p->manifest['invoice']['invoice_number'])->toBe('SANDBOX-PREPARATION-BQ-TRICATEL')
        ->and($p->archive($this->temp))->toBe($path)
        ->and($p->bytes())->not->toContain('offline-access', 'offline-refresh')
        ->and(app(EmissionPrerequisites::class))->toBeInstanceOf(ClosedEmissionPrerequisites::class)
        ->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class)
        ->and(config('superpdp_emission.sandbox_enabled'))->toBeFalse();
    $copy = $p->manifest;
    $copy['routing']['api_verified'] = true;
    expect($p->manifest['routing']['api_verified'])->toBeFalse();
    file_put_contents($path, 'changed');
    expect(fn () => TrialPackage::verifyArchive($path, $p->sha256()))->toThrow(RuntimeException::class);
    expect(fn () => $p->archive($this->temp))->toThrow(RuntimeException::class);
});

it('refuses simulated or cached documentary and seller evidence', function (string $source) {
    expect(fn () => ($this->inspect)(($this->package)($source)))->toThrow(RuntimeException::class);
    expect(fn () => ($this->inspect)(($this->package)(), ($this->seller)($source)))->toThrow(RuntimeException::class);
})->with(['simulated', 'cached']);

it('refuses a validation receipt bound to another CII and hidden alternate destinations', function () {
    expect(fn () => ($this->package)('live', validationInput: str_repeat('0', 64)))->toThrow(RuntimeException::class);
    expect(fn () => ($this->package)('live', str_replace('315143296_18171', '315143296_18171_replyto', $this->xml)))->toThrow(RuntimeException::class);
});

it('refuses changed OAuth credentials and remote seller identity', function () {
    $p = ($this->package)();
    expect(fn () => ($this->inspect)($p, ($this->seller)('live', 'another-token')))->toThrow(RuntimeException::class);
    $this->sellerData['companies/me']['id'] = 18171;
    expect(fn () => ($this->inspect)($p))->toThrow(RuntimeException::class);
});

it('refuses stale evidence even with a new approval and checks OAuth expiry margin', function () {
    $p = ($this->package)();
    $proofs = ($this->seller)();
    Carbon::setTestNow(now()->addSeconds(901));
    expect(fn () => ($this->inspect)($p, $proofs))->toThrow(RuntimeException::class);
    Carbon::setTestNow('2026-11-13T17:00:00Z');
    $this->connection->access_token_expires_at = now()->addMinutes(5);
    expect(fn () => ($this->inspect)($p))->toThrow(RuntimeException::class);
});

it('refuses every non-approved scope', function (string $change) {
    $p = ($this->package)();
    match ($change) {
        'production' => $this->app->instance('env', 'production'),
        'config' => config(['app.env' => 'production']),
        'company' => $this->company->id = 4,
        'owner' => $this->company->user_id = 4,
        'buyer' => config(['services.superpdp.sandbox_identity.number' => '000000001']),
        'provider' => $this->connection->superpdp_company_id = 18171,
    };
    expect(fn () => ($this->inspect)($p))->toThrow(RuntimeException::class);
})->with(['production', 'config', 'company', 'owner', 'buyer', 'provider']);

it('requires an exact approval and leaves provider prechecks enabled in the unsent SDK request', function (string $field, mixed $value) {
    $p = ($this->package)();
    $attempt = $this->ledger->reserve($p);
    $approval = ($this->approval)($p, $attempt['attempt_reference']);
    $plan = (new TrialGate)->requestPlan($p, $attempt['attempt_reference'], $approval);
    expect($plan->resolveEndpoint())->toBe('/v1.beta/invoices')
        ->and($plan->query()->all())->not->toHaveKey('disable_pre_check')
        ->and($plan->query()->get('external_id'))->toBe($attempt['attempt_reference']);
    $approval[$field] = $value;
    expect(fn () => (new TrialGate)->requestPlan($p, $attempt['attempt_reference'], $approval))->toThrow(RuntimeException::class);
})->with([
    ['recipient', '0225:315143296_18171_replyto'], ['provider_company_id', 18171],
    ['local_company_id', 4], ['environment', 'production'], ['disable_pre_check', true],
    ['routing_evidence', 'live'], ['package_sha256', 'changed'], ['cii_sha256', 'changed'],
    ['attempt_reference', 'another-attempt'], ['approved_by', ''], ['expires_at', 1794589200],
]);

it('claims durably once and permanently refuses replay after every result', function (?int $http, ?string $body, string $expected) {
    $p = ($this->package)();
    $path = $p->archive($this->temp);
    $attempt = $this->ledger->reserve($p);
    expect($this->ledger->reserve($p)['attempt_reference'])->toBe($attempt['attempt_reference']);
    $approval = ($this->approval)($p, $attempt['attempt_reference']);
    $seller = ($this->seller)();
    $claim = (new TrialGate)->prepareClaim($p, $this->context, $this->company, $this->connection,
        $seller[0], $seller[1], $seller[2], $path, $this->ledger, $approval);
    // Reopen process-local objects: claim is already on disk before a future transport.
    $reopened = new TrialLedger($this->temp);
    expect($reopened->history()[1]['status'])->toBe('unknown');
    $body = $body === 'valid' ? json_encode(['id' => 42, 'company_id' => 18172, 'direction' => 'out',
        'external_id' => $attempt['attempt_reference'], 'created_at' => now()->toIso8601String()]) : $body;
    $result = $reopened->recordOutcome($p, $claim['claim_token'], $http, $body);
    expect($result['status'])->toBe($expected);
    expect(fn () => ($this->claim)($p, $approval, $reopened))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->recordOutcome($p, $claim['claim_token'], $http, $body))->toThrow(RuntimeException::class);
})->with([[200, 'valid', 'accepted'], [400, '{"http_status_code":400,"message":"fixture"}', 'rejected'],
    [null, null, 'unknown'], [500, '{}', 'unknown'], [200, '{"id":1,"id":2}', 'unknown']]);

it('fails closed after a crash, incomplete ledger or a changed package', function () {
    $p = ($this->package)();
    $attempt = $this->ledger->reserve($p);
    ($this->claim)($p, ($this->approval)($p, $attempt['attempt_reference']));
    expect(fn () => ($this->claim)($p, ($this->approval)($p, $attempt['attempt_reference']), new TrialLedger($this->temp)))->toThrow(RuntimeException::class);
    Carbon::setTestNow(now()->addSecond());
    expect(fn () => $this->ledger->reserve(($this->package)()))->toThrow(RuntimeException::class);
    file_put_contents($this->temp.DIRECTORY_SEPARATOR.'burger-queen-3-tricatel-first-deposit.jsonl', '{partial', FILE_APPEND);
    expect(fn () => $this->ledger->reserve($p))->toThrow(RuntimeException::class);
});

it('fails closed on a competing file lock without changing the journal', function () {
    $p = ($this->package)();
    $this->ledger->reserve($p);
    $handle = fopen($this->temp.DIRECTORY_SEPARATOR.'burger-queen-3-tricatel-first-deposit.jsonl', 'r+b');
    flock($handle, LOCK_EX);
    try {
        expect(fn () => $this->ledger->reserve($p))->toThrow(RuntimeException::class);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    expect($this->ledger->history())->toHaveCount(1);
});

it('does not consume a reservation when approval, seller freshness or archived bytes fail', function (string $failure) {
    $p = ($this->package)();
    $path = $p->archive($this->temp);
    $attempt = $this->ledger->reserve($p);
    $approval = ($this->approval)($p, $attempt['attempt_reference']);
    $seller = ($this->seller)();
    match ($failure) {
        'approval' => $approval = [],
        'duration' => $approval['expires_at'] = now()->timestamp + 901,
        'archive' => file_put_contents($path, 'altered'),
        'seller' => $this->sellerData['oauth2_sessions/me']['company_verification_status'] = 'failed',
        'expiry' => $this->connection->access_token_expires_at = now()->addMinute(),
        'stale' => Carbon::setTestNow(now()->addSeconds(901)),
    };
    if ($failure === 'seller') {
        $seller = ($this->seller)();
    }
    expect(fn () => (new TrialGate)->prepareClaim($p, $this->context, $this->company, $this->connection,
        $seller[0], $seller[1], $seller[2], $path, $this->ledger, $approval))->toThrow(RuntimeException::class);
    expect($this->ledger->history())->toHaveCount(1);
})->with(['approval', 'duration', 'archive', 'seller', 'expiry', 'stale']);

it('refuses invalid or future timestamps instead of restamping evidence', function (string $time) {
    expect(fn () => TrialGate::fresh($time))->toThrow(RuntimeException::class);
})->with(['2026-11-13T17:00:01Z', '2026-02-30T17:00:00Z', '2026-11-13 17:00:00', '2026-11-13T16:44:59Z']);

it('refuses checksum damage and an empty preexisting ledger without recreating it', function (bool $empty) {
    $p = ($this->package)();
    $this->ledger->reserve($p);
    $path = $this->temp.DIRECTORY_SEPARATOR.'burger-queen-3-tricatel-first-deposit.jsonl';
    file_put_contents($path, $empty ? '' : str_replace('reserved', 'changed', file_get_contents($path)));
    expect(fn () => $this->ledger->reserve($p))->toThrow(RuntimeException::class);
})->with([false, true]);

it('does not treat a foreign, malformed, redirected or ambiguous response as acceptance', function (?int $http, string $body) {
    expect(TrialOutcome::classify($http, $body, 'expected')['status'])->toBe('unknown');
})->with([[302, '{}'], [429, '{}'], [200, 'invalid'], [200, '{"id":1,"company_id":18171,"direction":"out","external_id":"expected","created_at":"2026-11-13T17:00:00Z"}'],
    [400, '{"http_status_code":400,"message":"fixture","id":42}']]);

it('tracks full pages separately from deposit acceptance without inventing recipient delivery', function () {
    $events = new TrialEvents(42);
    $event = fn ($id, $code, $invoice = 42) => ['id' => $id, 'invoice_id' => $invoice, 'status_code' => $code, 'created_at' => '2026-11-13T17:00:00Z'];
    $page = ['data' => [$event(1, 'api:uploaded'), $event(2, 'api:sent'), $event(3, 'api:received')], 'has_after' => true];
    $report = $events->applyPage($page);
    expect($report['transmitted_to_access_point'])->toBeTrue()->and($report['remote_acknowledgment'])->toBeFalse()
        ->and($report['recipient_business_receipt'])->toBe('not_proven_by_deposit_acceptance')
        ->and($events->query())->toBe(['invoice_id' => 42, 'limit' => 100, 'starting_after_id' => 3]);
    expect($events->applyPage($page)['events'])->toHaveCount(3);
    $bad = ['data' => [$event(4, 'api:accepted'), $event(5, 'fr:202', 99)], 'has_after' => false];
    expect(fn () => $events->applyPage($bad))->toThrow(RuntimeException::class);
    expect($events->query()['starting_after_id'])->toBe(3);
    expect(fn () => $events->applyPage(['data' => [$event(3, 'api:rejected')], 'has_after' => false]))->toThrow(RuntimeException::class);
    $report = $events->applyPage(['data' => [$event(4, 'api:acknowledged'), $event(5, 'api:accepted'), $event(6, 'fr:202'), $event(7, 'api:rejected')], 'has_after' => false]);
    expect($report['remote_acceptance'])->toBeTrue()->and($report['french_received_event'])->toBeTrue()
        ->and($report['asynchronous_failure'])->toBeTrue()->and($report['deposit_accepted_is_delivery'])->toBeFalse();
});

it('locally inspects the exact forward CII but never upgrades a local report to live evidence', function () {
    $result = TrialArtifactInspection::inspect($this->xml, hash('sha256', $this->xml), PreparedCiiFixture::report($this->xml));
    expect($result['validation_report_consistent'])->toBeTrue()->and($result['live_validation_proven'])->toBeFalse()
        ->and($result['ready_for_emission'])->toBeFalse();
    $inverse = file_get_contents(base_path('factures-test/reference-superpdp-tricatel.xml'));
    expect(fn () => TrialArtifactInspection::inspect($inverse, hash('sha256', $inverse)))->toThrow(RuntimeException::class);
    expect(fn () => TrialArtifactInspection::inspect($this->xml, str_repeat('0', 64)))->toThrow(RuntimeException::class);
});

it('reuses unchanged historical documentary bytes with newly checked seller evidence', function () {
    $p = ($this->package)();
    $path = $p->archive($this->temp);
    Carbon::setTestNow(now()->addHour());
    $this->connection->access_token_expires_at = now()->addHour();
    $restored = TrialPackage::readArchive($path, $p->sha256());
    expect(($this->inspect)($restored)['experimental_prerequisites_passed'])->toBeTrue()
        ->and($restored->sha256())->toBe($p->sha256());
});

it('offers only local CLI inspection and never accepts an execute flag', function () {
    $script = base_path('tools/prepare-superpdp-sandbox-trial.php');
    $run = static function (array $arguments) use ($script) {
        $process = new Process(array_merge([PHP_BINARY, '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client', $script], $arguments));
        $process->run();

        return $process;
    };
    expect($run([])->getExitCode())->toBe(0)->and($run(['--execute'])->getExitCode())->toBe(1);
    $ciiPath = $this->temp.DIRECTORY_SEPARATOR.'fixture.xml';
    $reportPath = $this->temp.DIRECTORY_SEPARATOR.'report.json';
    file_put_contents($ciiPath, $this->xml);
    file_put_contents($reportPath, json_encode(PreparedCiiFixture::report($this->xml)));
    $process = $run(['--cii='.$ciiPath, '--expected-cii-sha256='.hash('sha256', $this->xml), '--validation-report='.$reportPath]);
    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), true)['live_validation_proven'])->toBeFalse();
});

it('resolves unknown only with fresh positive metadata and the exact original, without replay', function (string $failure) {
    $p = ($this->package)();
    $attempt = $this->ledger->reserve($p);
    $approval = ($this->approval)($p, $attempt['attempt_reference']);
    $claim = ($this->claim)($p, $approval);
    $this->ledger->recordOutcome($p, $claim['claim_token'], null, null);
    // Reconciliation may happen after the original conversion has expired.
    Carbon::setTestNow(now()->addHour());
    $readApproval = ($this->approval)($p, $attempt['attempt_reference']);
    $readApproval['purpose'] = 'reconciliation_read';
    $readApproval['provider_invoice_id'] = 42;
    $data = ['id' => 42, 'company_id' => 18172, 'direction' => 'out',
        'external_id' => $attempt['attempt_reference'], 'created_at' => now()->toIso8601String()];
    if ($failure === 'foreign') {
        $data['company_id'] = 18171;
    }
    if ($failure === 'correlation') {
        $data['external_id'] = 'foreign';
    }
    if ($failure === 'missing') {
        $data = [];
    }
    if ($failure === 'approval') {
        $readApproval['purpose'] = 'single_deposit';
    }
    $xml = $failure === 'original' ? $p->xml.' ' : $p->xml;
    $source = $failure === 'simulated' ? 'simulated' : 'live';
    $metadata = VerificationObservation::fromResponse($data, ($this->response)('invoices/42', json_encode($data), $source));
    $original = VerificationObservation::fromResponse(['xml' => $xml], ($this->response)('invoices/42?format=original', $xml, $source));
    if ($failure !== 'none') {
        expect(fn () => $this->ledger->reconcilePositive($p, $readApproval, $metadata, $original))->toThrow(RuntimeException::class);
        $history = $this->ledger->history();
        expect(end($history)['status'])->toBe('unknown');
    } else {
        $result = $this->ledger->reconcilePositive($p, $readApproval, $metadata, $original);
        expect($result['status'])->toBe('accepted')->and($result['provider_invoice_id'])->toBe(42);
        expect(fn () => ($this->claim)($p, ($this->approval)($p, $attempt['attempt_reference'])))->toThrow(RuntimeException::class);
    }
})->with(['none', 'foreign', 'correlation', 'missing', 'approval', 'original', 'simulated']);

it('persists full event pages and the cursor atomically and keeps asynchronous rejection separate', function () {
    $p = ($this->package)();
    $attempt = $this->ledger->reserve($p);
    $approval = ($this->approval)($p, $attempt['attempt_reference']);
    $claim = ($this->claim)($p, $approval);
    $data = ['id' => 42, 'company_id' => 18172, 'direction' => 'out',
        'external_id' => $attempt['attempt_reference'], 'created_at' => now()->toIso8601String()];
    $this->ledger->recordOutcome($p, $claim['claim_token'], 200, json_encode($data));
    $approval['purpose'] = 'event_read';
    $approval['provider_invoice_id'] = 42;
    $event = ['id' => 1, 'invoice_id' => 42, 'status_code' => 'api:rejected', 'created_at' => now()->toIso8601String()];
    $this->ledger->recordEventPage($p, $approval, ['data' => [$event], 'has_after' => true]);
    $history = (new TrialLedger($this->temp))->history();
    $last = end($history);
    expect($last['status'])->toBe('accepted')->and($last['event_summary']['asynchronous_failure'])->toBeTrue()
        ->and($last['event_summary']['cursor'])->toBe(1)->and($last['event_summary']['has_after'])->toBeTrue();
    $event['invoice_id'] = 99;
    expect(fn () => $this->ledger->recordEventPage($p, $approval, ['data' => [$event], 'has_after' => false]))->toThrow(RuntimeException::class);
    expect($this->ledger->history())->toHaveCount(count($history));
});
