<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Documentary\ReferenceArchive;
use App\Services\SuperPdp\Documentary\ReferenceScope;
use App\Services\SuperPdp\Documentary\ReferenceTest;
use Bluerock\SuperPdp\Requests\Invoices\GenerateTestInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Senders\GuzzleSender;
use Symfony\Component\Process\Process;
use Tests\Support\CapturedSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! defined('SIMPLEDEVIS_DOCUMENTARY_OFFLINE_TEST')) {
        define('SIMPLEDEVIS_DOCUMENTARY_OFFLINE_TEST', true);
    }
    $this->resolver = Model::getConnectionResolver();
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
    foreach (['mysql', 'sqlite', 'pgsql', 'sqlsrv'] as $driver) {
        DB::extend($driver, fn () => throw new RuntimeException('SQL forbidden.'));
    }
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(fn () => throw new RuntimeException('Network forbidden.'));
        }
    });
    Carbon::setTestNow('2026-11-14T18:00:00Z');
    $this->app->instance('env', 'sandbox');
    $this->scope = ['run' => 'first-reference', 'user_id' => 3, 'company_id' => 3, 'connection_id' => 1,
        'provider_company_id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002',
        'environment' => 'sandbox', 'client_id' => 'offline-client', 'api_url' => ReferenceScope::API];
    $this->xml = CapturedSandboxCii::xml();
    $this->root = sys_get_temp_dir().'/simpledevis-reference-'.bin2hex(random_bytes(12));
    $this->scope['private_root'] = $this->root;
    mkdir($this->root, 0700);
    // Only platform ACL probing is simulated; real exclusive files, hashes, fsync and locks are exercised.
    $this->archive = new class($this->root) extends ReferenceArchive
    {
        protected function assertPermissions(string $real): void {}
    };
    config(['app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => ReferenceScope::API, 'services.superpdp.client_id' => 'offline-client',
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
        'superpdp_documentary.enabled' => true, 'superpdp_documentary.scope' => $this->scope,
        'superpdp_documentary.private_root' => $this->root]);
    $this->approval = fn ($purpose) => ['scope' => $this->scope, 'purpose' => $purpose, 'approved_by' => 'offline-test',
        'approved_at' => now()->timestamp, 'expires_at' => now()->timestamp + 300,
        'cii_sha256' => hash('sha256', $this->xml)];
    config(['superpdp_documentary.generation_authorization' => ($this->approval)('generation'),
        'superpdp_documentary.validation_authorization' => ($this->approval)('validation')]);
    $this->user = (new User)->forceFill(['id' => 3]);
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->connection = (new SuperPdpConnection)->setDateFormat('Y-m-d H:i:s')->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'directory_identifier' => '000000002',
        'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'api_url' => ReferenceScope::API,
        'status' => 'connected', 'access_token' => 'offline-access', 'refresh_token' => 'offline-refresh',
        'access_token_expires_at' => now()->addHour()]);
    foreach ([$this->user, $this->company, $this->connection] as $model) {
        $model->exists = true;
        $model->setConnection('superpdp_sandbox');
    }
    $this->before = [$this->user->getAttributes(), $this->company->getAttributes(), $this->connection->getAttributes()];
    $this->mock = new MockClient([
        GenerateTestInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
    ]);
    $this->run = fn ($op = 'generation', $mock = null) => app(ReferenceTest::class)->run('first-reference', $op,
        $this->user, $this->company, $this->connection, $this->archive, $mock ?? $this->mock);
    $this->directory = $this->root.'/first-reference';
});

afterEach(function () {
    expect([$this->user->getAttributes(), $this->company->getAttributes(), $this->connection->getAttributes()])->toBe($this->before);
    Model::setConnectionResolver($this->resolver);
    Carbon::setTestNow();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Http::assertNothingSent();
    // Only this random test-owned temporary directory can be removed.
    $real = realpath($this->root);
    if ($real && str_starts_with($real, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'simpledevis-reference-')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($real);
    }
});

it('defaults to no SQL filesystem or network and rejects combined operations', function () {
    config(['superpdp_documentary.enabled' => false]);
    expect(Artisan::call('superpdp:sandbox-cii-reference'))->toBe(0)
        ->and(Artisan::output())->toContain(ReferenceTest::LABEL)
        ->and(Artisan::call('superpdp:sandbox-cii-reference', ['--generate' => true, '--validate' => true]))->toBe(1)
        ->and(scandir($this->root))->toBe(['.', '..']);
    $this->mock->assertNothingSent();
});

