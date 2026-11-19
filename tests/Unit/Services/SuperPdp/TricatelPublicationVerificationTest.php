<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Verification\TricatelPublicationGateway;
use App\Services\SuperPdp\Verification\TricatelPublicationScope;
use App\Services\SuperPdp\Verification\TricatelPublicationVerification;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(static fn () => throw new RuntimeException('Network forbidden in offline tests.'));
        }
    });
    Carbon::setTestNow(Carbon::parse('2026-11-14T12:00:00Z'));
    $this->previousResolver = Model::getConnectionResolver();
    $offline = new Connection(null);
    $offline->beforeExecuting(static fn () => throw new RuntimeException('All SQL forbidden.'));
    $resolver = new ConnectionResolver(['superpdp_sandbox' => $offline]);
    $resolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($resolver);
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox', 'database.connections' => ['superpdp_sandbox' => [
            'driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test', 'username' => 'fabyjulien_simpledevis_test']],
    ]);
    Storage::fake('invoice_cii');
    $this->actor = (new User)->forceFill(['id' => 4]);
    $this->actor->exists = true;
    $this->actingAs($this->actor);
    $this->company = (new Company)->forceFill(['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null]);
    $this->company->exists = true;
    $this->connection = (new SuperPdpConnection)->forceFill([
        'id' => 2, 'company_id' => 4, 'environment' => 'sandbox', 'oauth_client_id' => 'offline-client',
        'api_url' => 'https://api.superpdp.tech/v1.beta', 'superpdp_company_id' => 18171,
        'directory_identifier' => '000000001', 'status' => 'connected', 'access_token' => 'TRICATEL-ACCESS-SECRET',
        'refresh_token' => 'TRICATEL-REFRESH-SECRET', 'access_token_expires_at' => now()->addHour(),
        'last_invoice_id' => 121, 'last_event_id' => 343, 'reception_enabled' => true,
    ]);
    $this->connection->exists = true;
    $this->remote = ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001', 'env' => 'sandbox',
        'formal_name' => 'Tricatel', 'country' => 'FR', 'created_at' => '2026-11-01T12:00:00Z'];
    $this->entry = ['id' => 98765, 'company' => $this->remote, 'directory' => 'peppol',
        'identifier' => TricatelPublicationScope::ADDRESS, 'status' => 'created', 'is_replyto' => false,
        'created_at' => '2026-11-01T12:00:00Z'];
    $this->reply = ['data' => [$this->entry]];
    $this->companyStatus = 200;
    $this->entriesStatus = 200;
    $this->responses = fn () => [GetCompanyMeRequest::class => MockResponse::make($this->remote, $this->companyStatus),
        ListDirectoryEntriesRequest::class => MockResponse::make($this->reply, $this->entriesStatus)];
    $this->service = app(TricatelPublicationVerification::class);
    $this->verify = fn () => $this->service->verify($this->actor, $this->company, $this->connection, responses: ($this->responses)());
    $this->requests = [];
    $requests = &$this->requests;
    Config::globalMiddleware()->onRequest(static function (PendingRequest $request) use (&$requests) {
        $requests[] = ['class' => $request->getRequest()::class, 'method' => $request->getMethod()->value,
            'url' => $request->getUrl(), 'query' => $request->query()->all(),
            'token_matches' => $request->headers()->get('Authorization') === 'Bearer TRICATEL-ACCESS-SECRET'];
    });
});

afterEach(function () {
    Model::setConnectionResolver($this->previousResolver);
    Carbon::setTestNow();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Config::preventStrayRequests();
    MockClient::destroyGlobal();
    Http::assertNothingSent();
});

