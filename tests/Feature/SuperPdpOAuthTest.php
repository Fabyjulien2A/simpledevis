<?php

use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.superpdp.environment' => 'sandbox',
        'services.superpdp.client_id' => 'oauth-test-client',
        'services.superpdp.client_secret' => 'SECRET-NEVER-LOG',
        'services.superpdp.authorize_url' => 'https://superpdp.test/oauth2/authorize',
        'services.superpdp.token_url' => 'https://superpdp.test/oauth2/token',
        'services.superpdp.api_url' => 'https://superpdp.test/v1.beta',
        'services.superpdp.redirect_uri' => 'http://localhost/facturation-electronique/callback',
    ]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create(['company_name' => 'OAuth sandbox', 'siret' => '73282932000074']);
    $this->actingAs($this->owner);
    $this->oauth = app(SuperPdpOAuthService::class);
    $this->tokens = ['access_token' => 'ACCESS-NEVER-LOG', 'refresh_token' => 'REFRESH-NEVER-LOG', 'expires_in' => 1800, 'token_type' => 'Bearer'];
    $this->remote = ['id' => 123, 'number_scheme' => 'fr_siren', 'number' => '732829320', 'country' => 'FR', 'env' => 'sandbox'];
    $this->begin = function () {
        $response = $this->get(route('electronic-invoicing.connect'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    };
    $this->fake = function (?array $tokens = null, ?array $company = null) {
        Http::fake([
            'https://superpdp.test/oauth2/token' => Http::response($tokens ?? $this->tokens),
            'https://superpdp.test/v1.beta/companies/me' => Http::response($company ?? $this->remote),
        ]);
    };
    $this->callback = fn (array $query) => $this->get(route('electronic-invoicing.callback', ['code' => 'CODE-NEVER-LOG', 'state' => $query['state']]));
    $this->connection = function (array $attributes = []) {
        return $this->company->superPdpConnection()->create($attributes + [
            'environment' => 'sandbox', 'oauth_client_id' => 'oauth-test-client', 'api_url' => 'https://superpdp.test/v1.beta',
            'superpdp_company_id' => 123, 'access_token' => 'OLD-ACCESS', 'refresh_token' => 'OLD-REFRESH',
            'access_token_expires_at' => now()->subMinute(), 'status' => 'connected', 'reception_enabled' => true,
            'last_invoice_id' => 55, 'last_event_id' => 66,
        ]);
    };
});

it('completes consent PKCE identity verification and encrypted persistence for the owner', function () {
    $query = ($this->begin)();
    expect($query)->not->toHaveKey('superpdp_send_and_receive')
        ->and($query['response_type'])->toBe('code')->and($query['scope'])->toBe('')
        ->and($query['superpdp_company_number'])->toBe('732829320')->and($query['superpdp_company_number_scheme'])->toBe('fr_siren')
        ->and($query['code_challenge_method'])->toBe('S256');
    $pending = session('superpdp_oauth');
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='));
    ($this->fake)();
    ($this->callback)($query)->assertSessionHas('success');
    $connection = SuperPdpConnection::sole();
    expect($connection->company_id)->toBe($this->company->id)->and($connection->superpdp_company_id)->toBe(123)
        ->and($connection->environment)->toBe('sandbox')->and($connection->oauth_client_id)->toBe('oauth-test-client')
        ->and($connection->access_token)->toBe($this->tokens['access_token'])->and($connection->refresh_token)->toBe($this->tokens['refresh_token'])
        ->and($connection->getRawOriginal('access_token'))->not->toBe($this->tokens['access_token'])
        ->and($connection->getRawOriginal('refresh_token'))->not->toBe($this->tokens['refresh_token'])
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
        ->and($connection->reception_enabled)->toBeTrue()->and(session('superpdp_oauth'))->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['grant_type'] === 'authorization_code'
        && $request['code_verifier'] === $pending['verifier'] && $request['redirect_uri'] === config('services.superpdp.redirect_uri'));
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer '.$this->tokens['access_token']));
    Http::assertSentCount(2);
    ($this->callback)($query)->assertSessionHas('error');
    Http::assertSentCount(2);
});

