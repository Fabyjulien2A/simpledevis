<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Throwable;

/** PHP arrays only; neither PDO nor migrations are available. */
final class TricatelCreationDatabase extends Connection
{
    public array $rows = [
        'users' => [3 => ['id' => 3, 'name' => 'Burger Queen owner', 'email' => 'burger@sandbox.invalid']],
        'companies' => [3 => ['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]],
        'superpdp_connections' => [1 => ['id' => 1, 'company_id' => 3]],
        'supplier_invoices' => [],
    ];

    public array $operations = [];

    public string $selectedDatabase = 'fabyjulien_simpledevis_test';

    public string $authenticatedUser = 'fabyjulien_simpledevis_test@localhost';

    public bool $exclusive = true;

    public bool $failCompany = false;

    public string $engine = 'InnoDB';

    public bool $missingColumn = false;

    public bool $legalNullable = true;

    public bool $uniqueEmail = true;

    public bool $ownerForeignKey = true;

    public bool $failConnection = false;

    public array $grants = ['GRANT USAGE ON *.* TO test_user', 'GRANT ALL PRIVILEGES ON `fabyjulien_simpledevis_test`.* TO test_user'];

    public function __construct()
    {
        parent::__construct(static fn () => throw new LogicException('PDO forbidden.'), 'fabyjulien_simpledevis_test', '', [
            'name' => 'superpdp_sandbox', 'driver' => 'mysql', 'username' => 'fabyjulien_simpledevis_test',
        ]);
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $this->operations[] = $query;
        if ($this->failConnection) {
            throw new \PDOException('PRIVATE_ACCOUNT PRIVATE_HASH', 2002);
        }

        return match ($query) {
            'SELECT DATABASE() AS selected_database, CURRENT_USER() AS authenticated_user' => (object) ['selected_database' => $this->selectedDatabase, 'authenticated_user' => $this->authenticatedUser],
            'SELECT GET_LOCK(?, 0) AS acquired' => (object) ['acquired' => $this->exclusive ? 1 : 0],
            'SELECT RELEASE_LOCK(?) AS released' => (object) ['released' => 1],
            default => throw new LogicException('Unsupported fake query.'),
        };
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $this->operations[] = $query;
        if ($query === 'SHOW GRANTS FOR CURRENT_USER') {
            return array_map(fn ($grant) => (object) ['grant' => $grant], $this->grants);
        }
        if (str_starts_with($query, 'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES')) {
            return [(object) ['table_name' => 'users', 'engine' => $this->engine], (object) ['table_name' => 'companies', 'engine' => $this->engine]];
        }
        if (str_starts_with($query, 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name')) {
            $rows = [];
            foreach (['users' => ['id', 'name', 'email', 'password', 'created_at', 'updated_at'],
                'companies' => ['id', 'user_id', 'company_name', 'country_code', 'siret', 'vat_number', 'tva_number', 'legal_status', 'iban', 'bic', 'billing_electronic_address', 'created_at', 'updated_at']] as $table => $columns) {
                foreach ($columns as $column) {
                    if ($this->missingColumn && $column === 'country_code') {
                        continue;
                    }
                    $rows[] = (object) ['table_name' => $table, 'column_name' => $column,
                        'is_nullable' => $column === 'siret' && ! $this->legalNullable ? 'NO' : 'YES'];
                }
            }

            return $rows;
        }
        if (str_starts_with($query, 'SELECT INDEX_NAME AS index_name')) {
            return $this->uniqueEmail ? [(object) ['index_name' => 'users_email_unique', 'column_name' => 'email', 'non_unique' => 0]] : [];
        }
        if (str_starts_with($query, 'SELECT COLUMN_NAME AS column_name, REFERENCED_TABLE_SCHEMA')) {
            return $this->ownerForeignKey ? [(object) ['column_name' => 'user_id',
                'referenced_schema' => 'fabyjulien_simpledevis_test', 'referenced_table' => 'users', 'referenced_column' => 'id']] : [];
        }
        throw new LogicException('Unsupported fake query.');
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $snapshot = $this->rows;
        $this->operations[] = 'begin';
        try {
            $result = $callback($this);
            $this->operations[] = 'commit';

            return $result;
        } catch (Throwable $exception) {
            $this->rows = $snapshot;
            $this->operations[] = 'rollback';
            throw $exception;
        }
    }

    public function query()
    {
        return new class($this) extends Builder
        {
            public function get($columns = ['*'])
            {
                if (! in_array($this->from, ['users', 'companies'], true)) {
                    throw new LogicException('Unexpected table.');
                }
                $rows = array_filter($this->connection->rows[$this->from], function ($row) {
                    foreach ($this->wheres ?? [] as $where) {
                        if ($where['type'] !== 'Basic' || $where['operator'] !== '=') {
                            throw new LogicException('Unsupported predicate.');
                        }
                        $column = last(explode('.', $where['column']));
                        if (($row[$column] ?? null) != $where['value']) {
                            return false;
                        }
                    }

                    return true;
                });
                if ($this->limit !== null) {
                    $rows = array_slice($rows, 0, $this->limit);
                }

                return collect(array_values($rows))->map(fn ($row) => (object) $row);
            }

            public function insertGetId(array $values, $sequence = null)
            {
                if (! in_array($this->from, ['users', 'companies'], true)) {
                    throw new LogicException('Unexpected write table.');
                }
                if ($this->from === 'companies' && $this->connection->failCompany) {
                    throw new LogicException('Sensitive SQL with fixture email/password must not be logged.');
                }
                $id = max(array_keys($this->connection->rows[$this->from])) + 1;
                $this->connection->rows[$this->from][$id] = ['id' => $id] + $values;
                $this->connection->operations[] = 'insert '.$this->from;

                return $id;
            }
        };
    }
}
