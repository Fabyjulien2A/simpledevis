<?php

use SimpleDevis\Tools\SuperPdp\SandboxInterfaceTrialReadiness;

require_once dirname(__DIR__, 3).'/tools/support/SandboxInterfaceTrialReadiness.php';
require_once dirname(__DIR__, 3).'/tools/check-invoice-schema-test.php';

beforeEach(function () {
    $this->columns = [];
    foreach (SandboxInterfaceTrialReadiness::COLUMNS as $table => $columns) {
        $this->columns[$table] = array_fill_keys($columns, []);
    }
    $this->keys = SandboxInterfaceTrialReadiness::UNIQUE_KEYS;
    $this->engines = array_fill_keys(SandboxInterfaceTrialReadiness::ENGINES, 'InnoDB');
    $this->foreignKeys = [
        ['TABLE_NAME' => 'electronic_invoices', 'COLUMN_NAME' => 'invoice_id', 'REFERENCED_TABLE_NAME' => 'invoices', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
        ['TABLE_NAME' => 'electronic_invoice_attempts', 'COLUMN_NAME' => 'electronic_invoice_id', 'REFERENCED_TABLE_NAME' => 'electronic_invoices', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
        ['TABLE_NAME' => 'electronic_invoice_statuses', 'COLUMN_NAME' => 'electronic_invoice_id', 'REFERENCED_TABLE_NAME' => 'electronic_invoices', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
    ];
    $this->now = strtotime('2026-11-15T12:00:00Z');
    $this->rows = [];
    foreach ([3 => ['Burger Queen', 18172, '000000002'], 4 => ['Tricatel', 18171, '000000001']] as $id => [$name, $provider, $number]) {
        $this->rows[] = ['company_id' => $id, 'user_id' => $id, 'company_name' => $name, 'connection_id' => $id,
            'environment' => 'sandbox', 'oauth_client_id' => 'isolated-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'superpdp_company_id' => $provider, 'directory_identifier' => $number, 'status' => 'connected',
            'has_access_token' => 1, 'has_refresh_token' => 1, 'access_token_expires_at' => '2026-11-15 13:00:00'];
    }
});

it('assesses the schema required for the durable UUID and confirmation without granting permission', function () {
    $result = SandboxInterfaceTrialReadiness::schema($this->columns, $this->keys, $this->engines, $this->foreignKeys);
    expect($result['ready'])->toBeTrue()->and($result)->not->toHaveKey('deposit_allowed');
});

it('refuses incomplete non atomic or destructively cascading registries', function ($case) {
    match ($case) {
        'uuid column' => $this->columns['electronic_invoice_attempts'] = array_diff_key($this->columns['electronic_invoice_attempts'], ['reference' => true]),
        'uuid uniqueness' => $this->keys['electronic_invoice_attempts'] = [['electronic_invoice_id', 'number']],
        'wrong composite order' => $this->keys['electronic_invoice_attempts'] = [['reference'], ['number', 'electronic_invoice_id']],
        'dispatch uniqueness' => $this->keys['electronic_invoices'] = [],
        'engine' => $this->engines['electronic_invoice_attempts'] = 'MyISAM',
        'cascade' => $this->foreignKeys[1]['DELETE_RULE'] = 'CASCADE',
        'wrong target' => $this->foreignKeys[1]['REFERENCED_TABLE_NAME'] = 'invoices',
        'missing FK' => $this->foreignKeys = [],
    };
    expect(SandboxInterfaceTrialReadiness::schema($this->columns, $this->keys, $this->engines, $this->foreignKeys)['ready'])->toBeFalse();
})->with(['uuid column', 'uuid uniqueness', 'wrong composite order', 'dispatch uniqueness', 'engine', 'cascade', 'wrong target', 'missing FK']);

it('reports existing sandbox connections without exposing or renewing credentials', function () {
    $this->rows[0]['access_token'] = 'PRIVATE-CIPHERTEXT';
    $report = SandboxInterfaceTrialReadiness::parties($this->rows, 'isolated-client', $this->now);
    expect(array_column($report, 'local_metadata_ready'))->toBe([true, true])
        ->and(array_column($report, 'refresh_performed'))->toBe([false, false])
        ->and(array_column($report, 'decryption'))->toBe(['not_checked', 'not_checked'])
        ->and(json_encode($report))->not->toContain('PRIVATE-CIPHERTEXT', 'isolated-client');
});

it('fails closed on expired absent foreign or ambiguous local metadata', function ($case) {
    match ($case) {
        'owner' => $this->rows[0]['user_id'] = 4,
        'provider' => $this->rows[0]['superpdp_company_id'] = 18171,
        'identity' => $this->rows[0]['directory_identifier'] = '000000001',
        'environment' => $this->rows[0]['environment'] = 'production',
        'client' => $this->rows[0]['oauth_client_id'] = 'other',
        'url' => $this->rows[0]['api_url'] = 'https://forbidden.invalid',
        'missing access token' => $this->rows[0]['has_access_token'] = 0,
        'expired' => $this->rows[0]['access_token_expires_at'] = '2026-11-15 11:59:59',
        'margin' => $this->rows[0]['access_token_expires_at'] = '2026-11-15 12:05:00',
        'date' => $this->rows[0]['access_token_expires_at'] = 'invalid',
        'missing' => $this->rows = [],
        'duplicate' => $this->rows[] = $this->rows[0],
    };
    $report = SandboxInterfaceTrialReadiness::parties($this->rows, 'isolated-client', $this->now);
    expect($report[0]['local_metadata_ready'])->toBeFalse()->and($report[0]['refresh_performed'])->toBeFalse();
})->with(['owner', 'provider', 'identity', 'environment', 'client', 'url', 'missing access token', 'expired', 'margin', 'date', 'missing', 'duplicate']);

it('executes only metadata SELECT SHOW and a read only transaction ending in rollback', function ($fail) {
    $pdo = new class($fail) extends PDO
    {
        public array $commands = [];

        private bool $active = false;

        public function __construct(private bool $fail) {}

        public function exec(string $statement): int|false
        {
            expect($statement)->toBe('START TRANSACTION READ ONLY');
            $this->commands[] = $statement;
            $this->active = true;

            return 0;
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            expect((bool) preg_match('/\A(?:SELECT|SHOW)\b/', $query))->toBeTrue();
            $this->commands[] = $query;
            if ($this->fail) {
                throw new RuntimeException('isolated failure');
            }

            return new class extends PDOStatement
            {
                public function __construct() {}

                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                {
                    return [];
                }
            };
        }

        public function inTransaction(): bool
        {
            return $this->active;
        }

        public function rollBack(): bool
        {
            $this->commands[] = 'ROLLBACK';
            $this->active = false;

            return true;
        }
    };
    if ($fail) {
        expect(fn () => invoiceInterfaceAudit($pdo, 'test-client'))->toThrow(RuntimeException::class, 'isolated failure');
    } else {
        $report = invoiceInterfaceAudit($pdo, 'test-client');
        expect($report['schema']['ready'])->toBeFalse()->and($report['deposit_allowed'])->toBeFalse()
            ->and($report['schema_changed'])->toBeFalse()->and($report['oauth_changed'])->toBeFalse();
    }
    expect($pdo->commands[0])->toBe('START TRANSACTION READ ONLY')
        ->and($pdo->commands[array_key_last($pdo->commands)])->toBe('ROLLBACK');
})->with([false, true]);
