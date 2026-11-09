<?php

use App\Models\User;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

function clientDirectoryLine(string $identifier = '0225:001234567', bool $active = true): array
{
    return ['company' => ['number' => '001234567', 'formal_name' => 'Entreprise fictive',
        'address' => '1 rue Fictive', 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR'],
        'identifier' => $identifier, 'is_active' => $active];
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    config(['services.superpdp.directory_base_url' => 'https://api.superpdp.tech',
        'services.superpdp.api_url' => 'https://reception-unused.invalid/v1.beta']);
    $this->owner = User::factory()->create();
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Client fictif', 'client_type' => 'professional', 'country_code' => 'FR', 'siret' => '00123456700012',
    ]);
    $this->actingAs($this->owner);
    $this->url = route('clients.directory', $this->client);
    $this->fakeDirectory = function (mixed $body, int $status = 200) {
        MockClient::destroyGlobal();
        $this->mockClient = MockClient::global([
            ListFrenchDirectoryEntriesRequest::class => MockResponse::make($body, $status),
        ]);
    };
    ($this->fakeDirectory)(['data' => []]);
    $oauth = $this->createMock(SuperPdpOAuthService::class);
    $oauth->expects($this->never())->method('ensureValidAccessToken');
    $oauth->expects($this->never())->method('refreshAccessToken');
    $this->app->instance(SuperPdpOAuthService::class, $oauth);
});

afterEach(function () {
    Http::assertNothingSent();
    foreach ($this->mockClient->getRecordedResponses() as $response) {
        $pending = $response->getPendingRequest();
        expect($pending->getMethod())->toBe(Method::GET)
            ->and($pending->getRequest())->toBeInstanceOf(ListFrenchDirectoryEntriesRequest::class)
            ->and($pending->getUrl())->toBe('https://api.superpdp.tech/v1.beta/french_directory/entries')
            ->and($pending->query()->all())->toBe(['number' => '001234567'])
            ->and($pending->headers()->get('Authorization'))->toBeNull()
            ->and($pending->config()->get('timeout'))->toBe(15)
            ->and($pending->config()->get('connect_timeout'))->toBe(5)
            ->and($pending->config()->get('allow_redirects'))->toBeFalse();
    }
    MockClient::destroyGlobal();
    Config::allowStrayRequests();
});

it('shows the explicit POST button without consulting on show edit or index', function () {
    $this->get(route('clients.show', $this->client))->assertOk()->assertSee("Consulter l'annuaire", false)
        ->assertSee('action="'.$this->url.'"', false)->assertSee('method="POST"', false)
        ->assertSee('name="_token"', false)->assertDontSee('directory-results-title', false);
    $this->get(route('clients.edit', $this->client))->assertOk();
    $this->get(route('clients.index'))->assertOk();
    $this->mockClient->assertNothingSent();
});

it('requires an explicit authenticated POST', function () {
    $this->get($this->url)->assertStatus(405);
    $this->put($this->url)->assertStatus(405);
    auth()->logout();
    $this->post($this->url)->assertRedirect(route('login'));
    $this->mockClient->assertNothingSent();
});

it('refuses another owners client before any remote request', function () {
    $other = User::factory()->create();
    $this->actingAs($other)->post($this->url, ['siret' => '00123456700012'])->assertForbidden();
    $this->get(route('clients.show', $this->client))->assertForbidden();
    $this->mockClient->assertNothingSent();
});

it('does not consult for a client that does not exist', function () {
    $this->post(route('clients.directory', 999999))->assertNotFound();
    $this->mockClient->assertNothingSent();
});

it('rejects out of scope clients and omits their button', function (string $type, string $country) {
    $this->client->update(['client_type' => $type, 'country_code' => $country]);
    $this->get(route('clients.show', $this->client))->assertOk()->assertDontSee("Consulter l'annuaire", false);
    $this->post($this->url)->assertStatus(422)->assertSee('La consultation est réservée aux clients professionnels français.');
    $this->mockClient->assertNothingSent();
})->with([['individual', 'FR'], ['professional', 'BE'], ['professional', 'fr']]);

