<?php

use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\SandboxOAuthTokenRefresh;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemorySuperPdpOAuthDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-13T20:00:00Z'));
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new InMemorySuperPdpOAuthDatabase;
    $resolver = new ConnectionResolver(['superpdp_sandbox' => $this->memory]);
    $resolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($resolver);
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.token_url' => 'https://api.superpdp.tech/oauth2/token',
        'services.superpdp.client_id' => 'unit-client', 'services.superpdp.client_secret' => 'unit-secret',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test', 'username' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->user = (new User)->forceFill(['id' => 3]);
    $this->user->exists = true;
    $this->actingAs($this->user);
    $this->memory->rows['users'][3] = ['id' => 3];
    $this->memory->rows['companies'][3] = ['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null];
    $connection = (new SuperPdpConnection)->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'status' => 'connected',
        'directory_identifier' => '000000002', 'environment' => 'sandbox',
        'oauth_client_id' => 'unit-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'access_token' => 'unit-old-access', 'refresh_token' => 'unit-old-refresh',
        'access_token_expires_at' => now()->subHour(), 'reception_enabled' => true,
    ]);
    $this->memory->rows['superpdp_connections'][1] = $connection->getAttributes();
    $this->memory->rows['superpdp_connections'][2] = ['id' => 2, 'company_id' => 4, 'environment' => 'production'];
    DB::shouldReceive('connection')->with('superpdp_sandbox')->andReturn($this->memory);
    Http::swap(new class extends Factory
    {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('External HTTP forbidden.'));
        }
    });
    Http::preventStrayRequests();
    $this->tokens = ['access_token' => 'unit-new-access', 'refresh_token' => 'unit-new-refresh',
        'expires_in' => 3600, 'token_type' => 'Bearer', 'company_id' => 18172];
    $this->remote = ['id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002', 'country' => 'FR', 'env' => 'sandbox'];
    $this->fake = function (int $tokenStatus = 200) {
        Http::fake([
            'https://api.superpdp.tech/oauth2/token' => Http::response($tokenStatus === 200 ? $this->tokens : ['error' => 'invalid_grant'], $tokenStatus),
            'https://api.superpdp.tech/v1.beta/companies/me' => Http::response($this->remote),
        ]);
    };
    $this->run = fn () => app(SandboxOAuthTokenRefresh::class)->run($this->user);
    $this->selectTricatel = function () {
        $this->burgerQueenBefore = $this->memory->rows['superpdp_connections'][1];
        $this->user = (new User)->forceFill(['id' => 4]);
        $this->user->exists = true;
        $this->actingAs($this->user);
        $this->memory->rows['users'][4] = ['id' => 4];
        $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null];
        $connection = (new SuperPdpConnection)->forceFill([
            'id' => 2, 'company_id' => 4, 'superpdp_company_id' => 18171, 'status' => 'connected',
            'directory_identifier' => '000000001', 'environment' => 'sandbox',
            'oauth_client_id' => 'unit-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'access_token' => 'tricatel-old-access', 'refresh_token' => 'tricatel-old-refresh',
            'access_token_expires_at' => now()->subHour(), 'reception_enabled' => true,
        ]);
        $this->memory->rows['superpdp_connections'][2] = $connection->getAttributes();
        $this->tokens = ['access_token' => 'tricatel-new-access', 'refresh_token' => 'tricatel-new-refresh',
            'expires_in' => 3600, 'token_type' => 'Bearer', 'company_id' => 18171];
        $this->remote = ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001', 'country' => 'FR', 'env' => 'sandbox'];
        $this->run = fn () => app(SandboxOAuthTokenRefresh::class)->run($this->user, 2);
    };
});

afterEach(function () {
    if (isset($this->burgerQueenBefore)) {
        expect($this->memory->rows['superpdp_connections'][1])->toBe($this->burgerQueenBefore);
        expect(config('services.superpdp.sandbox_identity'))->toBe([
            'company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002',
        ]);
    }
    Model::setConnectionResolver($this->originalResolver);
    Carbon::setTestNow();
});

