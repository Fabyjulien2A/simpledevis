<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemorySuperPdpOAuthDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new InMemorySuperPdpOAuthDatabase;
    $resolver = new ConnectionResolver(['superpdp_sandbox' => $this->memory]);
    $resolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($resolver);
    $this->memory->rows['companies'][3] = ['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null];
    $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null];
    $user = new User;
    $user->forceFill(['id' => 3]);
    $this->actingAs($user);
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox',
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test', 'username' => 'fabyjulien_simpledevis_test']],
        'session.driver' => 'array',
        'services.superpdp.environment' => 'sandbox',
        'services.superpdp.client_id' => 'unit-client',
        'services.superpdp.client_secret' => 'unit-secret',
        'services.superpdp.authorize_url' => 'https://provider.invalid/oauth2/authorize',
        'services.superpdp.token_url' => 'https://provider.invalid/oauth2/token',
        'services.superpdp.api_url' => 'https://provider.invalid/v1.beta',
        'services.superpdp.redirect_uri' => 'http://127.0.0.1:8000/facturation-electronique/callback',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
    ]);
    $this->resetHttp = function () {
        Http::swap(new class extends Factory
        {
            protected function newPendingRequest()
            {
                return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('Network forbidden'));
            }
        });
        Http::preventStrayRequests();
    };
    ($this->resetHttp)();
    $this->tokens = ['access_token' => 'unit-access', 'refresh_token' => 'unit-refresh', 'expires_in' => 1800, 'token_type' => 'Bearer', 'company_id' => 42];
    $this->remote = ['id' => 42, 'number_scheme' => 'sandbox', 'number' => '000000002', 'country' => 'FR', 'env' => 'sandbox'];
    $this->fake = function (array $remote = [], ?Closure $duringToken = null) {
        ($this->resetHttp)();
        Http::fake([
            'https://provider.invalid/oauth2/token' => function () use ($duringToken) {
                $duringToken?->__invoke();

                return Http::response($this->tokens);
            },
            'https://provider.invalid/v1.beta/companies/me' => Http::response(array_replace($this->remote, $remote)),
        ]);
    };
    $this->oauth = new SuperPdpOAuthService;
    $this->begin = function (int $companyId = 3) {
        $url = $this->oauth->getAuthorizationUrl(Company::findOrFail($companyId), 'owner@example.invalid');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    };
    $this->complete = function () {
        $query = ($this->begin)();
        ($this->fake)();

        return $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']);
    };
});

afterEach(function () {
    Model::setConnectionResolver($this->originalResolver);
});

it('connects the explicitly bound sandbox company without a SIRET and preserves PKCE and single-use state', function () {
    $query = ($this->begin)();
    $pending = session('superpdp_oauth');
    expect($query['superpdp_company_number_scheme'])->toBe('sandbox')
        ->and($query['superpdp_company_number'])->toBe('000000002')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='));
    ($this->fake)();
    $connection = $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']);
    expect($connection->directory_identifier)->toBe('000000002')
        ->and($connection->superpdp_company_id)->toBe(42)
        ->and($this->memory->rows['companies'][3]['siret'])->toBeNull()
        ->and(session('superpdp_oauth'))->toBeNull();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['code_verifier'] === $pending['verifier'] && $r['grant_type'] === 'authorization_code');
    expect(fn () => $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']))->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
});

it('rejects absent partial malformed or different sandbox bindings without HTTP', function (mixed $binding) {
    config(['services.superpdp.sandbox_identity' => $binding]);
    expect(fn () => ($this->begin)())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    [null], [[]], [false],
    [['company_id' => null, 'number_scheme' => null, 'number' => null]],
    [['company_id' => 3]],
    [['company_id' => 8, 'number_scheme' => 'sandbox', 'number' => '000000002']],
    [['company_id' => [], 'number_scheme' => 'sandbox', 'number' => '000000002']],
    [['company_id' => true, 'number_scheme' => 'sandbox', 'number' => '000000002']],
    [['company_id' => '07', 'number_scheme' => 'sandbox', 'number' => '000000002']],
    [['company_id' => 3, 'number_scheme' => 'fr_siren', 'number' => '000000002']],
    [['company_id' => 3, 'number_scheme' => 'sandbox', 'number' => 2]],
    [['company_id' => 3, 'number_scheme' => 'sandbox', 'number' => '000000001']],
]);