it('uses only the two owned GETs and archives a nonqualifying simulated publication', function () {
    $before = [$this->connection->getAttributes(), $this->company->getAttributes(), config('superpdp_emission')];
    $report = ($this->verify)();
    expect($report['status'])->toBe('simulated')->and($report['publication_confirmed'])->toBeFalse()
        ->and($report['routing_from_burger_queen_verified'])->toBeFalse()->and($report['emission_ready'])->toBeFalse()
        ->and($report['published_entry']['identifier'])->toBe('0225:315143296_18171')
        ->and($report['observations']['company']['source'])->toBe('simulated')
        ->and($report['observations']['entries']['source'])->toBe('simulated')
        ->and(array_column($this->requests, 'class'))->toBe([GetCompanyMeRequest::class, ListDirectoryEntriesRequest::class])
        ->and(array_column($this->requests, 'method'))->toBe(['GET', 'GET'])
        ->and(array_column($this->requests, 'token_matches'))->toBe([true, true])
        ->and([$this->connection->getAttributes(), $this->company->getAttributes(), config('superpdp_emission')])->toBe($before);
    $stored = $this->service->read($this->actor, $this->company, $this->connection, $report['audit_reference']);
    expect($stored['source_sha256'])->toBe($report['source_sha256']);
    $files = Storage::disk('invoice_cii')->allFiles();
    expect($files)->toHaveCount(1);
    $json = Storage::disk('invoice_cii')->get($files[0]);
    expect($json)->not->toContain('TRICATEL-ACCESS-SECRET', 'TRICATEL-REFRESH-SECRET', 'Authorization', 'access_token', 'refresh_token');
});

it('rejects incorrect remote identities before reading entries', function (string $field, mixed $value) {
    $this->remote[$field] = $value;
    $report = ($this->verify)();
    expect($report['status'])->toBe('refused')->and($report['reason'])->toBe('tricatel_identity_mismatch')
        ->and($report['publication_confirmed'])->toBeFalse()->and($this->requests)->toHaveCount(1);
})->with([
    ['id', 18172], ['number', '000000002'], ['number', 1], ['number_scheme', 'fr_siren'],
    ['env', 'production'], ['formal_name', 'Burger Queen'], ['country', 'BE'],
]);

it('distinguishes absent inactive technical ambiguous and foreign entries', function (string $case, string $status) {
    $this->reply = match ($case) {
        'absent' => ['data' => []],
        'suffix' => ['data' => [array_replace($this->entry, ['identifier' => '0225:315143296_18172'])]],
        'technical' => ['data' => [array_replace($this->entry, ['identifier' => '0225:315143296_18171_REPLYTO'])]],
        'flagged' => ['data' => [array_replace($this->entry, ['is_replyto' => true])]],
        'missing_flag' => ['data' => [array_diff_key($this->entry, ['is_replyto' => true])]],
        'duplicate' => ['data' => [$this->entry, $this->entry]],
        'foreign' => ['data' => [array_replace_recursive($this->entry, ['company' => ['number' => '000000002']])]],
        'pending' => ['data' => [array_replace($this->entry, ['status' => 'pending'])]],
        'error' => ['data' => [array_replace($this->entry, ['status' => 'error'])]],
        'ppf' => ['data' => [array_replace($this->entry, ['directory' => 'ppf'])]],
        'partial' => ['data' => [$this->entry], 'has_more' => true],
        'object' => '{"data":{}}',
    };
    $report = ($this->verify)();
    expect($report['status'])->toBe($status)->and($report['publication_confirmed'])->toBeFalse()
        ->and($report['routing_from_burger_queen_verified'])->toBeFalse();
})->with([
    ['absent', 'absent'], ['suffix', 'absent'], ['technical', 'absent'], ['flagged', 'absent'],
    ['missing_flag', 'unavailable'], ['duplicate', 'ambiguous'], ['foreign', 'incompatible'],
    ['pending', 'inactive'], ['error', 'inactive'], ['ppf', 'incompatible'], ['partial', 'ambiguous'], ['object', 'ambiguous'],
]);