it('reuses the OAuth rotation and identity checks and persists only encrypted sandbox token fields', function () {
    ($this->fake)();
    $before = $this->memory->rows;
    $result = ($this->run)();
    expect($result['status'])->toBe('refreshed')->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['token_valid_for_documentary_test'])->toBeTrue()
        ->and($this->memory->rows['companies'])->toBe($before['companies'])
        ->and($this->memory->rows['users'])->toBe($before['users'])
        ->and($this->memory->rows['superpdp_connections'][2])->toBe($before['superpdp_connections'][2])
        ->and($this->memory->transactionAttempts)->toBe([1, 1]);
    $after = $this->memory->rows['superpdp_connections'][1];
    expect($after['access_token'])->not->toBe('unit-new-access')
        ->and(Crypt::decryptString($after['access_token']))->toBe('unit-new-access')
        ->and(Crypt::decryptString($after['refresh_token']))->toBe('unit-new-refresh');
    foreach ($before['superpdp_connections'][1] as $field => $value) {
        if (! in_array($field, ['access_token', 'refresh_token', 'access_token_expires_at'], true)) {
            expect($after[$field])->toBe($value);
        }
    }
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.superpdp.tech/oauth2/token'
        && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'unit-old-refresh');
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.superpdp.tech/v1.beta/companies/me');
    expect(json_encode($result))->not->toContain('unit-new-access', 'unit-new-refresh', 'unit-old-refresh');
});

it('refuses expired revoked or uncertain refresh tokens once and commits the blocked status instead of enabling replay', function (int $status) {
    ($this->fake)($status);
    $before = $this->memory->rows['superpdp_connections'][1];
    expect(($this->run)()['status'])->toBe('refused_or_uncertain')
        ->and($this->memory->rows['superpdp_connections'][1]['status'])->toBe('error')
        ->and($this->memory->rows['superpdp_connections'][1]['access_token'])->toBe($before['access_token'])
        ->and($this->memory->rows['superpdp_connections'][1]['refresh_token'])->toBe($before['refresh_token']);
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([400, 401, 500]);

it('rejects changed remote identities before replacing either token', function (string $field, mixed $value) {
    $this->remote[$field] = $value;
    ($this->fake)();
    $before = $this->memory->rows['superpdp_connections'][1];
    expect(($this->run)()['status'])->toBe('refused_or_uncertain')
        ->and($this->memory->rows['superpdp_connections'][1]['access_token'])->toBe($before['access_token'])
        ->and($this->memory->rows['superpdp_connections'][1]['refresh_token'])->toBe($before['refresh_token']);
    Http::assertSentCount(2);
})->with([['id', 18171], ['number', '000000001'], ['env', 'production'], ['number_scheme', 'fr_siren']]);

it('rejects local ownership environment company and client mismatches without HTTP', function (string $case) {
    match ($case) {
        'production' => $this->app->instance('env', 'production'),
        'provider' => config(['services.superpdp.environment' => 'production']),
        'database' => config(['database.connections.superpdp_sandbox.database' => 'main']),
        'owner' => $this->memory->rows['companies'][3]['user_id'] = 4,
        'company' => $this->memory->rows['superpdp_connections'][1]['company_id'] = 4,
        'client' => $this->memory->rows['superpdp_connections'][1]['oauth_client_id'] = 'other',
        'connection_production' => $this->memory->rows['superpdp_connections'][1]['environment'] = 'production',
        'provider_id' => $this->memory->rows['superpdp_connections'][1]['superpdp_company_id'] = 18171,
        'identity' => $this->memory->rows['superpdp_connections'][1]['directory_identifier'] = '000000001',
        'token_url' => config(['services.superpdp.token_url' => 'https://example.invalid/token']),
    };
    expect(fn () => ($this->run)())->toThrow(Exception::class);
    Http::assertNothingSent();
})->with(['production', 'provider', 'database', 'owner', 'company', 'client', 'connection_production', 'provider_id', 'identity', 'token_url']);

it('does not refresh an already usable access token', function () {
    $this->memory->rows['superpdp_connections'][1]['access_token_expires_at'] = now()->addHour()->format('Y-m-d H:i:s');
    $before = $this->memory->rows;
    expect(($this->run)()['status'])->toBe('already_valid')->and($this->memory->rows)->toBe($before);
    Http::assertNothingSent();
});

it('requires explicit CLI execution and prints no credentials on success or refusal', function () {
    $this->artisan('superpdp:sandbox-refresh-oauth')->assertExitCode(0);
    Http::assertNothingSent();
    ($this->fake)();
    $this->artisan('superpdp:sandbox-refresh-oauth', ['--execute' => true])->assertExitCode(0);
    Http::assertSentCount(2);
});

it('targets Tricatel explicitly and rotates only connection 2 with the existing service', function () {
    ($this->selectTricatel)();
    ($this->fake)();
    $before = $this->memory->rows;
    $result = ($this->run)();
    expect($result)->toMatchArray(['status' => 'refreshed', 'connection_id' => 2, 'local_company_id' => 4,
        'environment' => 'sandbox', 'token_valid_for_documentary_test' => true, 'ready_for_emission' => false]);
    $after = $this->memory->rows['superpdp_connections'][2];
    expect(Crypt::decryptString($after['access_token']))->toBe('tricatel-new-access')
        ->and(Crypt::decryptString($after['refresh_token']))->toBe('tricatel-new-refresh')
        ->and($this->memory->rows['users'])->toBe($before['users'])
        ->and($this->memory->rows['companies'])->toBe($before['companies'])
        ->and($this->memory->transactionAttempts)->toBe([1, 1]);
    foreach ($before['superpdp_connections'][2] as $field => $value) {
        if (! in_array($field, ['access_token', 'refresh_token', 'access_token_expires_at'], true)) {
            expect($after[$field])->toBe($value);
        }
    }
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.superpdp.tech/oauth2/token'
        && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'tricatel-old-refresh');
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.superpdp.tech/v1.beta/companies/me'
        && $r->hasHeader('Authorization', 'Bearer tricatel-new-access'));
});

