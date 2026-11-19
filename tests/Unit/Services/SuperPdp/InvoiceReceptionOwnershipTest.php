<?php

use App\Models\SuperPdpConnection;
use App\Models\SupplierInvoice;
use App\Services\SuperPdp\SuperPdpInvoiceService;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->previousResolver = Model::getConnectionResolver();
    Model::setConnectionResolver(new class implements ConnectionResolverInterface
    {
        public function connection($name = null)
        {
            throw new LogicException('No SQL in reception unit tests.');
        }

        public function getDefaultConnection()
        {
            return 'forbidden';
        }

        public function setDefaultConnection($name)
        {
            throw new LogicException('No SQL in reception unit tests.');
        }
    });
    Http::preventStrayRequests();
    $this->service = new SuperPdpInvoiceService($this->createMock(SuperPdpOAuthService::class));
    $this->connection = (new SuperPdpConnection)->forceFill(['company_id' => 3, 'superpdp_company_id' => 901]);
});

afterEach(function () {
    Http::assertNothingSent();
    Model::setConnectionResolver($this->previousResolver);
});

it('refuses foreign missing or malformed recipient company IDs before entering a write transaction', function (bool $present, mixed $id, mixed $expected) {
    $this->connection->superpdp_company_id = $expected;
    $detail = ['id' => 2077791, 'direction' => 'in'];
    if ($present) {
        $detail['company_id'] = $id;
    }
    DB::shouldReceive('transaction')->never();
    expect(fn () => (new ReflectionMethod(SuperPdpInvoiceService::class, 'storeInvoice'))
        ->invoke($this->service, $this->connection, $detail))
        ->toThrow(RuntimeException::class, 'ne correspond pas à l’entreprise de la connexion OAuth');
})->with([
    'foreign company' => [true, 902, 901], 'missing company' => [false, null, 901],
    'null company' => [true, null, 901], 'zero' => [true, 0, 901],
    'negative' => [true, -1, 901], 'numeric string' => [true, '901', 901],
    'float' => [true, 901.0, 901], 'boolean' => [true, true, 901],
    'array' => [true, [], 901], 'unbound connection' => [true, 901, null],
    'invalid connection' => [true, 901, 0],
]);

it('retains the direction guard even when the remote company matches', function () {
    DB::shouldReceive('transaction')->never();
    expect(fn () => (new ReflectionMethod(SuperPdpInvoiceService::class, 'storeInvoice'))
        ->invoke($this->service, $this->connection, ['id' => 2077789, 'company_id' => 901, 'direction' => 'out']))
        ->toThrow(RuntimeException::class, 'Seules les factures entrantes');
});

it('allows the matching remote company to reach the existing import transaction', function () {
    // Stop at the transaction boundary: no SQL, schema, model save or migration.
    DB::shouldReceive('transaction')->once()->andThrow(new DomainException('Approved import boundary reached.'));
    expect(fn () => (new ReflectionMethod(SuperPdpInvoiceService::class, 'storeInvoice'))
        ->invoke($this->service, $this->connection, ['id' => 2077791, 'company_id' => 901, 'direction' => 'in']))
        ->toThrow(DomainException::class, 'Approved import boundary reached.');
});

it('interprets fr205 as accepted and presents the accepted label in Purchases', function () {
    $status = (new ReflectionMethod(SuperPdpInvoiceService::class, 'resolveStatus'))->invoke($this->service, [
        'events' => [['status_code' => 'fr:202'], ['status_code' => 'fr:205']],
    ]);
    $invoice = (new SupplierInvoice)->forceFill(['status' => $status]);
    expect($status)->toBe('accepted')->and($invoice->statusLabel())->toBe('Acceptée');
});

it('automatically selects only connected token-bearing companies with reception enabled without SQL', function (bool $includeEnabled) {
    // Real Eloquent predicates, evaluated over PHP arrays. No PDO, schema or migration.
    $fake = new class extends Connection
    {
        public array $rows = [];

        public function __construct()
        {
            parent::__construct(static fn () => throw new LogicException('PDO forbidden.'));
        }

        public function query()
        {
            return new class($this) extends Builder
            {
                public function get($columns = ['*'])
                {
                    $rows = array_filter($this->connection->rows, function ($row) {
                        foreach ($this->wheres as $where) {
                            $value = $row[$where['column']] ?? null;
                            if ($where['type'] === 'NotNull') {
                                if ($value === null) {
                                    return false;
                                }
                            } elseif ($where['type'] === 'Basic' && $where['operator'] === '=' && $where['boolean'] === 'and') {
                                if ($value === null || $value != $where['value']) {
                                    return false;
                                }
                            } else {
                                throw new LogicException('Unexpected query predicate.');
                            }
                        }

                        return true;
                    });

                    return collect(array_values($rows))->map(fn ($row) => (object) $row);
                }
            };
        }
    };
    $base = ['company_id' => 3, 'superpdp_company_id' => 901, 'status' => 'connected', 'access_token' => 'opaque-offline-fixture'];
    $fake->rows = [
        ['id' => 1, 'reception_enabled' => false] + $base,
        ['id' => 2, 'reception_enabled' => null] + $base,
        ['id' => 3, 'reception_enabled' => true, 'status' => 'disconnected'] + $base,
        ['id' => 4, 'reception_enabled' => true, 'access_token' => null] + $base,
    ];
    if ($includeEnabled) {
        $fake->rows[] = ['id' => 5, 'reception_enabled' => true] + $base;
    }
    $resolver = new ConnectionResolver(['offline' => $fake]);
    $resolver->setDefaultConnection('offline');
    Model::setConnectionResolver($resolver);
    $service = $this->createMock(SuperPdpInvoiceService::class);
    if ($includeEnabled) {
        $service->expects($this->once())->method('synchronize')->with($this->callback(
            fn (SuperPdpConnection $connection) => $connection->id === 5 && $connection->reception_enabled === true))->willReturn(0);
    } else {
        $service->expects($this->never())->method('synchronize');
    }
    app()->instance(SuperPdpInvoiceService::class, $service);
    $this->artisan('superpdp:sync-invoices')->assertExitCode(0);
})->with([false, true]);
