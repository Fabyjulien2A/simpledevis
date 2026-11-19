<?php

use App\Services\SuperPdp\SandboxDatabaseManager;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('isolates a real sandbox bootstrap after Laravel merges its default connections and remains isolated', function () {
    $script = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$_SERVER['argv'] = ['offline-bootstrap-check', '--env=sandbox'];
$app = require getcwd().'/bootstrap/app.php';
$app->bind(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, fn () => new class extends Illuminate\Foundation\Bootstrap\LoadConfiguration {
    public function bootstrap(Illuminate\Contracts\Foundation\Application $app) {
        parent::bootstrap($app);
        echo json_encode(['merged_names' => array_keys($app['config']->get('database.connections'))]), PHP_EOL;
    }
});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$manager = $app['db'];
$connection = $manager->connection(); // Lazy PDO resolver only: never open a SQL socket.
$snapshot = config('database');
$refused = [];
$deny = function ($key, $action) use (&$refused) {
    try { $action(); $refused[$key] = false; }
    catch (RuntimeException $e) { $refused[$key] = true; }
};
foreach (['mysql','mariadb','pgsql','sqlite','sqlsrv'] as $name) {
    $deny($name, fn () => $manager->connection($name));
}
$deny('model_alternate_connection', fn () => App\Models\Company::on('mysql')->first());
$deny('dynamic', fn () => $manager->build(['driver'=>'mysql','database'=>'forbidden']));
$deny('connectUsing', fn () => $manager->connectUsing('mysql', ['driver'=>'mysql','database'=>'forbidden']));
$deny('reconnect', fn () => $manager->reconnect('mysql'));
$deny('default_switch', fn () => $manager->setDefaultConnection('mysql'));
config(['database.default'=>'mysql']);
$deny('cached_query_changed_default', fn () => $connection->select('SELECT 1'));
config(['database'=>$snapshot]);
config(['database.connections.mysql'=>['driver'=>'mysql','database'=>'forbidden']]);
$deny('reintroduced_connection', fn () => $manager->connection('mysql'));
$deny('cached_query_extra_connection', fn () => $connection->select('SELECT 1'));
config(['database'=>$snapshot]);
config(['database.connections.superpdp_sandbox.database'=>'forbidden']);
$deny('cached_query_changed_database', fn () => $connection->select('SELECT 1'));
config(['database'=>$snapshot]);
config(['database.connections.superpdp_sandbox.host'=>'forbidden.invalid']);
$deny('changed_host', fn () => $manager->connection());
config(['database'=>$snapshot]);
$company=(new App\Models\Company)->forceFill(['id'=>3,'user_id'=>3,'company_name'=>'Burger Queen','siret'=>null]);
$company->exists=true;
auth()->setUser((new App\Models\User)->forceFill(['id'=>3]));
$context=App\Services\SuperPdp\Emission\SandboxEmissionContext::forCompany($company);
echo json_encode(['guarded_manager'=>$manager instanceof App\Services\SuperPdp\SandboxDatabaseManager,
    'model_resolver_guarded'=>Illuminate\Database\Eloquent\Model::getConnectionResolver()===$manager,
    'active_names'=>array_keys(config('database.connections')),
    'default'=>config('database.default'),'cached_names'=>array_keys($manager->getConnections()),
    'pdo_unopened'=>$connection->getRawPdo() instanceof Closure,
    'context_ready_for_emission'=>$context->toArray()['ready_for_emission'],
    'refused'=>$refused]),PHP_EOL;
PHP;
    $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d',
        'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect',
        '-d', 'disable_classes=PDO,mysqli,SQLite3'], base_path());
    $process->setInput($script)->run();
    expect($process->getExitCode())->toBe(0);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    expect($lines)->toHaveCount(2);
    $before = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    $after = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
    expect($before['merged_names'])->toContain('mysql', 'sqlite', 'superpdp_sandbox')
        ->and($after['guarded_manager'])->toBeTrue()->and($after['model_resolver_guarded'])->toBeTrue()
        ->and($after['active_names'])->toBe(['superpdp_sandbox'])->and($after['default'])->toBe('superpdp_sandbox')
        ->and($after['cached_names'])->toBe(['superpdp_sandbox'])->and($after['pdo_unopened'])->toBeTrue()
        ->and($after['context_ready_for_emission'])->toBeFalse()
        ->and(array_unique(array_values($after['refused'])))->toBe([true]);
});

it('preserves the normal Laravel database manager outside the explicit sandbox bootstrap', function () {
    expect(app('db'))->not->toBeInstanceOf(SandboxDatabaseManager::class)
        ->and(array_keys(config('database.connections')))->toContain('mysql', 'sqlite');
});