it('rejects a closed real command before SQL', function () {
    config(['superpdp_documentary.generation_authorization' => null]);
    expect(Artisan::call('superpdp:sandbox-cii-reference', ['--generate' => true, '--run' => 'first-reference']))->toBe(1)
        ->and(scandir($this->root))->toBe(['.', '..']);
});

it('rejects server scope and authorization failures without requests', function ($key, $value) {
    config([$key => $value]);
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    $this->mock->assertNothingSent();
})->with([
    ['superpdp_documentary.enabled', false], ['app.env', 'production'],
    ['services.superpdp.environment', 'production'], ['services.superpdp.client_id', 'other'],
    ['services.superpdp.api_url', 'https://example.invalid'], ['superpdp_documentary.scope.user_id', 4],
    ['superpdp_documentary.scope.company_id', 5], ['superpdp_documentary.scope.number_scheme', 'fr_siren'],
    ['superpdp_documentary.scope.run', 'other'], ['superpdp_documentary.generation_authorization', null],
    ['superpdp_documentary.private_root', 'other-root'],
    ['superpdp_documentary.generation_authorization.expires_at', 1],
    ['superpdp_documentary.generation_authorization.approved_at', 9999999999],
    ['superpdp_documentary.generation_authorization.purpose', 'validation'],
]);

it('rejects expired missing or wrongly bound OAuth and cross-company models', function ($field, $value) {
    $this->connection->$field = $value;
    $this->before[2] = $this->connection->getAttributes();
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    $this->mock->assertNothingSent();
})->with([
    ['access_token_expires_at', '2026-11-14 18:04:59'], ['access_token_expires_at', null],
    ['access_token', ''], ['status', 'disconnected'], ['company_id', 4], ['environment', 'production'],
    ['oauth_client_id', 'other'], ['directory_identifier', '000000001'], ['superpdp_company_id', 18171],
]);

it('archives exact XML and provenance with no business side effects and reuses without GET', function () {
    $result = ($this->run)();
    expect($result['status'])->toBe('cii_received')->and($result['validation'])->toBe('not_performed')
        ->and($result['provenance']['source'])->toBe('simulated')->and($result['provenance']['request']['method'])->toBe('GET')
        ->and($result['provenance']['request']['query'])->toBe(['format' => 'cii'])
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['simpledevis_invoice_id'])->toBeNull()
        ->and(file_get_contents($this->directory.'/document.cii.xml'))->toBe($this->xml)
        ->and(($this->run)()['reused'])->toBeTrue();
    $this->mock->assertSentCount(1);
    expect(file_get_contents($this->directory.'/generation.result.json'))->not->toContain('offline-access', 'offline-refresh');
});

it('requires separate validation authorization and exact CII fingerprint', function ($approval) {
    ($this->run)();
    config(['superpdp_documentary.validation_authorization' => $approval === 'absent' ? null :
        array_replace(($this->approval)('validation'), ['cii_sha256' => str_repeat('a', 64)])]);
    expect(fn () => ($this->run)('validation'))->toThrow(RuntimeException::class);
    $this->mock->assertSentCount(1);
})->with(['absent', 'wrong_hash']);

it('validates exact archived bytes without Bearer and never generates again', function () {
    ($this->run)();
    $requests = [];
    Config::globalMiddleware()->onRequest(function ($request) use (&$requests) {
        $requests[] = [$request->getRequest()::class, $request->headers()->get('Authorization')];
    });
    $result = ($this->run)('validation');
    expect($result['validation'])->toBe('passed')->and($requests)->toBe([[CreateValidationReportRequest::class, null]])
        ->and(($this->run)('validation')['reused'])->toBeTrue();
    $this->mock->assertSentCount(2);
});

it('preserves the CII and exact report when validation fails or is malformed', function ($kind) {
    ($this->run)();
    $report = PreparedCiiFixture::report($this->xml);
    $report['data'][0]['is_valid'] = false;
    $report['data'][0]['subreports'][0]['failures'] = ['test failure'];
    $body = $kind === 'failed' ? json_encode($report) : '{invalid json';
    $mock = new MockClient([CreateValidationReportRequest::class => MockResponse::make($body)]);
    $result = ($this->run)('validation', $mock);
    expect($result['validation'])->toBe($kind === 'failed' ? 'failed' : 'unavailable')
        ->and(file_get_contents($this->directory.'/validation-report.json'))->toBe($body)
        ->and(file_get_contents($this->directory.'/document.cii.xml'))->toBe($this->xml);
})->with(['failed', 'malformed']);

