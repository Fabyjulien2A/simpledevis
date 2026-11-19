<?php

namespace App\Services\SuperPdp;

use Illuminate\Foundation\Application;
use RuntimeException;

/** Process-local scope, installed after Laravel's framework configuration merge. */
final class SandboxDatabaseIsolation
{
    public const CONNECTION = 'superpdp_sandbox';

    public const DATABASE = 'fabyjulien_simpledevis_test';

    private function __construct(private readonly Application $app, private readonly array $configuration) {}

    public static function install(Application $app): void
    {
        if ($app->bound(self::class)) {
            $app->make(self::class)->assertCurrent();

            return;
        }
        $config = $app['config'];
        $connection = $config->get('database.connections.'.self::CONNECTION);
        if ($config->get('app.env') !== 'sandbox' || $config->get('database.default') !== self::CONNECTION
            || ! is_array($connection) || ($connection['database'] ?? null) !== self::DATABASE
            || ($connection['username'] ?? null) !== self::DATABASE || ($connection['driver'] ?? null) !== 'mysql'
            || array_intersect(array_keys($connection), ['url', 'read', 'write', 'unix_socket']) !== []) {
            throw new RuntimeException('Invalid isolated sandbox SQL configuration.');
        }
        // Do not disable Laravel's global config merge, or change any production file.
        $config->set('database.connections', [self::CONNECTION => $connection]);
        $scope = new self($app, $connection);
        $app->instance(self::class, $scope);
        $app->extend('db', static function ($previous, $app) use ($scope) {
            if ($previous->getConnections() !== []) {
                throw new RuntimeException('Sandbox isolation must precede all connection creation.');
            }

            return new SandboxDatabaseManager($app, $app['db.factory'], $scope);
        });
    }

    /** Also usable by pure in-memory callers; a real sandbox boot installs the manager. */
    public static function assertConfiguredScope(): void
    {
        $connections = config('database.connections');
        if (! app()->environment('sandbox') || config('app.env') !== 'sandbox'
            || config('database.default') !== self::CONNECTION || ! is_array($connections)
            || array_keys($connections) !== [self::CONNECTION]
            || ($connections[self::CONNECTION]['database'] ?? null) !== self::DATABASE) {
            throw new RuntimeException('Only the isolated sandbox database is permitted.');
        }
        if (app()->bound(self::class)) {
            app(self::class)->assertCurrent();
        }
    }

    public function assertCurrent(): void
    {
        $config = $this->app['config'];
        if ($config->get('app.env') !== 'sandbox' || $config->get('database.default') !== self::CONNECTION
            || $config->get('database.connections') !== [self::CONNECTION => $this->configuration]) {
            throw new RuntimeException('Sandbox SQL scope changed during execution.');
        }
    }

    public function configuration(): array
    {
        $this->assertCurrent();

        return $this->configuration;
    }

    public function __debugInfo(): array
    {
        return ['connection' => self::CONNECTION, 'database' => self::DATABASE];
    }
}