it('rejects changed or expired local context before exchanging secrets', function (string $change) {
    $query = ($this->begin)();
    match ($change) {
        'state' => $query['state'] = 'wrong',
        'expired' => session(['superpdp_oauth.created_at' => now()->subMinutes(11)->timestamp]),
        'future' => session(['superpdp_oauth.created_at' => now()->addMinute()->timestamp]),
        'user' => $this->actingAs(User::factory()->create()),
        'owner' => $this->company->update(['user_id' => User::factory()->create()->id]),
        'siren' => $this->company->update(['siret' => '55210055400013']),
        'environment' => config(['services.superpdp.environment' => 'production']),
        'client' => config(['services.superpdp.client_id' => 'another-client']),
        'api' => config(['services.superpdp.api_url' => 'https://other.test/v1.beta']),
        'redirect' => config(['services.superpdp.redirect_uri' => 'http://localhost/other']),
    };
    ($this->callback)($query)->assertSessionHas('error');
    expect(SuperPdpConnection::count())->toBe(0)->and(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
})->with(['state', 'expired', 'future', 'user', 'owner', 'siren', 'environment', 'client', 'api', 'redirect']);

it('requires authentication at both endpoints', function () {
    $query = ($this->begin)();
    auth()->logout();
    $this->get(route('electronic-invoicing.connect'))->assertRedirect(route('login'));
    ($this->callback)($query)->assertRedirect(route('login'));
    Http::assertNothingSent();
});

it('rejects a foreign company passed directly to the authorization service', function () {
    $other = User::factory()->create()->company()->create(['company_name' => 'Other', 'siret' => '73282932000074']);
    expect(fn () => $this->oauth->getAuthorizationUrl($other, $this->owner->email))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('rejects a remote identity or environment mismatch without overwriting an existing connection', function (array $override) {
    $existing = ($this->connection)();
    $before = $existing->fresh()->getRawOriginal();
    $query = ($this->begin)();
    ($this->fake)(null, $override + $this->remote);
    ($this->callback)($query)->assertSessionHas('error');
    expect($existing->fresh()->getRawOriginal())->toBe($before);
})->with([
    [['id' => 0]], [['id' => '123']], [['number' => '552100554']], [['number_scheme' => 'sandbox']],
    [['country' => 'BE']], [['env' => 'production']],
]);

it('rechecks ownership after the remote request before storing any credential', function () {
    $query = ($this->begin)();
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response($this->tokens);
        }
        $this->company->update(['user_id' => User::factory()->create()->id]);

        return Http::response($this->remote);
    });
    ($this->callback)($query)->assertSessionHas('error');
    expect(SuperPdpConnection::count())->toBe(0);
});

it('handles refusal and malformed callbacks without displaying or logging provider secrets', function (array $query) {
    ($this->begin)();
    Log::spy();
    $this->get(route('electronic-invoicing.callback', $query))->assertSessionHas('error');
    expect(session('error'))->not->toContain('SECRET-NEVER-LOG')->and(session('superpdp_oauth'))->toBeNull();
    Log::shouldNotHaveReceived('error');
    Http::assertNothingSent();
})->with([
    [['error' => 'access_denied', 'error_description' => 'SECRET-NEVER-LOG']],
    [['code' => 'abc']], [['state' => 'abc']], [['code' => ['abc'], 'state' => 'abc']],
]);

