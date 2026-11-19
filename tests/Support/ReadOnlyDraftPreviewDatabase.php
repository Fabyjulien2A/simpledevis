<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LogicException;

/** PHP rows only. Every SQL execution, transaction and write fails before PDO. */
final class ReadOnlyDraftPreviewDatabase extends Connection
{
    public array $rows = [];

    public array $reads = [];

    public function __construct()
    {
        parent::__construct(static fn () => throw new LogicException('PDO forbidden'));
        $this->beforeExecuting(static fn () => throw new LogicException('SQL forbidden'));
    }

    public function query()
    {
        return new class($this) extends Builder
        {
            public function get($columns = ['*'])
            {
                if ($this->lock !== null) {
                    throw new LogicException('Lock forbidden in read-only preview');
                }
                $this->connection->reads[] = $this->from;
                $rows = array_filter($this->connection->rows[$this->from] ?? [], function ($row) {
                    foreach ($this->wheres ?? [] as $where) {
                        if ($where['boolean'] !== 'and') {
                            throw new LogicException('Unsupported predicate');
                        }
                        $value = $row[last(explode('.', $where['column']))] ?? null;
                        $matches = match ($where['type']) {
                            'Basic' => $where['operator'] === '=' ? $value == $where['value']
                                : throw new LogicException('Unsupported operator'),
                            'NotNull' => $value !== null,
                            'Null' => $value === null,
                            'InRaw', 'In' => in_array($value, $where['values'], true),
                            default => throw new LogicException('Unsupported predicate'),
                        };
                        if (! $matches) {
                            return false;
                        }
                    }

                    return true;
                });
                foreach (array_reverse($this->orders ?? []) as $order) {
                    usort($rows, fn ($a, $b) => ($order['direction'] === 'asc' ? 1 : -1)
                        * (($a[$order['column']] ?? null) <=> ($b[$order['column']] ?? null)));
                }
                if ($this->limit !== null) {
                    $rows = array_slice($rows, 0, $this->limit);
                }

                return new Collection(array_map(static fn ($row) => (object) $row, array_values($rows)));
            }

            public function exists()
            {
                return $this->get()->isNotEmpty();
            }

            public function pluck($column, $key = null)
            {
                return $this->get()->pluck($column, $key);
            }

            public function aggregate($function, $columns = ['*'])
            {
                if ($function !== 'sum') {
                    throw new LogicException('Unsupported aggregate');
                }

                return $this->get()->sum($columns[0]);
            }
        };
    }
}
