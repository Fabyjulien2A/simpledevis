<?php

use App\Services\SuperPdp\FrenchDirectoryConsultationFactory;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
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
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès SQL pour la composition annuaire.');
    });
    config(['services.superpdp.api_url' => 'https://reception-unused.invalid/v1.beta',
        'services.superpdp.client_id' => 'UNUSED-ID', 'services.superpdp.client_secret' => 'UNUSED-SECRET']);
    $this->mockClient = MockClient::global([
        ListFrenchDirectoryEntriesRequest::class => MockResponse::make(['data' => []]),
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
    Config::allowStrayRequests();
});

it('creates a lazy dedicated unauthenticated SDK transport with bounded timeouts and no redirects', function (string $url) {
    config(['services.superpdp.directory_base_url' => $url]);
    $service = (new FrenchDirectoryConsultationFactory)->create();
    $this->mockClient->assertNothingSent();
    expect($service->listEntries('001234567'))->toBe(['data' => []]);
    $request = $this->mockClient->getLastPendingRequest();
    expect($request->getUrl())->toBe('https://directory.example.invalid/v1.beta/french_directory/entries')
        ->and($request->headers()->get('Authorization'))->toBeNull()
        ->and($request->config()->get('timeout'))->toBe(15)
        ->and($request->config()->get('connect_timeout'))->toBe(5)
        ->and($request->config()->get('allow_redirects'))->toBeFalse()
        ->and($request->getConnector()->tries)->toBeNull();
    $this->mockClient->assertSentCount(1);
})->with(['https://directory.example.invalid', 'https://directory.example.invalid/']);

it('refuses ambiguous or unsafe base configuration before any request', function (mixed $url) {
    config(['services.superpdp.directory_base_url' => $url]);
    expect(fn () => (new FrenchDirectoryConsultationFactory)->create())->toThrow(InvalidArgumentException::class);
    $this->mockClient->assertNothingSent();
})->with([
    [null], [false], [[]], [''], ['api.superpdp.tech'], ['http://api.superpdp.tech'],
    ['https://api.superpdp.tech/v1.beta'], ['https://api.superpdp.tech/v1.beta/'],
    ['https://api.superpdp.tech/other'], ['https://api.superpdp.tech?token=SECRET'],
    ['https://user:SECRET@api.superpdp.tech'], ['https://api.superpdp.tech#fragment'],
    ['https://invalid host'], ['https://api.superpdp.tech\\other'],
]);