it('ignores an unrelated owned technical address without promoting it', function () {
    $this->reply['data'][] = array_replace($this->entry, ['identifier' => '0225:315143296_18171_replyto', 'is_replyto' => true]);
    $report = ($this->verify)();
    expect($report['status'])->toBe('simulated')->and($report['matched_entry_count'])->toBe(1);
});

it('qualifies only the exact unique created Peppol sandbox destination from the list contract', function ($case) {
    $row = $this->entry;
    unset($row['is_replyto']);
    match ($case) {
        'false' => $row['is_replyto'] = false,
        'true' => $row['is_replyto'] = true,
        'null' => $row['is_replyto'] = null,
        'string' => $row['is_replyto'] = 'false',
        'integer' => $row['is_replyto'] = 0,
        'identity' => $row['company']['id'] = 18172,
        'name' => $row['company']['formal_name'] = 'Other',
        'number' => $row['company']['number'] = '000000002',
        'environment' => $row['company']['env'] = 'production',
        'address' => $row['identifier'] = '0225:315143296_18172',
        'technical' => $row['identifier'] .= '_replyto',
        'directory' => $row['directory'] = 'ppf',
        'pending' => $row['status'] = 'pending',
        'error' => $row['status'] = 'error',
        default => null,
    };
    $reply = ['data' => [$row]];
    if ($case === 'duplicate') {
        $reply['data'][] = array_replace($row, ['id' => 123]);
    }
    if ($case === 'envelope') {
        $reply['extra'] = [];
    }
    $before = $reply;
    $result = $this->service->assessSandboxDestination($reply);
    expect($reply)->toBe($before);
    if (in_array($case, ['absent', 'false'], true)) {
        expect($result['status'])->toBe('matched')->and($result['qualification_documentaire_sandbox'])->toBe('passed')
            ->and($result['basis'])->toBe('directory_list_contract')
            ->and($result['replyto_classification'])->toBe($case === 'absent' ? 'undetermined' : 'non_replyto')
            ->and($result['delivery_verified'])->toBeFalse()->and($result['ready_for_emission'])->toBeFalse();
        if ($case === 'absent') {
            expect($result['published_entry'])->not->toHaveKey('is_replyto');
        } else {
            expect($result['published_entry']['is_replyto'])->toBeFalse();
        }
    } else {
        expect($result['status'])->not->toBe('matched')->and($result)->not->toHaveKey('qualification_documentaire_sandbox');
    }
})->with(['absent', 'false', 'true', 'null', 'string', 'integer', 'identity', 'name', 'number', 'environment',
    'address', 'technical', 'directory', 'pending', 'error', 'duplicate', 'envelope']);

it('distinguishes omitted null and invalid replyto fields without treating them as receiving addresses', function (string $case, bool $present, string $type) {
    $row = $this->entry;
    if ($case === 'absent') {
        unset($row['is_replyto']);
    } else {
        $row['is_replyto'] = match ($case) {
            'null' => null, 'string_false' => 'false', 'string_true' => 'true',
            'zero' => 0, 'one' => 1, 'array' => [], 'object' => (object) [],
        };
    }
    $this->reply = ['data' => [$row]];
    $report = ($this->verify)();
    expect($report['status'])->toBe('unavailable')->and($report['reason'])->toBe('replyto_classification_missing')
        ->and($report['classification_diagnostic'])->toBe([
            'entry_id' => 98765, 'identifier' => '0225:315143296_18171',
            'registration_status' => 'created',
            'field' => 'is_replyto', 'field_present' => $present, 'value_type' => $type, 'classification' => 'undetermined',
        ])
        ->and($report['publication_confirmed'])->toBeFalse()->and($report['routing_from_burger_queen_verified'])->toBeFalse()
        ->and($report['emission_ready'])->toBeFalse()->and($report)->not->toHaveKey('published_entry');
    $stored = $this->service->read($this->actor, $this->company, $this->connection, $report['audit_reference']);
    expect($stored['classification_diagnostic'])->toBe($report['classification_diagnostic']);
})->with([
    ['absent', false, 'absent'], ['null', true, 'null'],
    ['string_false', true, 'string'], ['string_true', true, 'string'],
    ['zero', true, 'int'], ['one', true, 'int'], ['array', true, 'array'], ['object', true, 'array'],
]);