it('blocks every replay after an invalid generation response', function ($body, $status, $type) {
    $mock = new MockClient([GenerateTestInvoiceRequest::class => MockResponse::make($body, $status, ['Content-Type' => $type])]);
    expect(fn () => ($this->run)('generation', $mock))->toThrow(RuntimeException::class);
    expect(fn () => ($this->run)('generation', $mock))->toThrow(RuntimeException::class);
    $mock->assertSentCount(1);
})->with([
    ['not xml', 200, 'application/xml'], ['<wrong/>', 200, 'application/xml'],
    ['<!DOCTYPE a SYSTEM "file:///secret"><a/>', 200, 'application/xml'],
    ['error', 500, 'application/xml'], ['<a/>', 200, 'text/html'],
]);

it('blocks incomplete intent markers without sending', function () {
    mkdir($this->directory);
    file_put_contents($this->directory.'/generation.intent.json', '{partial');
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class, 'interrompue');
    $this->mock->assertNothingSent();
});

it('blocks validation interruptions while preserving the generated document', function () {
    ($this->run)();
    file_put_contents($this->directory.'/validation.intent.json', 'interrupted');
    expect(fn () => ($this->run)('validation'))->toThrow(RuntimeException::class, 'interrompue');
    expect(file_get_contents($this->directory.'/document.cii.xml'))->toBe($this->xml);
    $this->mock->assertSentCount(1);
});

it('refuses concurrent operations while a filesystem lock is held', function () {
    $this->archive->locked('first-reference', function () {
        expect(fn () => ($this->run)())->toThrow(RuntimeException::class, 'cours');
    });
    $this->mock->assertNothingSent();
});

it('rejects changed missing or partially committed archives without another call', function ($file, $value) {
    ($this->run)();
    if ($value === null) {
        unlink($this->directory.'/'.$file);
    } else {
        file_put_contents($this->directory.'/'.$file, $value);
    }
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    $this->mock->assertSentCount(1);
})->with([
    ['document.cii.xml', '<changed/>'], ['document.cii.xml', null],
    ['generation.result.json', '{}'], ['generation.result.sha256', null], ['scope.json', '{}'],
]);

it('rejects archive path traversal and exposed repository roots', function () {
    expect(fn () => $this->archive->locked('../other', fn () => null))->toThrow(RuntimeException::class)
        ->and(fn () => new ReferenceArchive(base_path()))->toThrow(RuntimeException::class);
});

it('isolates the loaded user and company from another owner', function ($target, $field, $value) {
    $this->$target->$field = $value;
    $this->before = [$this->user->getAttributes(), $this->company->getAttributes(), $this->connection->getAttributes()];
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    $this->mock->assertNothingSent();
})->with([['user', 'id', 4], ['company', 'user_id', 4], ['company', 'id', 4], ['company', 'company_name', 'Other']]);

it('allows the separately pinned Tricatel sandbox identity without legal identifiers', function () {
    $this->scope = array_replace($this->scope, ['user_id' => 4, 'company_id' => 4, 'connection_id' => 2,
        'provider_company_id' => 18171, 'number' => '000000001']);
    config(['superpdp_documentary.scope' => $this->scope,
        'superpdp_documentary.generation_authorization' => ($this->approval)('generation')]);
    $this->user->id = 4;
    $this->company->forceFill(['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel']);
    $this->connection->forceFill(['id' => 2, 'company_id' => 4, 'superpdp_company_id' => 18171, 'directory_identifier' => '000000001']);
    $this->before = [$this->user->getAttributes(), $this->company->getAttributes(), $this->connection->getAttributes()];
    expect(($this->run)()['status'])->toBe('cii_received')->and($this->company->siret)->toBeNull();
    $this->mock->assertSentCount(1);
});

it('cannot validate without a fully committed generated archive', function () {
    expect(fn () => ($this->run)('validation'))->toThrow(RuntimeException::class);
    $this->mock->assertNothingSent();
});

it('preserves CII and blocks retries after unavailable validation transport', function () {
    ($this->run)();
    $mock = new MockClient([CreateValidationReportRequest::class => MockResponse::make('unavailable', 503)]);
    expect(fn () => ($this->run)('validation', $mock))->toThrow(RuntimeException::class);
    expect(fn () => ($this->run)('validation', $mock))->toThrow(RuntimeException::class, 'interrompue');
    expect(file_get_contents($this->directory.'/document.cii.xml'))->toBe($this->xml);
    $mock->assertSentCount(1);
});