it('sanitizes remote failures without storing tokens or retaining exception chains', function (string $failure) {
    $query = ($this->begin)();
    Http::fake(fn () => match ($failure) {
        'http' => Http::response(['access_token' => 'SECRET-NEVER-LOG'], 400),
        'json' => Http::response('SECRET-NEVER-LOG'),
        'transport' => throw new RuntimeException('SECRET-NEVER-LOG'),
    });
    try {
        $this->oauth->exchangeAuthorizationCode('code', $query['state']);
        $this->fail('Expected sanitized failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('SECRET-NEVER-LOG')->and($exception->getPrevious())->toBeNull();
    }
    expect(SuperPdpConnection::count())->toBe(0)->and(session('superpdp_oauth'))->toBeNull();
})->with(['http', 'json', 'transport']);

it('rejects incomplete token responses and inconsistent token company identifiers', function (array $override) {
    $query = ($this->begin)();
    ($this->fake)($override + $this->tokens);
    ($this->callback)($query)->assertSessionHas('error');
    expect(SuperPdpConnection::count())->toBe(0);
})->with([
    [['access_token' => '']], [['refresh_token' => null]], [['expires_in' => 0]],
    [['expires_in' => '1800']], [['token_type' => 'Other']], [['company_id' => 999]],
]);

it('can qualify production credentials but refuses to reuse them in sandbox', function () {
    config(['services.superpdp.environment' => 'production']);
    $query = ($this->begin)();
    ($this->fake)(null, ['env' => 'production'] + $this->remote);
    ($this->callback)($query)->assertSessionHas('success');
    $connection = SuperPdpConnection::sole();
    config(['services.superpdp.environment' => 'sandbox']);
    expect(fn () => $this->oauth->ensureValidAccessToken($connection))->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
});

it('refuses unknown legacy or crossed credential contexts before any request', function (array $override) {
    $connection = ($this->connection)($override);
    expect(fn () => $this->oauth->ensureValidAccessToken($connection))->toThrow(RuntimeException::class);
    expect(fn () => $this->oauth->refreshAccessToken($connection))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    [['environment' => null]], [['environment' => 'production']], [['oauth_client_id' => null]],
    [['oauth_client_id' => 'other']], [['api_url' => 'https://other.test']], [['status' => 'disconnected']],
]);

it('rotates under a transaction and reuses the winner tokens for a stale second worker', function () {
    $connection = ($this->connection)();
    $staleWorker = $connection->fresh();
    $oldRefresh = $connection->refresh_token;
    Http::fake(function ($request) use ($connection, $oldRefresh) {
        expect($connection->getConnection()->transactionLevel())->toBeGreaterThan(0);
        if ($request->method() === 'POST') {
            expect($request['grant_type'])->toBe('refresh_token')->and($request['refresh_token'])->toBe($oldRefresh);

            return Http::response($this->tokens);
        }

        return Http::response($this->remote);
    });
    $renewed = $this->oauth->ensureValidAccessToken($connection);
    expect($renewed->refresh_token)->toBe('REFRESH-NEVER-LOG')
        ->and($renewed->last_invoice_id)->toBe(55)->and($renewed->last_event_id)->toBe(66)
        ->and($renewed->environment)->toBe('sandbox');
    expect($this->oauth->refreshAccessToken($staleWorker)->access_token)->toBe('ACCESS-NEVER-LOG');
    Http::assertSentCount(2);
});

it('keeps a valid access token without refresh and honors the five minute renewal margin', function () {
    $connection = ($this->connection)(['access_token_expires_at' => now()->addMinutes(6)]);
    expect($this->oauth->ensureValidAccessToken($connection)->access_token)->toBe('OLD-ACCESS');
    Http::assertNothingSent();
    $connection->update(['access_token_expires_at' => now()->addMinutes(4)]);
    ($this->fake)();
    expect($this->oauth->ensureValidAccessToken($connection)->access_token)->toBe('ACCESS-NEVER-LOG');
    Http::assertSentCount(2);
});

it('does not write mismatched refreshed credentials', function () {
    $connection = ($this->connection)();
    $before = $connection->fresh()->getRawOriginal();
    ($this->fake)(null, ['id' => 999] + $this->remote);
    expect(fn () => $this->oauth->refreshAccessToken($connection))->toThrow(RuntimeException::class);
    $after = $connection->fresh()->getRawOriginal();
    unset($before['status'], $before['updated_at'], $after['status'], $after['updated_at']);
    expect($after)->toBe($before)->and($connection->fresh()->status)->toBe('error');
});

it('clears pending consent and credentials on local disconnect', function () {
    $connection = ($this->connection)();
    ($this->begin)();
    $this->post(route('electronic-invoicing.disconnect'))->assertSessionHas('success');
    expect($connection->fresh()->access_token)->toBeNull()->and($connection->fresh()->refresh_token)->toBeNull()
        ->and($connection->fresh()->reception_enabled)->toBeFalse()->and(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
});

it('requires explicit environment configuration', function () {
    config(['services.superpdp.environment' => null]);
    ($this->begin)();
    expect(session('error'))->toBeString()->and(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
});

it('preserves reception cursors when reconnecting the same OAuth company', function () {
    $existing = ($this->connection)();
    $query = ($this->begin)();
    ($this->fake)();
    ($this->callback)($query)->assertSessionHas('success');
    expect(SuperPdpConnection::count())->toBe(1)->and($existing->fresh()->last_invoice_id)->toBe(55)
        ->and($existing->fresh()->last_event_id)->toBe(66)->and($existing->fresh()->reception_enabled)->toBeTrue();
});

it('preserves existing credentials instead of replacing another environment', function () {
    $existing = ($this->connection)(['environment' => 'production']);
    $before = $existing->fresh()->getRawOriginal();
    $query = ($this->begin)();
    ($this->fake)();
    ($this->callback)($query)->assertSessionHas('error');
    expect($existing->fresh()->getRawOriginal())->toBe($before);
});

it('blocks stale workers after a failed refresh without logging remote secrets', function () {
    $connection = ($this->connection)();
    $staleWorker = $connection->fresh();
    Log::spy();
    Http::fake(['*' => Http::response(['error_description' => 'SECRET-NEVER-LOG'], 400)]);
    expect(fn () => $this->oauth->refreshAccessToken($connection))->toThrow(RuntimeException::class)
        ->and($connection->fresh()->status)->toBe('error');
    expect(fn () => $this->oauth->refreshAccessToken($staleWorker))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