it('requires a strict false boolean for the exact ordinary address and excludes explicit technical entries', function () {
    $this->reply = ['data' => [array_replace($this->entry, ['is_replyto' => true])]];
    $technical = ($this->verify)();
    expect($technical['publication_confirmed'])->toBeFalse()->and($technical['status'])->toBe('absent');
    $this->reply = ['data' => [array_replace($this->entry, ['is_replyto' => false])]];
    $ordinary = ($this->verify)();
    expect($ordinary['status'])->toBe('simulated')->and($ordinary['published_entry']['is_replyto'])->toBeFalse()
        ->and($ordinary['publication_confirmed'])->toBeFalse()->and($ordinary['routing_from_burger_queen_verified'])->toBeFalse();
});

it('archives API errors without retries or token updates', function (string $operation, int $status) {
    if ($operation === 'company') {
        $this->companyStatus = $status;
    } else {
        $this->entriesStatus = $status;
    }
    $before = $this->connection->getAttributes();
    $report = ($this->verify)();
    expect($report['publication_confirmed'])->toBeFalse()->and($report['reason'])->toBe('api_http_'.$status)
        ->and($this->requests)->toHaveCount($operation === 'company' ? 1 : 2)
        ->and($report['observations'][$operation]['request']['http_status'])->toBe($status)
        ->and($this->connection->getAttributes())->toBe($before);
})->with([['company', 401], ['company', 503], ['entries', 403], ['entries', 500]]);

it('refuses crossed local access and environment before network or archival', function (string $change) {
    match ($change) {
        'actor' => $this->actingAs((new User)->forceFill(['id' => 3])),
        'owner' => $this->company->user_id = 3,
        'company' => $this->company->id = 3,
        'connection' => $this->connection->company_id = 3,
        'actor_database' => $this->actor->setConnection('mysql'),
        'company_database' => $this->company->setConnection('mysql'),
        'production' => $this->app->instance('env', 'production'),
        'database' => config(['database.default' => 'mysql']),
        'api' => config(['services.superpdp.api_url' => 'https://example.invalid']),
    };
    expect(fn () => ($this->verify)())->toThrow(RuntimeException::class)
        ->and($this->requests)->toBe([])->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
})->with(['actor', 'owner', 'company', 'connection', 'actor_database', 'company_database', 'production', 'database', 'api']);

it('never refreshes expired or wrongly scoped credentials', function (string $change) {
    match ($change) {
        'expiry' => $this->connection->access_token_expires_at = now()->addMinutes(4),
        'client' => $this->connection->oauth_client_id = 'other-client',
        'identity' => $this->connection->directory_identifier = '000000002',
        'token' => $this->connection->setRawAttributes(array_replace($this->connection->getAttributes(), ['access_token' => 'not-decryptable'])),
        'status' => $this->connection->status = 'disconnected',
    };
    $before = $this->connection->getAttributes();
    $report = ($this->verify)();
    expect($report['status'])->toBe('unavailable')->and($this->requests)->toBe([])
        ->and($this->connection->getAttributes())->toBe($before);
})->with(['expiry', 'client', 'identity', 'token', 'status']);

it('requires an explicit single-use authorization and rejects foreign operations', function () {
    expect(fn () => new TricatelPublicationGateway($this->actor, $this->company, $this->connection))->toThrow(RuntimeException::class);
    expect(fn () => new TricatelPublicationGateway($this->actor, $this->company, $this->connection, true, ($this->responses)()))->toThrow(RuntimeException::class);
    expect(fn () => new TricatelPublicationGateway($this->actor, $this->company, $this->connection,
        responses: [CreateInvoiceRequest::class => MockResponse::make([])]))->toThrow(RuntimeException::class);
    $gateway = new TricatelPublicationGateway($this->actor, $this->company, $this->connection, responses: ($this->responses)());
    $gateway->company();
    expect(fn () => $gateway->company())->toThrow(RuntimeException::class);
    $gateway->entries();
    expect(fn () => $gateway->entries())->toThrow(RuntimeException::class);
    expect($this->requests)->toHaveCount(2);
});