it('refuses changed validation reports on consultation without network', function () {
    ($this->run)();
    ($this->run)('validation');
    file_put_contents($this->directory.'/validation-report.json', '{}');
    expect(fn () => ($this->run)('validation'))->toThrow(RuntimeException::class, 'altéré');
    $this->mock->assertSentCount(2);
});

it('enforces the operation lock across independent PHP processes', function () {
    $probe = fn () => new Process([PHP_BINARY, '-r',
        '$h=fopen($argv[1], "c+b"); if(!flock($h, LOCK_EX|LOCK_NB))exit(9); flock($h, LOCK_UN); fclose($h);',
        $this->directory.'/operation.lock']);
    $this->archive->locked('first-reference', function () use ($probe) {
        $process = $probe();
        $process->run();
        expect($process->getExitCode())->toBe(9);
    });
    $process = $probe();
    $process->run();
    expect($process->getExitCode())->toBe(0);
    $this->mock->assertNothingSent();
});

it('loads models only inside a rolled back read-only database transaction', function () {
    $db = Mockery::mock();
    $db->shouldReceive('transactionLevel')->once()->andReturn(0);
    $db->shouldReceive('statement')->once()->with('SET TRANSACTION READ ONLY')->ordered();
    $db->shouldReceive('beginTransaction')->once()->ordered();
    $db->shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS selected_database')->ordered()
        ->andReturn((object) ['selected_database' => 'fabyjulien_simpledevis_test']);
    $db->shouldReceive('rollBack')->once()->ordered();
    DB::shouldReceive('connection')->once()->with('superpdp_sandbox')->andReturn($db);
    // The intentionally forbidden Eloquent resolver throws on the first SELECT; rollback must still occur.
    expect(fn () => app(ReferenceScope::class)->load($this->scope))->toThrow(RuntimeException::class, 'SQL forbidden');
});

it('executes the Artisan generation path using only injected models and SDK mocks', function () {
    $guard = Mockery::mock(ReferenceScope::class)->makePartial();
    $guard->shouldReceive('load')->once()->with($this->scope)->andReturn([$this->user, $this->company, $this->connection]);
    $this->app->instance(ReferenceScope::class, $guard);
    $actual = new ReferenceTest($guard);
    $service = Mockery::mock(ReferenceTest::class);
    $service->shouldReceive('run')->once()->andReturnUsing(fn ($run, $operation, $user, $company, $connection, $archive) => $actual->run($run, $operation, $user, $company, $connection, $archive, $this->mock));
    $this->app->instance(ReferenceTest::class, $service);
    $this->app->bind(ReferenceArchive::class, fn () => $this->archive);
    expect(Artisan::call('superpdp:sandbox-cii-reference', ['--generate' => true, '--run' => 'first-reference']))->toBe(0)
        ->and(Artisan::output())->toContain('cii_received', 'not_performed')->not->toContain('offline-access', '<rsm:');
    $this->mock->assertSentCount(1);
});

it('returns the proper Artisan exit code for separately authorized validation', function ($valid) {
    ($this->run)();
    $report = PreparedCiiFixture::report($this->xml);
    $report['data'][0]['is_valid'] = $valid;
    $mock = new MockClient([CreateValidationReportRequest::class => MockResponse::make($report)]);
    $guard = Mockery::mock(ReferenceScope::class)->makePartial();
    $guard->shouldReceive('load')->once()->andReturn([$this->user, $this->company, $this->connection]);
    $this->app->instance(ReferenceScope::class, $guard);
    $actual = new ReferenceTest($guard);
    $service = Mockery::mock(ReferenceTest::class);
    $service->shouldReceive('run')->once()->andReturnUsing(fn ($run, $operation, $user, $company, $connection, $archive) => $actual->run($run, $operation, $user, $company, $connection, $archive, $mock));
    $this->app->instance(ReferenceTest::class, $service);
    $this->app->bind(ReferenceArchive::class, fn () => $this->archive);
    expect(Artisan::call('superpdp:sandbox-cii-reference', ['--validate' => true, '--run' => 'first-reference']))->toBe($valid ? 0 : 1)
        ->and(Artisan::output())->toContain($valid ? 'passed' : 'failed')
        ->and(file_get_contents($this->directory.'/document.cii.xml'))->toBe($this->xml);
    $mock->assertSentCount(1);
})->with([true, false]);
