<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/** Eloquent test double: rows live only in PHP arrays, never in a SQL database. */
final class InMemorySuperPdpOAuthDatabase extends Connection
{
    public array $rows = ['companies' => [], 'superpdp_connections' => []];

    public array $transactionAttempts = [];

    public function __construct()
    {
        parent::__construct(static fn () => throw new LogicException('SQL forbidden'));
        $this->beforeExecuting(static fn () => throw new LogicException('SQL forbidden'));
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->transactionAttempts[] = $attempts;
        $snapshot = $this->rows;
        try {
            return $callback($this);
        } catch (Throwable $exception) {
            $this->rows = $snapshot;
            throw $exception;
        }
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        if ($query !== 'SELECT DATABASE() AS selected_database') {
            throw new LogicException('SQL forbidden');
        }

        return (object) ['selected_database' => 'fabyjulien_simpledevis_test'];
    }

    public function query()
    {
        return new class($this) extends Builder
        {
            private function matching(): array
            {
                return array_filter($this->connection->rows[$this->from], fn (array $row) => $this->matches($row, $this->wheres ?? []));
            }

            private function matches(array $row, array $wheres): bool
            {
                foreach ($wheres as $where) {
                    if ($where['type'] === 'NotNull' && $where['boolean'] === 'and') {
                        if (($row[last(explode('.', $where['column']))] ?? null) === null) {
                            return false;
                        }

                        continue;
                    }
                    if ($where['type'] === 'Nested' && $where['boolean'] === 'and') {
                        if (! $this->matches($row, $where['query']->wheres ?? [])) {
                            return false;
                        }

                        continue;
                    }
                    if ($where['type'] === 'InRaw' && $where['boolean'] === 'and') {
                        $column = last(explode('.', $where['column']));
                        if (! in_array($row[$column] ?? null, $where['values'], true)) {
                            return false;
                        }

                        continue;
                    }
                    if ($where['type'] !== 'Basic' || $where['operator'] !== '=' || $where['boolean'] !== 'and') {
                        throw new LogicException('Unsupported in-memory predicate');
                    }
                    $column = last(explode('.', $where['column']));
                    if (($row[$column] ?? null) != $where['value']) {
                        return false;
                    }
                }

                return true;
            }

            public function get($columns = ['*'])
            {
                $rows = array_values($this->matching());
                if ($this->limit !== null) {
                    $rows = array_slice($rows, 0, $this->limit);
                }

                return new Collection(array_map(static fn (array $row) => (object) $row, $rows));
            }

            public function insertGetId(array $values, $sequence = null)
            {
                $id = count($this->connection->rows[$this->from]) + 1;
                $this->connection->rows[$this->from][$id] = ['id' => $id] + $values;

                return $id;
            }

            public function update(array $values)
            {
                $matching = $this->matching();
                foreach ($matching as $id => $row) {
                    $this->connection->rows[$this->from][$id] = array_replace($row, $values);
                }

                return count($matching);
            }
        };
    }
}
