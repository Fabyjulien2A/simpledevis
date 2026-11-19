<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemorySuperPdpOAuthDatabase;
use Tests\TestCase;

// Exercise Laravel routes and middleware, with no SQL driver or real HTTP transport.
uses(TestCase::class);

beforeEach(function () {
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new InMemorySuperPdpOAuthDatabase;
    $resolver = new ConnectionResolver(['superpdp_sandbox' => $this->memory]);
    $resolver->setDefaultConnection('superpdp_sandbox');
    Model::setConnectionResolver($resolver);
    $this->memory->rows['companies'][3] = ['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null];
    $this->company = Company::findOrFail(3);
    $user = new User;
    $user->forceFill(['id' => 3, 'name' => 'Owner', 'email' => 'owner@example.invalid']);
    $user->setRelation('company', $this->company);
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
    // Laravel normally skips CSRF in tests. Exercise the real token check here.
    $this->app->bind(ValidateCsrfToken::class, function ($app) {
        return new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });
    Http::swap(new class extends Factory
    {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('Network forbidden'));
        }
    });
    Http::preventStrayRequests();
    $this->csrf = str_repeat('a', 40);
    $this->withSession(['_token' => $this->csrf]);
    $this->start = fn (array $data = []) => $this->post(route('electronic-invoicing.connect'), $data + ['_token' => $this->csrf]);
});

afterEach(function () {
    Model::setConnectionResolver($this->originalResolver);
});

it('starts the explicitly configured sandbox company with CSRF and a 303 without requiring a SIRET', function () {
    $response = ($this->start)()->assertStatus(303);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $pending = session('superpdp_oauth');
    expect($query['superpdp_company_number_scheme'])->toBe('sandbox')
        ->and($query['superpdp_company_number'])->toBe('000000002')
        ->and($query['redirect_uri'])->toBe(config('services.superpdp.redirect_uri'))
        ->and($query['state'])->toBe($pending['state'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='))
        ->and($pending['company_id'])->toBe(3)->and($pending['user_id'])->toBe(3);
    Http::assertNothingSent();
});

it('starts Tricatel from its own account and ignores client-supplied Burger Queen identifiers', function () {
    $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null];
    $user = (new User)->forceFill(['id' => 4, 'email' => 'tricatel@example.invalid']);
    $user->setRelation('company', Company::findOrFail(4));
    $this->actingAs($user);
    $response = ($this->start)(['company_id' => 3, 'superpdp_company_number' => '000000002'])->assertStatus(303);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['superpdp_company_number_scheme'])->toBe('sandbox')
        ->and($query['superpdp_company_number'])->toBe('000000001')
        ->and(session('superpdp_oauth.company_id'))->toBe(4)
        ->and(session('superpdp_oauth.user_id'))->toBe(4);
    Http::assertNothingSent();
    expect($this->memory->rows['superpdp_connections'])->toBe([]);
});

it('refuses POST without a matching CSRF token before creating OAuth state', function (?string $token) {
    $data = $token === null ? [] : ['_token' => $token];
    $this->post(route('electronic-invoicing.connect'), $data)->assertStatus(419);
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
})->with([null, 'wrong']);

it('no longer permits starting OAuth through GET', function () {
    $this->get(route('electronic-invoicing.connect'))->assertStatus(405);
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
});

it('requires authentication for the POST and GET callback', function (string $environment) {
    config(['services.superpdp.environment' => $environment]);
    auth()->logout();
    ($this->start)()->assertRedirect(route('login'));
    $this->get(route('electronic-invoicing.callback', ['code' => 'unit-code', 'state' => 'unit-state']))->assertRedirect(route('login'));
    Http::assertNothingSent();
})->with(['sandbox', 'production']);

it('refuses a logged-in account without a local company', function () {
    auth()->user()->setRelation('company', null);
    ($this->start)()->assertNotFound();
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
});

it('refuses sandbox opt-in for the wrong local company or invalid configuration', function (array $binding) {
    config(['services.superpdp.sandbox_identity' => $binding]);
    ($this->start)(['company_id' => 8, 'superpdp_company_number_scheme' => 'sandbox', 'superpdp_company_number' => '000000002'])
        ->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('error');
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
})->with([
    [['company_id' => '8', 'number_scheme' => 'sandbox', 'number' => '000000002']],
    [['company_id' => '3']],
    [['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000001']],
]);

it('refuses a company no longer owned by the authenticated user', function (string $environment) {
    config(['services.superpdp.environment' => $environment]);
    $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    $this->memory->rows['companies'][3]['user_id'] = 9;
    ($this->start)()->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('error');
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
})->with(['sandbox', 'production']);

