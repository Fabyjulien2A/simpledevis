<?php

declare(strict_types=1);

// Dedicated TEST database only. Never include .env or the older validation runner.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__).'/vendor/autoload.php';

const INVOICE_TEST_DB = 'fabyjulien_simpledevis_test';
const INVOICE_TEST_CONNECTION = 'invoice_schema_test';

function invoiceCheck(bool $condition, string $code): void
{
    if (! $condition) { throw new RuntimeException($code); }
}

function invoiceOutput(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

function invoiceTestPdo(): array
{
    $root = dirname(__DIR__);
    $settings = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.mariadb-test'));
    invoiceCheck(($settings['DB_DATABASE'] ?? null) === INVOICE_TEST_DB && ($settings['DB_USERNAME'] ?? null) === INVOICE_TEST_DB, 'dedicated_config_scope_invalid');
    invoiceCheck(empty($settings['DB_URL']) && empty($settings['DB_SOCKET']), 'dedicated_config_url_or_socket_forbidden');
    foreach (['DB_HOST', 'DB_PASSWORD'] as $key) {
        invoiceCheck(is_string($settings[$key] ?? null) && $settings[$key] !== '' && ! str_contains($settings[$key], '${'), 'dedicated_config_incomplete');
    }
    invoiceCheck(preg_match('/\A[A-Za-z0-9.-]+\z/D', $settings['DB_HOST']) === 1, 'dedicated_host_invalid');
    $port = filter_var($settings['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    invoiceCheck($port !== false, 'dedicated_port_invalid');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_PERSISTENT => false];
    if (! empty($settings['DB_SSL_CA'])) { $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['DB_SSL_CA']; }
    $pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$port.';dbname='.INVOICE_TEST_DB.';charset=utf8mb4', INVOICE_TEST_DB, $settings['DB_PASSWORD'], $options);
    $identity = $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    invoiceCheck($identity['db'] === INVOICE_TEST_DB && explode('@', $identity['account'])[0] === INVOICE_TEST_DB
        && str_contains($identity['version'], 'MariaDB'), 'server_identity_mismatch');
    $grantCount = 0;
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        invoiceCheck(preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $grant, $match) === 1, 'unresolved_grant_or_role');
        $target = str_replace(['`', chr(92)], '', trim($match[2]));
        invoiceCheck((strtoupper(trim($match[1])) === 'USAGE' && $target === '*.*') || $target === INVOICE_TEST_DB.'.*', 'grant_outside_test_database');
        $grantCount++;
    }
    $visible = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    invoiceCheck(array_diff($visible, [INVOICE_TEST_DB, 'information_schema', 'performance_schema']) === [], 'other_database_visible');

    return [$pdo, $settings, ['database' => INVOICE_TEST_DB, 'dedicated_account_confirmed' => true, 'exclusive_grants_confirmed' => true,
        'grant_count' => $grantCount, 'mariadb_version' => $identity['version'], 'production_access' => false], $options];
}

function invoiceSchemaAudit(PDO $pdo): array
{
    $schema = [];
    foreach ($pdo->query('SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $schema[$row['TABLE_NAME']][$row['COLUMN_NAME']] = ['type' => $row['COLUMN_TYPE'], 'nullable' => $row['IS_NULLABLE'] === 'YES'];
    }
    $required = [
        'invoices' => ['id', 'user_id', 'client_id', 'quote_id', 'invoice_number', 'date', 'due_date', 'status', 'document_status',
            'french_business_process_type', 'emission_profile', 'emission_context', 'calculation_version', 'subtotal_ht', 'total_tva', 'total_ttc',
            'amount_paid', 'notes', 'party_snapshot', 'is_electronic', 'electronic_format', 'pdp_id', 'pdp_reference', 'pdp_status', 'xml_generated_at', 'sent_to_pdp_at', 'created_at', 'updated_at'],
        'invoice_items' => ['id', 'invoice_id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht',
            'vat_category', 'exemption_reason_code', 'exemption_reason', 'unit_code', 'created_at', 'updated_at'],
        'invoice_counters' => ['id', 'user_id', 'year', 'last_number', 'created_at', 'updated_at'],
        'companies' => ['id', 'user_id', 'country_code', 'billing_electronic_address', 'vat_regime', 'invoice_recovery_indemnity_text',
            'invoice_late_payment_penalties_text', 'invoice_early_payment_discount_text'],
        'clients' => ['id', 'user_id', 'billing_electronic_address'],
    ];
    $missing = [];
    foreach ($required as $table => $columns) {
        $missing[$table] = array_values(array_diff($columns, array_keys($schema[$table] ?? [])));
    }
    $ran = isset($schema['migrations']) ? $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) : [];
    $files = glob(dirname(__DIR__).'/database/migrations/*.php');
    $names = array_map(static fn ($path) => basename($path, '.php'), $files);
    $indexes = $pdo->query('SHOW INDEX FROM invoices')->fetchAll(PDO::FETCH_ASSOC);
    $unique = [];
    foreach ($indexes as $index) {
        if ((int) $index['Non_unique'] === 0) { $unique[$index['Key_name']][] = $index['Column_name']; }
    }

    return ['required_missing_columns' => $missing, 'invoice_columns' => $schema['invoices'] ?? [],
        'invoice_unique_indexes' => $unique, 'pending_migration_files' => array_values(array_diff($names, $ran)),
        'emission_preparation_migration_recorded' => in_array('2026_11_09_000001_add_invoice_emission_preparation_fields', $ran, true),
        'invoice_number_nullable' => $schema['invoices']['invoice_number']['nullable'] ?? false];
}