it('expires a private report on local read without network', function () {
    $report = ($this->verify)();
    $this->travel(15)->minutes();
    $read = $this->service->read($this->actor, $this->company, $this->connection, $report['audit_reference']);
    expect($read['status'])->toBe('expired')->and($read['publication_confirmed'])->toBeFalse()
        ->and($this->requests)->toHaveCount(2);
});

it('refuses tampered archives and cross-owner private reads', function () {
    $report = ($this->verify)();
    $this->actingAs((new User)->forceFill(['id' => 3]));
    expect(fn () => $this->service->read($this->actor, $this->company, $this->connection, $report['audit_reference']))->toThrow(RuntimeException::class);
    $this->actingAs($this->actor);
    Storage::disk('invoice_cii')->put($report['audit_reference'], '{}');
    expect(fn () => $this->service->read($this->actor, $this->company, $this->connection, $report['audit_reference']))->toThrow(RuntimeException::class);
});

it('does nothing by default at the CLI and rejects execution outside sandbox', function () {
    DB::shouldReceive('connection')->never();
    $this->artisan('superpdp:verify-tricatel-publication')->assertExitCode(0);
    $this->app->instance('env', 'production');
    $this->artisan('superpdp:verify-tricatel-publication', ['--execute' => true])->assertExitCode(1);
    expect($this->requests)->toBe([])->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});

it('revalidates archived evidence without ever treating a publication report as routing proof', function (string $field, mixed $value) {
    // Fixture de rapport représentant une observation live archivée, sans appel réseau.
    // Le parcours MockResponse ci-dessus conserve toujours source=simulated.
    $report = ($this->verify)();
    unset($report['audit_reference']);
    $report['status'] = 'confirmed';
    $report['publication_confirmed'] = true;
    $report['observations']['company']['source'] = 'live';
    $report['observations']['entries']['source'] = 'live';
    data_set($report, $field, $value);
    $reference = app(VerificationAuditStore::class)->storeCompanyPublication($this->actor, $this->company, $report);
    $read = $this->service->read($this->actor, $this->company, $this->connection, $reference);
    expect($read['publication_confirmed'])->toBeFalse()->and($read['routing_from_burger_queen_verified'])->toBeFalse()
        ->and($read['emission_ready'])->toBeFalse();
})->with([
    ['observations.company.source', 'simulated'], ['observations.entries.source', 'cached'],
    ['observations.entries.request.endpoint', 'https://example.invalid'],
    ['observations.entries.request.http_status', 503], ['observations.company.response_sha256', 'invalid'],
    ['published_entry.identifier', '0225:315143296_18172'], ['matched_entry_count', 2],
    ['published_entry.is_replyto', null], ['published_entry.is_replyto', 'false'], ['published_entry.is_replyto', true],
    ['observations.entries.observed_at', '2099-01-01T00:00:00+00:00'], ['identity.env', 'production'],
]);

it('allows a fresh archived live publication fixture while keeping routing and emission false', function () {
    $report = ($this->verify)();
    unset($report['audit_reference']);
    $report['status'] = 'confirmed';
    $report['publication_confirmed'] = true;
    $report['observations']['company']['source'] = 'live';
    $report['observations']['entries']['source'] = 'live';
    $reference = app(VerificationAuditStore::class)->storeCompanyPublication($this->actor, $this->company, $report);
    $read = $this->service->read($this->actor, $this->company, $this->connection, $reference);
    expect($read['publication_confirmed'])->toBeTrue()->and($read['routing_from_burger_queen_verified'])->toBeFalse();
    $this->travel(15)->minutes();
    expect($this->service->read($this->actor, $this->company, $this->connection, $reference)['status'])->toBe('expired');
    config(['superpdp_verification.publication_validity_minutes' => 20]);
    expect($this->service->read($this->actor, $this->company, $this->connection, $reference)['publication_confirmed'])->toBeTrue();
});