it('refuses an unspecified SUPER PDP environment', function () {
    config(['services.superpdp.environment' => null]);
    ($this->start)()->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('error');
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
});

it('preserves the French SIRET requirement in production despite the sandbox configuration', function (?string $siret) {
    config(['services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][3]['siret'] = $siret;
    ($this->start)()->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('error');
    expect(session('superpdp_oauth'))->toBeNull();
    Http::assertNothingSent();
})->with([null, '', '000000002', '1234567890123']);

it('starts production with fr_siren and ignores client-supplied sandbox identifiers', function () {
    config(['services.superpdp.environment' => 'production']);
    $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    $response = ($this->start)(['company_id' => 8, 'superpdp_company_number_scheme' => 'sandbox', 'superpdp_company_number' => '000000002'])->assertStatus(303);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['superpdp_company_number_scheme'])->toBe('fr_siren')->and($query['superpdp_company_number'])->toBe('001234567')
        ->and(session('superpdp_oauth.company_id'))->toBe(3);
    Http::assertNothingSent();
});

it('renders a POST connection button with a CSRF field and no GET start link', function () {
    $this->withoutVite();
    $view = $this->view('electronic-invoicing.index', ['company' => $this->company, 'connection' => null, 'receivedInvoicesCount' => 0]);
    $document = new DOMDocument;
    @$document->loadHTML((string) $view);
    $xpath = new DOMXPath($document);
    $url = route('electronic-invoicing.connect');
    $forms = $xpath->query('//form[@method="POST" and @action="'.$url.'"]');
    expect($forms->length)->toBe(1)
        ->and($xpath->query('.//input[@type="hidden" and @name="_token" and @value="'.$this->csrf.'"]', $forms->item(0))->length)->toBe(1)
        ->and($xpath->query('.//button[@type="submit"]', $forms->item(0))->length)->toBe(1)
        ->and($xpath->query('//a[@href="'.$url.'"]')->length)->toBe(0);
    Http::assertNothingSent();
});

it('keeps the callback GET and completes sandbox or production using only simulated HTTP', function (string $environment) {
    config(['services.superpdp.environment' => $environment]);
    if ($environment === 'production') {
        $this->memory->rows['companies'][3]['siret'] = '00123456700012';
    }
    $response = ($this->start)()->assertStatus(303);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    Http::fake([
        'https://provider.invalid/oauth2/token' => Http::response(['access_token' => 'unit-access', 'refresh_token' => 'unit-refresh', 'expires_in' => 1800, 'token_type' => 'Bearer', 'company_id' => 42]),
        'https://provider.invalid/v1.beta/companies/me' => Http::response(['id' => 42, 'country' => 'FR', 'env' => $environment,
            'number_scheme' => $query['superpdp_company_number_scheme'], 'number' => $query['superpdp_company_number']]),
    ]);
    $this->get(route('electronic-invoicing.callback', ['code' => 'unit-code', 'state' => $query['state']]))
        ->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('success');
    expect($this->memory->rows['superpdp_connections'][1]['directory_identifier'])->toBe($query['superpdp_company_number'])
        ->and(session('superpdp_oauth'))->toBeNull();
    $this->get(route('electronic-invoicing.callback', ['code' => 'unit-code', 'state' => $query['state']]))->assertSessionHas('error');
    Http::assertSentCount(2);
    $this->post(route('electronic-invoicing.callback'), ['_token' => $this->csrf])->assertStatus(405);
})->with(['sandbox', 'production']);

it('rejects altered state environment or ownership on the GET callback without HTTP', function (string $change) {
    $response = ($this->start)()->assertStatus(303);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    match ($change) {
        'state' => $query['state'] = 'wrong',
        'owner' => $this->memory->rows['companies'][3]['user_id'] = 9,
        'environment' => config(['services.superpdp.environment' => 'production']),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 8]),
        'expired' => session(['superpdp_oauth.created_at' => now()->subMinutes(11)->timestamp]),
        'user' => $this->actingAs((new User)->forceFill(['id' => 9])),
    };
    $this->get(route('electronic-invoicing.callback', ['code' => 'unit-code', 'state' => $query['state']]))
        ->assertRedirect(route('electronic-invoicing.index'))->assertSessionHas('error');
    expect(session('superpdp_oauth'))->toBeNull()->and($this->memory->rows['superpdp_connections'])->toBe([]);
    Http::assertNothingSent();
})->with(['state', 'owner', 'environment', 'binding', 'expired', 'user']);