it('requires explicit execution for the Tricatel command and restores the previous actor', function () {
    ($this->selectTricatel)();
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->artisan('superpdp:sandbox-refresh-oauth', ['--connection' => '2'])->assertSuccessful();
    Http::assertNothingSent();
    ($this->fake)();
    $this->artisan('superpdp:sandbox-refresh-oauth', ['--connection' => '2', '--execute' => true])->assertSuccessful();
    expect(auth()->id())->toBe(3);
    Http::assertSentCount(2);
});

it('refuses invalid Tricatel local bindings before HTTP', function (string $case) {
    ($this->selectTricatel)();
    match ($case) {
        'owner' => $this->memory->rows['companies'][4]['user_id'] = 3,
        'name' => $this->memory->rows['companies'][4]['company_name'] = 'Burger Queen',
        'connection' => $this->memory->rows['superpdp_connections'][2]['company_id'] = 3,
        'provider' => $this->memory->rows['superpdp_connections'][2]['superpdp_company_id'] = 18172,
        'identity' => $this->memory->rows['superpdp_connections'][2]['directory_identifier'] = '000000002',
        'environment' => $this->memory->rows['superpdp_connections'][2]['environment'] = 'production',
        'actor' => $this->actingAs((new User)->forceFill(['id' => 3])),
    };
    expect(fn () => ($this->run)())->toThrow(Exception::class);
    Http::assertNothingSent();
})->with(['owner', 'name', 'connection', 'provider', 'identity', 'environment', 'actor']);

it('blocks only Tricatel after a refused refresh or a wrong returned identity', function (string $case) {
    ($this->selectTricatel)();
    if ($case === 'wrong_identity') {
        $this->remote['id'] = 18172;
    }
    ($this->fake)($case === 'invalid_grant' ? 400 : 200);
    expect(($this->run)()['status'])->toBe('refused_or_uncertain')
        ->and($this->memory->rows['superpdp_connections'][2]['status'])->toBe('error');
    expect(fn () => ($this->run)())->toThrow(RuntimeException::class);
    Http::assertSentCount($case === 'invalid_grant' ? 1 : 2);
})->with(['invalid_grant', 'wrong_identity']);

it('makes no HTTP call if Tricatel is already valid', function () {
    ($this->selectTricatel)();
    $this->memory->rows['superpdp_connections'][2]['access_token_expires_at'] = now()->addHour()->format('Y-m-d H:i:s');
    $before = $this->memory->rows;
    expect(($this->run)()['status'])->toBe('already_valid')->and($this->memory->rows)->toBe($before);
    Http::assertNothingSent();
});

it('rejects unsupported connection selection before any SQL or HTTP operation', function () {
    $this->artisan('superpdp:sandbox-refresh-oauth', ['--connection' => '3', '--execute' => true])->assertFailed();
    expect($this->memory->transactionAttempts)->toBe([]);
    Http::assertNothingSent();
});