it('rejects malformed or duplicate-key responses without exposing their body', function (string $body) {
    $responses = ($this->responses)();
    $responses[ListDirectoryEntriesRequest::class] = MockResponse::make($body);
    $report = $this->service->verify($this->actor, $this->company, $this->connection, responses: $responses);
    expect($report['publication_confirmed'])->toBeFalse()->and($report['status'])->toBe('unavailable')
        ->and($this->requests)->toHaveCount(2);
    $json = Storage::disk('invoice_cii')->get($report['audit_reference']);
    expect($json)->not->toContain('DO-NOT-ARCHIVE');
})->with(['DO-NOT-ARCHIVE', '{"data":[],"data":[],"secret":"DO-NOT-ARCHIVE"}']);

it('prevents a report from asserting Burger Queen routing or emission readiness', function () {
    $report = ($this->verify)();
    unset($report['audit_reference']);
    $report['routing_from_burger_queen_verified'] = true;
    expect(fn () => app(VerificationAuditStore::class)->storeCompanyPublication($this->actor, $this->company, $report))->toThrow(RuntimeException::class);
});

it('identifies the exact read-only OAuth prerequisite without secrets or side effects', function (string $change, string $reason) {
    match ($change) {
        'expired' => $this->connection->access_token_expires_at = now()->subSecond(),
        'now' => $this->connection->access_token_expires_at = now(),
        'near' => $this->connection->access_token_expires_at = now()->addMinutes(4),
        'boundary' => $this->connection->access_token_expires_at = now()->addMinutes(5),
        'missing_expiry' => $this->connection->access_token_expires_at = null,
        'client' => $this->connection->oauth_client_id = 'other-client',
        'api' => $this->connection->api_url = 'https://example.invalid',
        'environment' => $this->connection->environment = 'production',
        'status' => $this->connection->status = 'disconnected',
        'identity' => $this->connection->directory_identifier = '000000002',
        'token' => $this->connection->setRawAttributes(array_replace($this->connection->getAttributes(), ['access_token' => 'DO-NOT-DISCLOSE'])),
        'missing_token' => $this->connection->access_token = null,
        'missing_client_config' => config(['services.superpdp.client_id' => null]),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
    };
    $before = $this->connection->getAttributes();
    $result = $this->service->diagnose($this->actor, $this->company, $this->connection);
    expect($result['status'])->toBe('unavailable')->and($result['reason'])->toBe($reason)
        ->and($result['api_called'])->toBeFalse()->and($result['report_saved'])->toBeFalse()
        ->and($result['token_refreshed'])->toBeFalse()->and($this->requests)->toBe([])
        ->and($this->connection->getAttributes())->toBe($before)
        ->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
    expect(json_encode($result))->not->toContain('TRICATEL-ACCESS-SECRET', 'TRICATEL-REFRESH-SECRET', 'DO-NOT-DISCLOSE', 'other-client');
})->with([
    ['expired', 'access_token_expired'], ['now', 'access_token_expired'],
    ['near', 'access_token_expiring_soon'], ['boundary', 'access_token_expiring_soon'],
    ['missing_expiry', 'access_token_expiry_missing'], ['client', 'oauth_connection_context_mismatch'],
    ['api', 'oauth_connection_context_mismatch'], ['environment', 'oauth_connection_context_mismatch'],
    ['status', 'oauth_connection_not_connected'], ['identity', 'oauth_directory_identity_mismatch'],
    ['token', 'access_token_unreadable'], ['missing_token', 'access_token_missing'],
    ['missing_client_config', 'oauth_client_id_not_configured'], ['binding', 'oauth_identity_context_invalid'],
]);

