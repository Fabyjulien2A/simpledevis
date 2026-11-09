<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use RuntimeException;

/** Vraies frontières de COMMIT sur SQLite en mémoire, sans rollback des anciennes migrations. */
trait IsolatedEmissionDatabase
{
    protected function setUpIsolatedEmissionDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Ces tests exigent exclusivement SQLite en mémoire.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }
}
