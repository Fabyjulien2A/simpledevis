<?php

use App\Services\SuperPdp\FrenchDirectoryConsultationException;
use App\Services\SuperPdp\FrenchDirectoryConsultationService;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryCompaniesRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\TestCase;

uses(TestCase::class);

function directoryCompany(string $number = '001234567'): array
{
    return ['number' => $number, 'formal_name' => 'Entreprise fictive',
        'address' => '1 rue Fictive', 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR'];
}

function directoryEntry(bool $active = true): array
{
    return ['company' => directoryCompany(), 'identifier' => 'Identifiant brut fourni par le test', 'is_active' => $active];
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun SQL autorisé pour la consultation de l’annuaire.');
    });
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $this->makeDirectory = function (mixed $body, int $status = 200): FrenchDirectoryConsultationService {
        $this->mockClient = new MockClient([MockResponse::make($body, $status)]);
        $this->connector = new SuperPdpConnector;
        $this->connector->withMockClient($this->mockClient);

        return new FrenchDirectoryConsultationService($this->connector->frenchDirectory());
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Config::allowStrayRequests();
    if (isset($this->mockClient)) {
        foreach ($this->mockClient->getRecordedResponses() as $response) {
            $request = $response->getPendingRequest();
            expect($request->getMethod())->toBe(Method::GET)
                ->and($request->getRequest()::class)->toBeIn([
                    ListFrenchDirectoryCompaniesRequest::class, ListFrenchDirectoryEntriesRequest::class,
                ]);
        }
    }
});

it('searches by exact SIREN using only the documented GET and preserves the envelope', function (bool $more, int $count) {
    $companies = array_fill(0, $count, directoryCompany());
    $expected = ['data' => $companies, 'has_more' => $more];
    $service = ($this->makeDirectory)($expected);
    expect($service->searchCompanies('001234567'))->toBe($expected);
    $this->mockClient->assertSentCount(1);
    $request = $this->mockClient->getLastPendingRequest();
    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->getRequest())->toBeInstanceOf(ListFrenchDirectoryCompaniesRequest::class)
        ->and($request->getUrl())->toBe('https://api.superpdp.tech/v1.beta/french_directory/companies')
        ->and($request->query()->all())->toBe(['number' => '001234567', 'limit' => 100]);
})->with([[false, 0], [false, 1], [false, 2], [true, 2], [true, 0]]);

it('returns every entry in its original order without parsing selecting or filtering identifiers', function () {
    $entries = [directoryEntry(false), directoryEntry(), directoryEntry()];
    $entries[1]['identifier'] = '0225:001234567';
    $entries[2]['identifier'] = '  Identifiant opaque avec espaces  ';
    $expected = ['data' => $entries];
    $service = ($this->makeDirectory)($expected);
    expect($service->listEntries('001234567'))->toBe($expected);
    $this->mockClient->assertSentCount(1);
    $request = $this->mockClient->getLastPendingRequest();
    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->getRequest())->toBeInstanceOf(ListFrenchDirectoryEntriesRequest::class)
        ->and($request->getUrl())->toBe('https://api.superpdp.tech/v1.beta/french_directory/entries')
        ->and($request->query()->all())->toBe(['number' => '001234567']);
    foreach ($entries as $entry) {
        expect($entry)->not->toHaveKeys(['scheme', 'value', 'routing_verified', 'eligible', 'selected']);
    }
});

it('preserves optional entry pagination without inventing it or issuing another request', function (array $body) {
    $service = ($this->makeDirectory)($body);
    expect($service->listEntries('001234567'))->toBe($body);
    $this->mockClient->assertSentCount(1);
})->with([
    [['data' => []]],
    [['data' => [], 'has_more' => false]],
    [['data' => [directoryEntry()], 'has_more' => true]],
]);

it('validates the SIREN before any consultation without coercion or normalization', function (mixed $siren, string $method) {
    $service = ($this->makeDirectory)(['data' => [], 'has_more' => false]);
    expect(fn () => $service->{$method}($siren))->toThrow(InvalidArgumentException::class);
    $this->mockClient->assertNothingSent();
})->with([
    null, false, 123456789, 123456789.0, [['001234567']], '', '12345678', '1234567890',
    '00123456700012', ' 001234567', '001234567 ', "001234567\n", '001 234 567', '12345678A', '１２３４５６７８９',
])->with(['searchCompanies', 'listEntries']);

it('checks the format only and preserves leading zeroes', function (string $siren) {
    $body = ['data' => [directoryCompany($siren)], 'has_more' => false];
    expect(($this->makeDirectory)($body)->searchCompanies($siren))->toBe($body);
})->with(['000000000', '001234567']);

it('distinguishes HTTP authentication and API failures from empty results without leaking the body', function (int $status, string $reason, string $method) {
    $service = ($this->makeDirectory)(['message' => 'REMOTE-SECRET'], $status);
    try {
        $service->{$method}('001234567');
        $this->fail('Une erreur HTTP doit être signalée.');
    } catch (FrenchDirectoryConsultationException $exception) {
        expect($exception->reason)->toBe($reason)->and($exception->httpStatus)->toBe($status)
            ->and($exception->getMessage())->not->toContain('REMOTE-SECRET')
            ->and($exception->getPrevious())->toBeNull();
    }
    $this->mockClient->assertSentCount(1);
})->with([[401, 'authentication'], [403, 'authentication'], [400, 'api_error'], [404, 'api_error'],
    [429, 'api_error'], [500, 'api_error'], [503, 'api_error'], [204, 'api_error'], [302, 'api_error']])
    ->with(['searchCompanies', 'listEntries']);

