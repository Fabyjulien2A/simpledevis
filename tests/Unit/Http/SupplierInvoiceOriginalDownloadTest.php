<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use Bluerock\SuperPdp\Requests\Invoices\GetInvoiceRequest;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Senders\GuzzleSender;
use Tests\Support\InMemorySuperPdpOAuthDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::preventStrayRequests();
    Config::setSenderResolver(static fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(static fn () => throw new LogicException('Network forbidden'));
        }
    });
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new InMemorySuperPdpOAuthDatabase;
    $resolver = new ConnectionResolver(['memory' => $this->memory]);
    $resolver->setDefaultConnection('memory');
    Model::setConnectionResolver($resolver);
    config(['session.driver' => 'array', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.client_id' => 'unit-client', 'services.superpdp.api_url' => 'https://provider.invalid/v1.beta']);
    $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel'];
    $company = Company::findOrFail(4);
    $user = (new User)->forceFill(['id' => 4]);
    $user->setRelation('company', $company);
    $this->actingAs($user);
    $connection = (new SuperPdpConnection)->forceFill([
        'id' => 2, 'company_id' => 4, 'environment' => 'sandbox', 'oauth_client_id' => 'unit-client',
        'api_url' => 'https://provider.invalid/v1.beta', 'superpdp_company_id' => 18171,
        'access_token' => 'unit-tricatel-token', 'refresh_token' => 'unit-tricatel-refresh', 'status' => 'connected',
    ]);
    $this->memory->rows['superpdp_connections'][2] = $connection->getAttributes();
    $this->memory->rows['supplier_invoices'][1] = [
        'id' => 1, 'company_id' => 4, 'superpdp_invoice_id' => 2077791, 'invoice_number' => 'BQ-TRICATEL',
    ];
    Http::swap(new class extends Factory
    {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('Network forbidden'));
        }
    });
    Http::preventStrayRequests();
    $this->beforeRows = $this->memory->rows;
    $this->download = fn () => $this->get(route('supplier-invoices.xml', 1));
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
    Config::setSenderResolver(static fn () => throw new LogicException('Network forbidden'));
    Model::setConnectionResolver($this->originalResolver);
});

it('downloads original XML or PDF bytes with the correct URL format and filename', function (string $body, string $type, string $extension) {
    $mock = MockClient::global([GetInvoiceRequest::class => MockResponse::make($body, 200, ['Content-Type' => $type])]);
    ($this->download)()->assertOk()->assertContent($body)->assertHeader('Content-Type', $type)
        ->assertHeader('Content-Disposition', 'attachment; filename="facture-BQ-TRICATEL.'.$extension.'"')
        ->assertHeader('Content-Length', (string) strlen($body));
    $mock->assertSent(function ($request, $response) {
        $psr = $response->getPsrRequest();

        return $psr->getMethod() === 'GET'
            && (string) $psr->getUri() === 'https://provider.invalid/v1.beta/invoices/2077791?format=original'
            && substr_count($psr->getUri()->getPath(), '/v1.beta') === 1
            && $request->query()->get('format') === 'original'
            && $psr->getHeaderLine('Authorization') === 'Bearer unit-tricatel-token';
    });
    $mock->assertSentCount(1);
    expect($this->memory->rows)->toBe($this->beforeRows)->and($this->memory->transactionAttempts)->toBe([]);
})->with([
    ['<?xml version="1.0"?><CrossIndustryInvoice/>', 'application/xml; charset=UTF-8', 'xml'],
    ['<?xml version="1.0" encoding="ISO-8859-1"?><Invoice/>', 'text/xml; charset=ISO-8859-1', 'xml'],
    ['<Invoice/>', 'application/xml', 'xml'],
    ['%PDF-1.7 original Factur-X', 'application/pdf', 'pdf'],
]);

it('maps provider errors without claiming that the original is absent or leaking remote content', function (int $status, string $message) {
    Log::spy();
    $mock = MockClient::global([GetInvoiceRequest::class => MockResponse::make('REMOTE-PRIVATE-CONTENT', $status)]);
    ($this->download)()->assertRedirect(route('supplier-invoices.show', 1))->assertSessionHas('error', $message);
    $mock->assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($text, $context) => ! str_contains(json_encode([$text, $context]), 'PRIVATE') && ! isset($context['response']));
    expect($this->memory->rows)->toBe($this->beforeRows);
})->with([
    [401, 'Le jeton SUPER PDP est expiré ou ne correspond pas à cet environnement.'],
    [403, 'SUPER PDP ne vous autorise pas à télécharger ce document.'],
    [404, 'Document indisponible via SUPER PDP'],
    [500, 'SUPER PDP a refusé le téléchargement du document.'],
]);

it('handles an empty original or a transport failure without retrying', function (bool $transportFailure) {
    $attempts = 0;
    $mock = MockClient::global([GetInvoiceRequest::class => $transportFailure
        ? static function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('private transport detail');
        }
        : MockResponse::make('', 200)]);
    ($this->download)()->assertRedirect(route('supplier-invoices.show', 1))->assertSessionHas('error', $transportFailure
        ? 'Impossible de télécharger le document depuis SUPER PDP.' : 'SUPER PDP a retourné un document vide.');
    if (! $transportFailure) {
        $mock->assertSentCount(1);
    } else {
        expect($attempts)->toBe(1);
    }
    expect($this->memory->rows)->toBe($this->beforeRows);
})->with([false, true]);

it('rejects access to another company invoice before any provider request', function () {
    $this->memory->rows['supplier_invoices'][1]['company_id'] = 3;
    $mock = MockClient::global([]);
    ($this->download)()->assertForbidden();
    $mock->assertNothingSent();
});

it('rejects unavailable or mismatched OAuth connections before any provider request', function (string $change) {
    match ($change) {
        'environment' => $this->memory->rows['superpdp_connections'][2]['environment'] = 'production',
        'client' => $this->memory->rows['superpdp_connections'][2]['oauth_client_id'] = 'other',
        'url' => $this->memory->rows['superpdp_connections'][2]['api_url'] = 'https://other.invalid/v1.beta',
        'status' => $this->memory->rows['superpdp_connections'][2]['status'] = 'error',
        'token' => $this->memory->rows['superpdp_connections'][2]['access_token'] = null,
        'foreign' => $this->memory->rows['superpdp_connections'][2]['company_id'] = 3,
    };
    $mock = MockClient::global([]);
    ($this->download)()->assertRedirect(route('supplier-invoices.show', 1))->assertSessionHas('error');
    $mock->assertNothingSent();
})->with(['environment', 'client', 'url', 'status', 'token', 'foreign']);

it('requires authentication before downloading', function () {
    auth()->logout();
    $mock = MockClient::global([]);
    ($this->download)()->assertRedirect(route('login'));
    $mock->assertNothingSent();
});