it('preserves the French SIRET flow in production', function () {
    $environment = 'production';
    $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    config(['services.superpdp.environment' => $environment]);
    $query = ($this->begin)();
    expect($query['superpdp_company_number_scheme'])->toBe('fr_siren')->and($query['superpdp_company_number'])->toBe('001234567');
    ($this->fake)(['number_scheme' => 'fr_siren', 'number' => '001234567', 'env' => $environment]);
    $connection = $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']);
    expect($connection->directory_identifier)->toBe('001234567');
});

it('never bypasses the production SIRET requirement with a sandbox binding', function (mixed $siret) {
    config(['services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][3]['siret'] = $siret;
    expect(fn () => ($this->begin)())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([null, '', '000000002', '1234567890123']);

it('rejects changed ownership state environment or identity before callback HTTP', function (string $change) {
    $query = ($this->begin)();
    match ($change) {
        'owner' => $this->memory->rows['companies'][3]['user_id'] = 9,
        'state' => $query['state'] = 'wrong',
        'expired' => session(['superpdp_oauth.created_at' => now()->subMinutes(11)->timestamp]),
        'environment' => config(['services.superpdp.environment' => 'production']),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 8]),
        'missing_identity' => session()->forget('superpdp_oauth.identity'),
    };
    expect(fn () => $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']))->toThrow(RuntimeException::class);
    expect(session('superpdp_oauth'))->toBeNull()->and($this->memory->rows['superpdp_connections'])->toBe([]);
    Http::assertNothingSent();
})->with(['owner', 'state', 'expired', 'environment', 'binding', 'missing_identity']);

it('rejects remote sandbox identity or environment mismatches without persistence', function (array $remote) {
    $query = ($this->begin)();
    ($this->fake)($remote);
    expect(fn () => $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']))->toThrow(RuntimeException::class);
    expect($this->memory->rows['superpdp_connections'])->toBe([]);
    Http::assertSentCount(2);
})->with([
    [['number_scheme' => 'fr_siren']], [['number' => '000000001']], [['number' => 2]],
    [['env' => 'production']], [['country' => 'BE']], [['id' => 43]],
]);

it('rechecks binding and ownership after the exchange before saving tokens', function (string $change) {
    $query = ($this->begin)();
    ($this->fake)([], function () use ($change) {
        if ($change === 'owner') {
            $this->memory->rows['companies'][3]['user_id'] = 9;
        } else {
            config(['services.superpdp.sandbox_identity.company_id' => 8]);
        }
    });
    expect(fn () => $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']))->toThrow(RuntimeException::class);
    expect($this->memory->rows['superpdp_connections'])->toBe([]);
})->with(['owner', 'binding']);

it('renews sandbox tokens while checking the same remote identity', function () {
    $connection = ($this->complete)();
    $connection->update(['access_token_expires_at' => now()->subMinute()]);
    ($this->fake)();
    $renewed = $this->oauth->refreshAccessToken($connection);
    expect($renewed->status)->toBe('connected')->and($renewed->directory_identifier)->toBe('000000002');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['grant_type'] === 'refresh_token');
});

it('blocks renewal on a changed identity without replacing tokens', function (string $change) {
    $connection = ($this->complete)();
    $connection->update(['access_token_expires_at' => now()->subMinute()]);
    $stored = $this->memory->rows['superpdp_connections'][$connection->id];
    ($this->fake)($change === 'remote' ? ['number' => '000000001'] : []);
    if ($change === 'binding') {
        config(['services.superpdp.sandbox_identity.company_id' => 8]);
    } elseif ($change === 'stored') {
        $this->memory->rows['superpdp_connections'][$connection->id]['directory_identifier'] = '000000001';
    }
    expect(fn () => $this->oauth->refreshAccessToken($connection))->toThrow(RuntimeException::class);
    $after = $this->memory->rows['superpdp_connections'][$connection->id];
    expect($after['status'])->toBe('error')->and($after['access_token'])->toBe($stored['access_token'])
        ->and($after['refresh_token'])->toBe($stored['refresh_token']);
})->with(['binding', 'stored', 'remote']);

it('does not authorize a company owned by another user even with an explicit sandbox binding', function () {
    $this->memory->rows['companies'][3]['user_id'] = 9;
    expect(fn () => ($this->begin)())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('refuses a sandbox identity returned for a production company', function () {
    config(['services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    $query = ($this->begin)();
    ($this->fake)(['env' => 'production']);
    expect(fn () => $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']))->toThrow(RuntimeException::class);
    expect($this->memory->rows['superpdp_connections'])->toBe([]);
    Http::assertSentCount(2);
});

it('still renews production tokens using the exact French identity', function () {
    config(['services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    $query = ($this->begin)();
    $remote = ['number_scheme' => 'fr_siren', 'number' => '001234567', 'env' => 'production'];
    ($this->fake)($remote);
    $connection = $this->oauth->exchangeAuthorizationCode('unit-code', $query['state']);
    $connection->update(['access_token_expires_at' => now()->subMinute()]);
    ($this->fake)($remote);
    expect($this->oauth->refreshAccessToken($connection)->status)->toBe('connected');
    Http::assertSentCount(2);
});

it('does not save renewed tokens when the binding changes during the exchange', function () {
    $connection = ($this->complete)();
    $connection->update(['access_token_expires_at' => now()->subMinute()]);
    ($this->fake)([], fn () => config(['services.superpdp.sandbox_identity.company_id' => 8]));
    expect(fn () => $this->oauth->refreshAccessToken($connection))->toThrow(RuntimeException::class);
    expect($this->memory->rows['superpdp_connections'][$connection->id]['status'])->toBe('error');
});

it('connects reconnects and refreshes Tricatel without changing Burger Queen tokens or cursors', function () {
    $burger = ($this->complete)();
    $burger->update(['last_invoice_id' => 2077789, 'last_event_id' => 123, 'last_sync_at' => now()]);
    $burgerStored = $this->memory->rows['superpdp_connections'][$burger->id];
    $this->actingAs((new User)->forceFill(['id' => 4]));
    $this->tokens = ['access_token' => 'tricatel-access', 'refresh_token' => 'tricatel-refresh', 'expires_in' => 1800, 'company_id' => 18171];
    $this->remote = ['id' => 18171, 'number_scheme' => 'sandbox', 'number' => '000000001', 'country' => 'FR', 'env' => 'sandbox'];
    $query = ($this->begin)(4);
    expect($query['superpdp_company_number'])->toBe('000000001')
        ->and($query['superpdp_company_number_scheme'])->toBe('sandbox')
        ->and(session('superpdp_oauth.company_id'))->toBe(4)
        ->and(session('superpdp_oauth.user_id'))->toBe(4);
    ($this->fake)();
    $tricatel = $this->oauth->exchangeAuthorizationCode('tricatel-code', $query['state']);
    expect($tricatel->company_id)->toBe(4)->and($tricatel->superpdp_company_id)->toBe(18171)
        ->and($tricatel->directory_identifier)->toBe('000000001')
        ->and($tricatel->last_invoice_id)->toBe(0)->and($tricatel->last_event_id)->toBe(0)
        ->and($tricatel->last_sync_at)->toBeNull()->and($tricatel->access_token)->toBe('tricatel-access')
        ->and($tricatel->refresh_token)->toBe('tricatel-refresh')
        ->and($this->memory->rows['superpdp_connections'][$burger->id])->toBe($burgerStored);
    $tricatel->update(['last_invoice_id' => 2077791, 'last_event_id' => 456, 'last_sync_at' => now()]);
    $syncAt = $tricatel->last_sync_at->toISOString();
    $query = ($this->begin)(4);
    ($this->fake)();
    $reconnected = $this->oauth->exchangeAuthorizationCode('new-code', $query['state']);
    expect($reconnected->id)->toBe($tricatel->id);
    $reconnected->update(['access_token_expires_at' => now()->subHour()]);
    $this->tokens['access_token'] = 'tricatel-rotated-access';
    $this->tokens['refresh_token'] = 'tricatel-rotated-refresh';
    ($this->fake)();
    $renewed = $this->oauth->refreshAccessToken($reconnected);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['refresh_token'] === 'tricatel-refresh');
    expect($renewed->access_token)->toBe('tricatel-rotated-access')
        ->and($renewed->last_invoice_id)->toBe(2077791)->and($renewed->last_event_id)->toBe(456)
        ->and($renewed->last_sync_at->toISOString())->toBe($syncAt)
        ->and($this->memory->rows['superpdp_connections'][$burger->id])->toBe($burgerStored)
        ->and($this->memory->rows['superpdp_connections'])->toHaveCount(2);
});

it('rejects cross-user authorization before HTTP', function (int $userId, int $companyId) {
    $this->actingAs((new User)->forceFill(['id' => $userId]));
    expect(fn () => ($this->begin)($companyId))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
    expect($this->memory->rows['superpdp_connections'])->toBe([]);
})->with([[3, 4], [4, 3]]);

it('rejects cross-user or tampered Tricatel callbacks without changing Burger Queen', function (string $change) {
    ($this->complete)();
    $before = $this->memory->rows['superpdp_connections'];
    $this->actingAs((new User)->forceFill(['id' => 4]));
    $query = ($this->begin)(4);
    ($this->resetHttp)();
    match ($change) {
        'user' => $this->actingAs((new User)->forceFill(['id' => 3])),
        'company' => session(['superpdp_oauth.company_id' => 3]),
        'identity' => session(['superpdp_oauth.identity.number' => '000000002']),
    };
    expect(fn () => $this->oauth->exchangeAuthorizationCode('code', $query['state']))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
    expect(session('superpdp_oauth'))->toBeNull()->and($this->memory->rows['superpdp_connections'])->toBe($before);
})->with(['user', 'company', 'identity']);

it('rejects Burger Queen remote identity or inconsistent provider id for Tricatel', function (bool $wrongIdentity) {
    ($this->complete)();
    $before = $this->memory->rows['superpdp_connections'];
    $this->actingAs((new User)->forceFill(['id' => 4]));
    $query = ($this->begin)(4);
    $this->tokens['company_id'] = $wrongIdentity ? 42 : 18171;
    ($this->fake)($wrongIdentity ? [] : ['number' => '000000001']);
    expect(fn () => $this->oauth->exchangeAuthorizationCode('code', $query['state']))->toThrow(RuntimeException::class);
    expect($this->memory->rows['superpdp_connections'])->toBe($before);
})->with([true, false]);

it('refuses other sandbox companies and reassigned owners even with a valid SIRET', function (int $companyId, int $ownerId) {
    $this->memory->rows['companies'][$companyId] = ['id' => $companyId, 'user_id' => $ownerId, 'siret' => '00123456700012'];
    $this->actingAs((new User)->forceFill(['id' => $ownerId]));
    expect(fn () => ($this->begin)($companyId))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([[5, 5], [3, 4], [4, 3]]);

it('refuses sandbox OAuth outside the isolated database before HTTP', function () {
    config(['database.connections.other' => ['database' => 'other']]);
    expect(fn () => ($this->begin)())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
