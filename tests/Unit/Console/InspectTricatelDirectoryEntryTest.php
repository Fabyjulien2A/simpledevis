<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\SuperPdpOAuthService;
use App\Services\SuperPdp\Verification\TricatelDirectoryEntryDiagnostic;
use Bluerock\SuperPdp\Requests\DirectoryEntries\GetDirectoryEntryRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    DB::shouldReceive('connection')->never();
    $this->sdk = MockClient::global([]);
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['database' => 'fabyjulien_simpledevis_test']]]);
    $this->actor = (new User)->forceFill(['id' => 4]);
    $this->actor->exists = true;
    $this->actingAs($this->actor);
    $this->company = (new Company)->forceFill(['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel']);
    $this->company->exists = true;
    $this->connection = (new SuperPdpConnection)->forceFill(['id' => 2, 'company_id' => 4,
        'superpdp_company_id' => 18171, 'directory_identifier' => '000000001']);
    $this->connection->exists = true;
    $this->oauth = $this->mock(SuperPdpOAuthService::class);
    $this->oauth->shouldNotReceive('getValidAccessToken');
    $this->row = ['id' => 59580, 'identifier' => '0225:315143296_18171', 'directory' => 'peppol', 'status' => 'created',
        'company' => ['id' => 18171, 'env' => 'sandbox', 'number_scheme' => 'sandbox', 'number' => '000000001',
            'formal_name' => 'Tricatel', 'country' => 'FR'],
        'secret' => 'REMOTE-SECRET'];
    $this->read = fn ($authorized = true) => app(TricatelDirectoryEntryDiagnostic::class)
        ->read($this->actor, $this->company, $this->connection, 59580, $authorized);
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
});

it('does nothing without explicit execution and rejects missing entry ID before SQL or HTTP', function () {
    $this->artisan('superpdp:inspect-tricatel-directory-entry')->assertSuccessful();
    $this->artisan('superpdp:inspect-tricatel-directory-entry', ['--execute' => true])->assertFailed();
    $this->sdk->assertNothingSent();
});

it('requires explicit authorization and exact sandbox Tricatel context before HTTP', function ($case) {
    if ($case === 'production') {
        $this->app->instance('env', 'production');
    } elseif ($case === 'connection') {
        $this->connection->id = 1;
    } elseif ($case === 'provider') {
        $this->connection->superpdp_company_id = 18172;
    }
    expect(fn () => ($this->read)($case !== 'authorization'))->toThrow(RuntimeException::class);
    $this->sdk->assertNothingSent();
})->with(['production', 'connection', 'provider', 'authorization']);

it('sends only the detail GET and whitelists output while distinguishing omission from false', function ($flag) {
    $this->oauth->shouldReceive('existingAccessTokenForReadOnly')->twice()->andReturn('OFFLINE-TOKEN');
    $row = $this->row;
    if ($flag !== 'absent') {
        $row['is_replyto'] = $flag;
    }
    $this->sdk->addResponse(MockResponse::make($row, 200));
    $output = ($this->read)();
    $expected = ['id' => 59580, 'identifier' => '0225:315143296_18171', 'directory' => 'peppol', 'status' => 'created'];
    $expected += $flag === 'absent' ? ['field_present' => false] : ['is_replyto' => $flag];
    expect($output)->toBe($expected)
        ->and(json_encode($output))->not->toContain('REMOTE-SECRET', 'OFFLINE-TOKEN');
    $this->sdk->assertSentCount(1);
    $this->sdk->assertSent(fn ($request) => $request instanceof GetDirectoryEntryRequest
        && $request->resolveEndpoint() === '/v1.beta/directory_entries/59580');
})->with(['absent', false, true]);

it('refuses unavailable OAuth without attempting HTTP or refresh', function () {
    $this->oauth->shouldReceive('existingAccessTokenForReadOnly')->once()->andThrow(new RuntimeException('expired'));
    expect(fn () => ($this->read)())->toThrow(RuntimeException::class);
    $this->sdk->assertNothingSent();
});

it('does not retry errors or follow redirects and refuses mismatched or malformed detail data', function ($case) {
    $this->oauth->shouldReceive('existingAccessTokenForReadOnly')->twice()->andReturn('OFFLINE-TOKEN');
    $row = $this->row;
    $http = 200;
    match ($case) {
        'id' => $row['id'] = 18171,
        'address' => $row['identifier'] = '0225:315143296_18172',
        'provider' => $row['company']['id'] = 18172,
        'directory' => $row['directory'] = 'ppf',
        'invalid flag' => $row['is_replyto'] = 'REMOTE-SECRET',
        'null flag' => $row['is_replyto'] = null,
        'http' => $http = 500,
        'redirect' => $http = 302,
    };
    $this->sdk->addResponse(MockResponse::make($row, $http, ['Location' => 'https://forbidden.invalid']));
    expect(fn () => ($this->read)())->toThrow(RuntimeException::class);
    $this->sdk->assertSentCount(1);
})->with(['id', 'address', 'provider', 'directory', 'invalid flag', 'null flag', 'http', 'redirect']);

it('obtains the entry ID only from exact local buyer evidence', function () {
    $report = ['kind' => 'sandbox_buyer_destination_diagnostic', 'invoice_id' => 80, 'user_id' => 3,
        'buyer_provider_company_id' => 18171,
        'checks' => ['buyer_destination' => ['assessment' => ['classification_diagnostic' => [
            'entry_id' => 59580, 'identifier' => '0225:315143296_18171']]]],
        'observations' => [['connection_id' => 2, 'method' => 'GET', 'http_status' => 200, 'source' => 'live',
            'query' => [], 'endpoint' => 'https://api.superpdp.tech/v1.beta/directory_entries']]];
    $service = app(TricatelDirectoryEntryDiagnostic::class);
    expect($service->entryId($report))->toBe(59580);
    $missing = $report;
    unset($missing['checks']['buyer_destination']['assessment']['classification_diagnostic']['entry_id']);
    expect(fn () => $service->entryId($missing))->toThrow(RuntimeException::class);
    $report['checks']['buyer_destination']['assessment']['classification_diagnostic']['identifier'] .= '_replyto';
    expect(fn () => $service->entryId($report))->toThrow(RuntimeException::class);
    $this->sdk->assertNothingSent();
});