it('keeps precise prerequisite failures in a verification report before any HTTP request', function () {
    $this->connection->access_token_expires_at = now()->subHour();
    $report = ($this->verify)();
    expect($report['reason'])->toBe('access_token_expired')->and($report['observations'])->toBe([])
        ->and($report['publication_confirmed'])->toBeFalse()
        ->and($report['prerequisite_diagnostic']['safety_margin_seconds'])->toBe(300)
        ->and($this->requests)->toBe([]);
});

it('reports passing prerequisites without consulting publication or saving a report', function () {
    $result = $this->service->diagnose($this->actor, $this->company, $this->connection);
    expect($result['status'])->toBe('prerequisites_passed')->and($result['publication_confirmed'])->toBeFalse()
        ->and($result['routing_from_burger_queen_verified'])->toBeFalse()
        ->and($this->requests)->toBe([])->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});

it('refuses simultaneous diagnose and execute without any SQL or HTTP operation', function () {
    DB::shouldReceive('connection')->never();
    $this->artisan('superpdp:verify-tricatel-publication', ['--diagnose' => true, '--execute' => true])->assertExitCode(1);
    expect($this->requests)->toBe([])->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});

it('reports database failure with only a safe stage and numeric codes', function () {
    $exception = new PDOException('DO-NOT-DISCLOSE sql host password');
    $exception->errorInfo = ['HY000', 2002, 'DO-NOT-DISCLOSE'];
    DB::shouldReceive('connection')->once()->with('superpdp_sandbox')->andThrow($exception);
    $status = Artisan::call('superpdp:verify-tricatel-publication', ['--diagnose' => true]);
    $output = Artisan::output();
    expect($status)->toBe(1)->and($output)->toContain('sandbox_read_only_transaction', 'HY000', '2002')
        ->not->toContain('DO-NOT-DISCLOSE', 'password')->and($this->requests)->toBe([]);
});

it('runs the CLI diagnostic using only SELECTs and a read-only transaction without any report', function () {
    $this->connection->access_token_expires_at = now()->subMinute();
    $memory = new class($this->actor->getAttributes(), $this->company->getAttributes(), $this->connection->getAttributes()) extends Connection
    {
        public array $operations = [];

        public function __construct(private array $actorRow, private array $companyRow, private array $connectionRow)
        {
            parent::__construct(null);
        }

        public function statement($query, $bindings = [])
        {
            expect($query)->toBe('SET TRANSACTION READ ONLY');
            $this->operations[] = $query;

            return true;
        }

        public function beginTransaction()
        {
            $this->operations[] = 'BEGIN READ ONLY';
        }

        public function rollBack($toLevel = null)
        {
            $this->operations[] = 'ROLLBACK';
        }

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            expect(strtolower(ltrim($query)))->toStartWith('select');
            $this->operations[] = $query;
            if (str_contains($query, 'DATABASE()')) {
                return [(object) ['selected_database' => 'fabyjulien_simpledevis_test']];
            }
            foreach (['users' => $this->actorRow, 'companies' => $this->companyRow, 'superpdp_connections' => $this->connectionRow] as $table => $row) {
                if (str_contains($query, 'from "'.$table.'"')) {
                    return [(object) $row];
                }
            }
            throw new RuntimeException('Unexpected offline query.');
        }
    };
    $resolver = new ConnectionResolver(['superpdp_sandbox' => $memory]);
    $resolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($resolver);
    DB::shouldReceive('connection')->once()->with('superpdp_sandbox')->andReturn($memory);
    $status = Artisan::call('superpdp:verify-tricatel-publication', ['--diagnose' => true]);
    expect($status)->toBe(1)->and(Artisan::output())->toContain('access_token_expired')
        ->and($memory->operations)->toContain('SET TRANSACTION READ ONLY', 'ROLLBACK')
        ->and($this->requests)->toBe([])->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
});
