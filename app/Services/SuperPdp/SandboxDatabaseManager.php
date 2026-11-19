<?php

namespace App\Services\SuperPdp;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

use function Illuminate\Support\enum_value;

/** Fail closed for cached queries, reconnects, dynamic connections and config mutation. */
final class SandboxDatabaseManager extends DatabaseManager
{
    public function __construct($app, $factory, private readonly SandboxDatabaseIsolation $scope)
    {
        parent::__construct($app, $factory);
    }

    private function assertName($name): void
    {
        $this->scope->assertCurrent();
        if ($name !== null && enum_value($name) !== SandboxDatabaseIsolation::CONNECTION) {
            throw new RuntimeException('Connection outside sandbox SQL scope.');
        }
    }

    public function connection($name = null)
    {
        $this->assertName($name);

        return parent::connection(SandboxDatabaseIsolation::CONNECTION);
    }

    protected function configuration($name)
    {
        $this->assertName($name);

        return $this->scope->configuration();
    }

    protected function configure(Connection $connection, $type)
    {
        $connection = parent::configure($connection, $type);
        $connection->beforeExecuting(fn () => $this->scope->assertCurrent());

        return $connection;
    }

    public function reconnect($name = null)
    {
        $this->assertName($name);

        return parent::reconnect(SandboxDatabaseIsolation::CONNECTION);
    }

    public function setDefaultConnection($name)
    {
        $this->assertName($name);
        parent::setDefaultConnection(SandboxDatabaseIsolation::CONNECTION);
    }

    public function build(array $config)
    {
        throw new RuntimeException('Dynamic sandbox SQL connections are forbidden.');
    }

    public function connectUsing(string $name, array $config, bool $force = false)
    {
        throw new RuntimeException('Alternate sandbox SQL configurations are forbidden.');
    }
}