it('requires a persisted fourteen digit ASCII SIRET and ignores request replacements', function (?string $siret) {
    $this->client->update(['siret' => $siret]);
    $this->post($this->url, ['siret' => '00123456700012', 'siren' => '001234567'])->assertStatus(422)
        ->assertSee('Renseignez et enregistrez un SIRET de 14 chiffres');
    $this->mockClient->assertNothingSent();
})->with([null, '', '001234567', '0012345670001', '001234567000123', '0012345670001A',
    ' 00123456700012', '00123456700012 ', "00123456700012\n", '００１２３４５６７０００１２']);

it('renders multiple lines in order including duplicates inactive composed and technical entries', function () {
    $lines = [clientDirectoryLine(), clientDirectoryLine('0225:001234567_ACHATS'),
        clientDirectoryLine('0225:001234567_INACTIF', false), clientDirectoryLine(),
        clientDirectoryLine('0225:001234567_REPLYTO')];
    ($this->fakeDirectory)(['data' => $lines, 'has_more' => true]);
    $response = $this->post($this->url, ['siren' => '999999999', 'selected' => 'invented']);
    $response->assertOk()->assertViewIs('clients.show')
        ->assertSee('SIREN consulté : 001234567')->assertSee('Active — format simple compatible')
        ->assertSee('Inactive')->assertSee('Format non pris en charge')->assertSee('Données invalides ou incohérentes')
        ->assertSee('Les résultats retournés sont incomplets.')
        ->assertSee('Une ligne active dans l’annuaire ne garantit pas la réception d’une facture.')
        ->assertSee('Aucune destination n’est sélectionnée ou enregistrée.')
        ->assertViewHas('directoryAnalysis', fn ($analysis) => array_column($analysis['lines'], 'line') === $lines
            && $analysis['pagination'] === ['has_more' => true]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $section = substr($response->getContent(), strpos($response->getContent(), '<section aria-labelledby="directory-results-title"'));
    $section = substr($section, 0, strpos($section, '</section>'));
    expect($section)->not->toContain('<button', '<form', '<input', '<select');
    $this->mockClient->assertSentCount(1);
});

it('escapes raw remote identifiers and company text', function () {
    $line = clientDirectoryLine('<script>alert("RAW")</script>');
    $line['company']['formal_name'] = '<script>company</script>';
    ($this->fakeDirectory)(['data' => [$line]]);
    $this->post($this->url)->assertOk()->assertSee($line['identifier'])->assertDontSee($line['identifier'], false)
        ->assertSee('Données invalides ou incohérentes');
});

it('renders empty responses without claiming a destination is nonexistent', function (array $pagination) {
    ($this->fakeDirectory)(['data' => []] + $pagination);
    $response = $this->post($this->url)->assertOk()->assertSee('Aucune ligne retournée pour cette consultation.')
        ->assertSee('Cela ne prouve pas l’absence de destination.')
        ->assertViewHas('directoryAnalysis', fn ($analysis) => $analysis['lines'] === [] && $analysis['pagination'] === $pagination);
    if (($pagination['has_more'] ?? false) !== true) {
        $response->assertDontSee('Les résultats retournés sont incomplets.');
    }
    $this->mockClient->assertSentCount(1);
})->with([[[]], [['has_more' => false]], [['has_more' => true]]]);

it('uses local sanitized messages for API refusals and malformed responses', function (int $status, mixed $body, string $message) {
    ($this->fakeDirectory)($body, $status);
    $this->post($this->url)->assertStatus(502)->assertSee($message)->assertDontSee('REMOTE-SECRET');
    $this->mockClient->assertSentCount(1);
})->with([
    [401, ['message' => 'REMOTE-SECRET'], 'authentification ou autorisation'],
    [403, ['message' => 'REMOTE-SECRET'], 'authentification ou autorisation'],
    [429, ['message' => 'REMOTE-SECRET'], 'une erreur API'],
    [500, ['message' => 'REMOTE-SECRET'], 'une erreur API'],
    [302, ['message' => 'REMOTE-SECRET'], 'une erreur API'],
    [200, '{REMOTE-SECRET', 'mal formée'],
]);

it('handles transport failure without exposing the SDK message', function () {
    MockClient::destroyGlobal();
    $this->mockClient = MockClient::global([ListFrenchDirectoryEntriesRequest::class => function (PendingRequest $request) {
        throw new FatalRequestException(new RuntimeException('TRANSPORT-SECRET'), $request);
    }]);
    $this->post($this->url)->assertStatus(503)->assertSee('temporairement inaccessible')->assertDontSee('TRANSPORT-SECRET');
});

it('does not depend on absent expired or disconnected reception credentials', function (string $state) {
    if ($state !== 'absent') {
        $company = $this->owner->company()->create(['company_name' => 'Atelier fictif']);
        $company->superPdpConnection()->create(['status' => $state, 'access_token' => 'RECEPTION-SECRET',
            'refresh_token' => 'REFRESH-SECRET', 'access_token_expires_at' => now()->subDay(), 'reception_enabled' => false]);
    }
    $before = DB::table('superpdp_connections')->get()->toJson();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucun renouvellement ou écriture de connexion pendant la consultation.');
        }
    });
    $this->post($this->url)->assertOk()->assertDontSee('RECEPTION-SECRET')->assertDontSee('REFRESH-SECRET');
    expect(DB::table('superpdp_connections')->get()->toJson())->toBe($before);
    $this->mockClient->assertSentCount(1);
})->with(['absent', 'connected', 'disconnected']);

