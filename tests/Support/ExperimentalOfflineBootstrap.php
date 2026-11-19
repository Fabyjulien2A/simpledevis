<?php

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Saloon\Config;

// Dedicated invocation only. No full suite, migrations, SQL driver or network sender.
require dirname(__DIR__, 2).'/vendor/autoload.php';

Config::preventStrayRequests();
Config::setSenderResolver(static fn () => throw new RuntimeException('All HTTP senders forbidden in offline trial tests.'));

Model::setConnectionResolver(new class implements ConnectionResolverInterface
{
    public function connection($name = null)
    {
        throw new RuntimeException('SQL forbidden in offline trial tests.');
    }

    public function getDefaultConnection()
    {
        return 'forbidden';
    }

    public function setDefaultConnection($name)
    {
        throw new RuntimeException('SQL forbidden in offline trial tests.');
    }
});