/** Keep only hashes in memory; compare old columns even after additive migrations. */
function invoiceHistory(PDO $pdo, ?array $before = null): array
{
    $snapshot = [];
    $tables = $before === null
        ? $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)
        : array_keys($before);
    foreach ($tables as $table) {
        if ($table === 'migrations') { continue; }
        invoiceCheck(preg_match('/\A[a-zA-Z0-9_]+\z/D', $table) === 1, 'unsafe_table_identifier');
        $columns = $before[$table]['columns'] ?? $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column) { invoiceCheck(preg_match('/\A[a-zA-Z0-9_]+\z/D', $column) === 1, 'unsafe_column_identifier'); }
        $statement = $pdo->query('SELECT `'.implode('`,`', $columns).'` FROM `'.$table.'`');
        $hashes = [];
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            $hash = hash('sha256', serialize($row));
            $hashes[$hash] = ($hashes[$hash] ?? 0) + 1;
        }
        if ($before !== null) {
            foreach ($before[$table]['hashes'] as $hash => $count) {
                invoiceCheck(($hashes[$hash] ?? 0) >= $count, 'preexisting_data_changed');
            }
        }
        $snapshot[$table] = ['columns' => $columns, 'hashes' => $hashes];
    }

    return $snapshot;
}

function invoiceTestApplication(PDO $pdo, array $settings): Illuminate\Foundation\Application
{
    invoiceCheck(! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'network_streams_must_be_disabled');
    foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'mail'] as $function) {
        invoiceCheck(! function_exists($function), 'network_functions_must_be_disabled');
    }
    // Never load the main .env, cached production config, external loggers or SQL sessions.
    $env = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => 'http://127.0.0.1', 'APP_CONFIG_CACHE' => dirname(__DIR__).'/tmp/absent-config-'.bin2hex(random_bytes(8)).'.php',
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4', 'NIGHTWATCH_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
        'PULSE_ENABLED' => 'false', 'DB_URL' => '', 'DB_SOCKET' => '', 'DB_CONNECTION' => INVOICE_TEST_CONNECTION];
    foreach ($env as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.mariadb-test');
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($pdo, $settings) {
        $dbConfig = ['driver' => 'mysql', 'host' => $settings['DB_HOST'], 'port' => $settings['DB_PORT'] ?? 3306,
            'database' => INVOICE_TEST_DB, 'username' => INVOICE_TEST_DB, 'password' => $settings['DB_PASSWORD'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        $app['config']->set('database.default', INVOICE_TEST_CONNECTION);
        $app['config']->set('database.connections', [INVOICE_TEST_CONNECTION => $dbConfig]);
        $app['config']->set('database.migrations', ['table' => 'migrations', 'update_date_on_publish' => false]);
        $app['config']->set('app.debug', false);
        $app['config']->set('superpdp_emission.sandbox_enabled', false);
        $app['config']->set('superpdp_emission.authorization', null);
        $app['config']->set('superpdp_preparation.live_enabled', false);
        Illuminate\Database\Connection::resolverFor('mysql', function ($unused, $database, $prefix, $config) use ($pdo, $settings) {
            invoiceCheck($database === INVOICE_TEST_DB && ($config['username'] ?? null) === INVOICE_TEST_DB
                && ($config['host'] ?? null) === $settings['DB_HOST'], 'laravel_database_scope_refused');
            // Reuse the authenticated, rights-checked PDO. No second SQL connection is possible.
            return new Illuminate\Database\MySqlConnection($pdo, INVOICE_TEST_DB, $prefix, $config);
        });
        foreach (['sqlite', 'pgsql', 'sqlsrv', 'mariadb'] as $driver) {
            Illuminate\Database\Connection::resolverFor($driver, static function () { throw new RuntimeException('other_database_driver_refused'); });
        }
    });
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterFacades::class, function () {
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Saloon\Config::preventStrayRequests();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    invoiceCheck(Illuminate\Support\Facades\DB::connection()->getPdo() === $pdo, 'laravel_pdo_mismatch');

    return $app;
}

function invoiceExercise(PDO $pdo, array $settings): array
{
    $initial = invoiceSchemaAudit($pdo);
    invoiceCheck(array_filter($initial['required_missing_columns']) === [], 'test_schema_incomplete');
    $oauthName = '2026_11_10_000001_add_oauth_context_to_superpdp_connections';
    invoiceCheck(array_diff($initial['pending_migration_files'], [$oauthName]) === [], 'unexpected_pending_migrations');
    $history = invoiceHistory($pdo);
    $app = invoiceTestApplication($pdo, $settings);
    $connection = Illuminate\Support\Facades\DB::connection();
    // Session-temporary prefixed tables reproduce the real missing-column bug.
    // Neither real tables nor migration history are altered by this reproduction.
    $prefix = 'ivcheck_'.bin2hex(random_bytes(6)).'_';
    foreach (['companies', 'clients', 'invoices', 'superpdp_connections'] as $table) {
        $pdo->exec('CREATE TEMPORARY TABLE `'.$prefix.$table.'` (id BIGINT PRIMARY KEY, legacy_marker VARCHAR(32))');
        $pdo->exec("INSERT INTO `".$prefix.$table."` (id,legacy_marker) VALUES (1,'unchanged')");
    }
    $reproduced = false;
    try { $pdo->exec('INSERT INTO `'.$prefix.'invoices` (id,emission_profile) VALUES (2,NULL)'); }
    catch (PDOException $exception) { $reproduced = $exception->getCode() === '42S22' && ($exception->errorInfo[1] ?? null) === 1054; }
    invoiceCheck($reproduced, 'missing_column_error_not_reproduced');
    $shadow = new Illuminate\Database\MySqlConnection($pdo, INVOICE_TEST_DB, $prefix, ['driver' => 'mysql']);
    Illuminate\Support\Facades\Schema::swap($shadow->getSchemaBuilder());
    try {
        foreach (['2026_11_09_000001_add_invoice_emission_preparation_fields', $oauthName] as $name) {
            (require dirname(__DIR__).'/database/migrations/'.$name.'.php')->up();
        }
        foreach (['companies' => ['country_code', 'billing_electronic_address'], 'clients' => ['billing_electronic_address'],
            'invoices' => ['emission_profile', 'emission_context'], 'superpdp_connections' => ['environment', 'oauth_client_id', 'api_url']] as $table => $columns) {
            $row = $pdo->query('SELECT * FROM `'.$prefix.$table.'` WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            invoiceCheck($row['legacy_marker'] === 'unchanged', 'temporary_history_changed');
            foreach ($columns as $column) { invoiceCheck(array_key_exists($column, $row) && $row[$column] === null, 'migration_column_invalid'); }
        }
        $pdo->exec('INSERT INTO `'.$prefix.'invoices` (id,emission_profile) VALUES (2,NULL)');
    } finally {
        Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
    }
    $applied = [];
    if (in_array($oauthName, $initial['pending_migration_files'], true)) {
        foreach (['environment', 'oauth_client_id', 'api_url'] as $column) {
            invoiceCheck(! $connection->getSchemaBuilder()->hasColumn('superpdp_connections', $column), 'partial_oauth_migration_refused');
        }
        $migrator = $app->make('migrator');
        $migrator->setConnection(INVOICE_TEST_CONNECTION);
        $applied = array_map(static fn ($file) => basename($file, '.php'),
            $migrator->run([dirname(__DIR__).'/database/migrations/'.$oauthName.'.php']));
        invoiceCheck($applied === [$oauthName], 'unexpected_migration_result');
    }
    $tag = bin2hex(random_bytes(8));
    $user = App\Models\User::create(['name' => 'TEST SCHEMA '.$tag, 'email' => 'schema-'.$tag.'@example.invalid',
        'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 4])]);
    $client = App\Models\Client::create(['user_id' => $user->id, 'first_name' => 'TEST FICTIF '.$tag]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $created = [];
    foreach (['draft', 'issue'] as $action) {
        $request = Illuminate\Http\Request::create('http://127.0.0.1/invoices', 'POST', [
            'action' => $action, 'client_id' => $client->id, 'date' => '2026-11-11', 'due_date' => '2026-12-11',
            'notes' => 'TEST FICTIF SCHEMA '.$tag.' '.$action, 'french_business_process_type' => 'S1',
            'items' => [['description' => 'TEST LOCAL', 'quantity' => '1', 'unit_price_ht' => '100',
                'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable']],
        ]);
        $app->instance('request', $request);
        Illuminate\Support\Facades\Auth::guard()->setUser($user);
        $request->setUserResolver(static fn () => $user);
        $response = $kernel->handle($request);
        invoiceCheck($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location', ''), '/invoices'), 'invoice_post_failed');
        invoiceCheck(! $app['session.store']->has('errors'), 'invoice_validation_failed');
        $invoice = App\Models\Invoice::where('user_id', $user->id)->where('notes', 'TEST FICTIF SCHEMA '.$tag.' '.$action)->sole();
        invoiceCheck($invoice->document_status === ($action === 'issue' ? 'issued' : 'draft')
            && $invoice->subtotal_ht === '100.00' && $invoice->total_tva === '20.00' && $invoice->total_ttc === '120.00'
            && $invoice->emission_profile === null && $invoice->emission_context === null
            && $invoice->calculation_version === 'decimal-v1' && $invoice->items()->count() === 1, 'invoice_persistence_invalid');
        if ($action === 'issue') {
            invoiceCheck(is_string($invoice->invoice_number) && $invoice->invoice_number !== ''
                && ($invoice->party_snapshot['schema_version'] ?? null) === 2, 'invoice_finalization_invalid');
        } else { invoiceCheck($invoice->invoice_number === null && $invoice->party_snapshot === null, 'draft_invalid'); }
        $created[] = ['action' => $action, 'http_status' => 302, 'invoice_id' => $invoice->id, 'totals_verified' => true];
    }
    invoiceHistory($pdo, $history);
    $final = invoiceSchemaAudit($pdo);
    invoiceCheck($final['pending_migration_files'] === [] && array_filter($final['required_missing_columns']) === [], 'final_schema_invalid');

    return ['missing_column_reproduced' => true, 'real_migration_up_verified_on_temporary_tables' => true,
        'historical_rows_unchanged' => true, 'historical_tables_checked' => count($history),
        'historical_rows_checked' => array_sum(array_map(static fn ($table) => array_sum($table['hashes']), $history)),
        'applied_test_migrations' => $applied, 'invoice_posts' => $created, 'fixtures_retained_in_test_only' => true,
        'pending_migrations' => $final['pending_migration_files'], 'superpdp_network_requests' => 0, 'emission_transport_enabled' => false];
}

try {
    invoiceCheck(PHP_SAPI === 'cli', 'cli_only');
    $mode = $argv[1] ?? '--audit';
    invoiceCheck(in_array($mode, ['--audit', '--exercise'], true) && count($argv) <= 2, 'unsupported_mode');
    [$pdo, $settings, $identity] = invoiceTestPdo();
    invoiceOutput(['mode' => $mode, 'isolation' => $identity, 'result' => $mode === '--audit'
        ? invoiceSchemaAudit($pdo) : invoiceExercise($pdo, $settings)]);
} catch (Throwable $exception) {
    $safe = ['status' => 'refused_or_failed', 'error_class' => get_class($exception), 'production_access' => false];
    if ($exception instanceof PDOException) {
        $safe['sqlstate'] = (string) $exception->getCode();
        $safe['driver_code'] = $exception->errorInfo[1] ?? null;
    } elseif ($exception instanceof RuntimeException) {
        $safe['guard'] = preg_match('/\A[a-z_]+\z/D', $exception->getMessage()) ? $exception->getMessage() : 'operation_refused';
    }
    invoiceOutput($safe);
    exit(1);
}