it('returns a local configuration error without consulting a versioned base URL', function () {
    config(['services.superpdp.directory_base_url' => 'https://api.superpdp.tech/v1.beta']);
    $this->post($this->url)->assertStatus(503)->assertSee('sa configuration doit être vérifiée');
    $this->mockClient->assertNothingSent();
});

it('enforces real CSRF checks for missing invalid and valid tokens', function () {
    // Laravel court-circuite normalement CSRF pendant ses tests : lever uniquement ce raccourci.
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $token = str_repeat('a', 40);
    $this->withSession(['_token' => $token])->post($this->url)->assertStatus(419);
    $this->withSession(['_token' => $token])->post($this->url, ['_token' => 'wrong'])->assertStatus(419);
    $this->mockClient->assertNothingSent();
    $this->withSession(['_token' => $token])->post($this->url, ['_token' => $token])->assertOk();
    $this->mockClient->assertSentCount(1);
});

it('limits calls per user across clients and keeps other users independent', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post($this->url)->assertOk();
    }
    $this->post($this->url)->assertStatus(429)->assertSee('Trop de consultations de l’annuaire.')->assertHeader('Retry-After');
    $this->mockClient->assertSentCount(5);
    $another = $this->owner->clients()->create(['client_type' => 'professional', 'country_code' => 'FR', 'siret' => '00123456700012']);
    $this->post(route('clients.directory', $another))->assertStatus(429);
    $otherUser = User::factory()->create();
    $otherClient = $otherUser->clients()->create(['client_type' => 'professional', 'country_code' => 'FR', 'siret' => '00123456700012']);
    $this->actingAs($otherUser)->post(route('clients.directory', $otherClient))->assertOk();
    $this->mockClient->assertSentCount(6);
});

it('never writes business data or stores results in session or cache and forgets results on the next GET', function () {
    $raw = '0225:001234567_UNIQUE_RESULT';
    ($this->fakeDirectory)(['data' => [clientDirectoryLine($raw)]]);
    $tables = ['clients', 'companies', 'invoices', 'invoice_items', 'superpdp_connections', 'supplier_invoices', 'electronic_invoices'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Toute écriture SQL est interdite pendant cette consultation.');
        }
    });
    $this->post($this->url)->assertOk()->assertSee($raw)->assertSessionMissing(['directoryAnalysis', 'directoryResult', 'directoryError']);
    expect($snapshot())->toBe($before)
        ->and(serialize(session()->all()))->not->toContain($raw)
        ->and(serialize(Cache::store()->getStore()))->not->toContain($raw);
    $this->get(route('clients.show', $this->client))->assertOk()->assertDontSee($raw)->assertDontSee('directory-results-title');
    $this->mockClient->assertSentCount(1);
});