it('rejects malformed envelopes for either operation', function (mixed $body, string $method) {
    $service = ($this->makeDirectory)($body);
    try {
        $service->{$method}('001234567');
        $this->fail('Une réponse mal formée doit être signalée.');
    } catch (FrenchDirectoryConsultationException $exception) {
        expect($exception->reason)->toBe('malformed_response')->and($exception->httpStatus)->toBeNull();
    }
    $this->mockClient->assertSentCount(1);
})->with([
    [''], ['{invalid'], ['null'], ['[]'], ['{}'], ['{"data":{}}'],
    [['data' => null]], [['data' => 'invalid']], [['data' => ['key' => directoryCompany()]]],
    [['data' => [], 'has_more' => 'false']], [['data' => [], 'has_more' => 0]], [['data' => [], 'has_more' => null]],
])->with(['searchCompanies', 'listEntries']);

it('requires has_more on company results as specified by the local contract', function () {
    $service = ($this->makeDirectory)(['data' => []]);
    expect(fn () => $service->searchCompanies('001234567'))->toThrow(FrenchDirectoryConsultationException::class);
});

it('rejects incomplete or incorrectly typed company fields in both result shapes', function (string $field, string $method) {
    $company = directoryCompany();
    unset($company[$field]);
    $body = ['data' => $method === 'searchCompanies' ? [$company] : [array_replace(directoryEntry(), ['company' => $company])], 'has_more' => false];
    $service = ($this->makeDirectory)($body);
    expect(fn () => $service->{$method}('001234567'))->toThrow(FrenchDirectoryConsultationException::class);
    $company[$field] = 123;
    $body['data'] = $method === 'searchCompanies' ? [$company] : [array_replace(directoryEntry(), ['company' => $company])];
    $service = ($this->makeDirectory)($body);
    expect(fn () => $service->{$method}('001234567'))->toThrow(FrenchDirectoryConsultationException::class);
})->with(['number', 'formal_name', 'address', 'postcode', 'city', 'country'])->with(['searchCompanies', 'listEntries']);

it('rejects a company that does not match the requested SIREN without returning a partial result', function (string $method) {
    $body = $method === 'searchCompanies'
        ? ['data' => [directoryCompany(), directoryCompany('999999999')], 'has_more' => false]
        : ['data' => [directoryEntry(), array_replace(directoryEntry(), ['company' => directoryCompany('999999999')])]];
    expect(fn () => ($this->makeDirectory)($body)->{$method}('001234567'))->toThrow(FrenchDirectoryConsultationException::class);
})->with(['searchCompanies', 'listEntries']);

it('rejects malformed entries without interpreting the identifier format', function (mixed $entry) {
    $service = ($this->makeDirectory)(['data' => [$entry]]);
    expect(fn () => $service->listEntries('001234567'))->toThrow(FrenchDirectoryConsultationException::class);
})->with([
    [null], [[]], ['entry'],
    [array_diff_key(directoryEntry(), ['company' => true])],
    [array_replace(directoryEntry(), ['company' => null])],
    [array_replace(directoryEntry(), ['identifier' => null])],
    [array_replace(directoryEntry(), ['identifier' => 123])],
    [array_replace(directoryEntry(), ['identifier' => ''])],
    [array_replace(directoryEntry(), ['identifier' => " \t\n"])],
    [array_diff_key(directoryEntry(), ['is_active' => true])],
    [array_replace(directoryEntry(), ['is_active' => 'true'])],
    [array_replace(directoryEntry(), ['is_active' => 1])],
]);

it('maps SDK thrown HTTP exceptions to the same sanitized error contract', function () {
    $service = ($this->makeDirectory)(['message' => 'REMOTE-SECRET'], 403);
    $this->connector->middleware()->onResponse(fn ($response) => throw new RequestException($response));
    try {
        $service->listEntries('001234567');
        $this->fail('Une erreur du SDK doit être signalée.');
    } catch (FrenchDirectoryConsultationException $exception) {
        expect($exception->reason)->toBe('authentication')->and($exception->httpStatus)->toBe(403)
            ->and($exception->getMessage())->not->toContain('REMOTE-SECRET');
    }
});

it('reports transport failures without exposing SDK messages or using OAuth', function () {
    $service = ($this->makeDirectory)(['data' => []]);
    $this->connector->middleware()->onRequest(function (PendingRequest $request) {
        throw new FatalRequestException(new RuntimeException('TRANSPORT-SECRET'), $request);
    });
    try {
        $service->listEntries('001234567');
        $this->fail('Une erreur de transport doit être signalée.');
    } catch (FrenchDirectoryConsultationException $exception) {
        expect($exception->reason)->toBe('unavailable')->and($exception->httpStatus)->toBeNull()
            ->and($exception->getMessage())->not->toContain('TRANSPORT-SECRET')
            ->and($exception->getPrevious())->toBeNull();
    }
    $this->mockClient->assertNothingSent();
});
