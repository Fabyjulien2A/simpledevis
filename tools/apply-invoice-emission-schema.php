<?php

declare(strict_types=1);

// Single authorized up(), no Artisan command, application bootstrap or business service.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (): never { throw new RuntimeException('runtime_error'); });

const TARGET_DATABASE = 'fabyjulien_simpledevis';
const TARGET_MIGRATION = '2026_11_09_000001_add_invoice_emission_preparation_fields';
const TARGET_COLUMNS = [
    'companies' => ['country_code' => 'char(2)', 'billing_electronic_address' => 'longtext'],
    'clients' => ['billing_electronic_address' => 'longtext'],
    'invoices' => ['emission_profile' => 'varchar(32)', 'emission_context' => 'longtext'],
];

function ensureInvoiceSchema(bool $condition, string $reason): void
{
    if (! $condition) { throw new RuntimeException($reason); }
}

function safeIdentifier(string $name): string
{
    ensureInvoiceSchema(preg_match('/\A[A-Za-z0-9_]+\z/D', $name) === 1, 'unsafe_identifier');
    return '`'.$name.'`';
}

function rowFingerprints(PDO $pdo, string $table, array $columns): array
{
    $query = $pdo->query('SELECT '.implode(',', array_map('safeIdentifier', $columns)).' FROM '.safeIdentifier($table));
    $hashes = [];
    while ($row = $query->fetch(PDO::FETCH_NUM)) {
        $hash = hash_init('sha256');
        foreach ($row as $value) { hash_update($hash, $value === null ? 'N;' : 'V'.strlen($value).':'.$value.';'); }
        $digest = hash_final($hash);
        $hashes[$digest] = ($hashes[$digest] ?? 0) + 1;
    }
    ksort($hashes);
    return $hashes;
}

function verifyBaseline(PDO $pdo, array $baseline): void
{
    $names = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    sort($names);
    $expectedNames = array_keys($baseline['tables']); sort($expectedNames);
    ensureInvoiceSchema($names === $expectedNames, 'table_inventory_changed');
    foreach ($baseline['tables'] as $table => $saved) {
        $actual = rowFingerprints($pdo, $table, $saved['columns']);
        $expected = $saved['rows']; ksort($expected);
        ensureInvoiceSchema($actual === $expected, 'data_changed_since_verified_backup');
    }
}

function columnMetadata(PDO $pdo): array
{
    $rows = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    $metadata = [];
    foreach ($rows as $row) { $metadata[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row; }
    return $metadata;
}

function indexDefinitions(PDO $pdo): array
{
    return $pdo->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = null;
$advisoryLock = false;
$ddlStarted = false;
$registered = false;
try {
    ensureInvoiceSchema(PHP_SAPI === 'cli' && count($argv) === 3 && in_array($argv[1], ['--plan', '--apply'], true), 'arguments_refused');
    require dirname(__DIR__).'/vendor/autoload.php';
    ensureInvoiceSchema(! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'http_streams_not_disabled');
    foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'mail'] as $function) {
        ensureInvoiceSchema(! function_exists($function), 'network_functions_not_disabled');
    }
    $baselinePath = realpath($argv[2]);
    $privateRoot = realpath((string) getenv('LOCALAPPDATA'));
    ensureInvoiceSchema(is_string($baselinePath) && is_string($privateRoot)
        && str_starts_with(strtolower($baselinePath), strtolower($privateRoot.DIRECTORY_SEPARATOR)), 'private_baseline_scope_refused');
    $baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    $directory = dirname($baselinePath);
    $proof = json_decode(file_get_contents($directory.'/restore-verification.json'), true, 512, JSON_THROW_ON_ERROR);
    $backup = realpath($directory.'/'.$proof['backup_name']);
    ensureInvoiceSchema(is_string($backup) && dirname($backup) === $directory
        && $proof['status'] === 'restore_verified' && $proof['tables_verified'] === 29
        && $proof['temporary_server_stopped'] === true && $proof['temporary_data_removed'] === true
        && hash_file('sha256', $backup) === $proof['backup_sha256']
        && $baseline['backup_sha256'] === $proof['backup_sha256'] && count($baseline['tables']) === 29, 'verified_backup_refused');
    $migrationFile = dirname(__DIR__).'/database/migrations/'.TARGET_MIGRATION.'.php';
    ensureInvoiceSchema(hash_file('sha256', $migrationFile) === $baseline['migration_sha256'], 'migration_file_changed');
    $settings = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
    ensureInvoiceSchema(($settings['DB_DATABASE'] ?? null) === TARGET_DATABASE && ($settings['DB_CONNECTION'] ?? null) === 'mysql'
        && empty($settings['DB_URL']) && empty($settings['DB_SOCKET'])
        && preg_match('/\A[A-Za-z0-9.-]+\z/D', $settings['DB_HOST'] ?? '') === 1, 'database_configuration_refused');
    $port = filter_var($settings['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    ensureInvoiceSchema($port !== false, 'database_port_refused');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_TIMEOUT => 10];
    if (! empty($settings['MYSQL_ATTR_SSL_CA'])) { $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['MYSQL_ATTR_SSL_CA']; }
    $pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$port.';dbname='.TARGET_DATABASE.';charset=utf8mb4',
        $settings['DB_USERNAME'], $settings['DB_PASSWORD'], $options);
    ensureInvoiceSchema($pdo->query('SELECT DATABASE()')->fetchColumn() === TARGET_DATABASE, 'effective_database_refused');
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec('SET SESSION lock_wait_timeout = 10');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    ensureInvoiceSchema((int) $pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND (DB=DATABASE() OR DB IS NULL) AND COMMAND<>'Sleep'")->fetchColumn() === 0, 'other_visible_sql_operation');
    ensureInvoiceSchema((int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() === 0, 'database_event_present');
    $pdo->exec('START TRANSACTION READ ONLY');
    verifyBaseline($pdo, $baseline);
    $migrations = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    ensureInvoiceSchema(! in_array(TARGET_MIGRATION, $migrations, true), 'migration_already_recorded');
    $otherNames = ['2026_11_09_000002_add_outgoing_emission_orchestration', '2026_11_10_000001_add_oauth_context_to_superpdp_connections'];
    foreach ($otherNames as $name) { ensureInvoiceSchema(! in_array($name, $migrations, true), 'other_migration_unexpectedly_applied'); }
    $beforeColumns = columnMetadata($pdo);
    $beforeIndexes = indexDefinitions($pdo);
    foreach (TARGET_COLUMNS as $table => $columns) {
        foreach ($columns as $column => $type) { ensureInvoiceSchema(! isset($beforeColumns[$table][$column]), 'column_already_present'); }
    }
    $connection = new Illuminate\Database\MySqlConnection($pdo, TARGET_DATABASE, '', ['driver' => 'mysql', 'database' => TARGET_DATABASE]);
    Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
    $migration = require $migrationFile;
    $planned = $connection->pretend(static function () use ($migration) { $migration->up(); });
    $expectedSql = [
        'alter table `companies` add `country_code` char(2) null',
        'alter table `companies` add `billing_electronic_address` json null',
        'alter table `clients` add `billing_electronic_address` json null',
        'alter table `invoices` add `emission_profile` varchar(32) null',
        'alter table `invoices` add `emission_context` json null',
    ];
    ensureInvoiceSchema(array_column($planned, 'query') === $expectedSql, 'generated_sql_outside_authorized_scope');
    foreach ($planned as $statement) { ensureInvoiceSchema($statement['bindings'] === [], 'unexpected_ddl_bindings'); }
    $pdo->commit();
    if ($argv[1] === '--plan') {
        echo json_encode(['status' => 'ready', 'database_confirmed' => true, 'backup_and_baseline_match' => true,
            'migration_absent' => true, 'five_columns_absent' => true, 'allowed_ddl_statements' => count($planned),
            'production_writes' => 0], JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }
    ensureInvoiceSchema((string) $pdo->query("SELECT GET_LOCK('simpledevis:invoice-schema:20261109',0)")->fetchColumn() === '1', 'schema_operator_lock_unavailable');
    $advisoryLock = true;
    verifyBaseline($pdo, $baseline);
    $executed = 0;
    $connection->beforeExecuting(static function ($query, $bindings) use (&$executed, $expectedSql): void {
        ensureInvoiceSchema(isset($expectedSql[$executed]) && $query === $expectedSql[$executed] && $bindings === [], 'execution_sql_outside_authorized_scope');
        $executed++;
    });
    $ddlStarted = true;
    $migration->up();
    ensureInvoiceSchema($executed === 5, 'ddl_sequence_incomplete');
    $afterColumns = columnMetadata($pdo);
    ensureInvoiceSchema(indexDefinitions($pdo) === $beforeIndexes, 'preexisting_indexes_changed');
    foreach ($beforeColumns as $table => $columns) {
        foreach ($columns as $column => $metadata) {
            ensureInvoiceSchema(($afterColumns[$table][$column] ?? null) === $metadata, 'preexisting_column_changed');
        }
        $expectedCount = count($columns) + count(TARGET_COLUMNS[$table] ?? []);
        ensureInvoiceSchema(count($afterColumns[$table]) === $expectedCount, 'unexpected_column_count');
    }
    $verified = [];
    foreach (TARGET_COLUMNS as $table => $columns) {
        $ddl = strtolower($pdo->query('SHOW CREATE TABLE '.safeIdentifier($table))->fetch(PDO::FETCH_NUM)[1]);
        foreach ($columns as $column => $type) {
            $metadata = $afterColumns[$table][$column] ?? [];
            ensureInvoiceSchema(($metadata['COLUMN_TYPE'] ?? null) === $type && ($metadata['IS_NULLABLE'] ?? null) === 'YES', 'new_column_definition_invalid');
            ensureInvoiceSchema((int) $pdo->query('SELECT COUNT(*) FROM '.safeIdentifier($table).' WHERE '.safeIdentifier($column).' IS NOT NULL')->fetchColumn() === 0, 'historical_new_column_not_null');
            if ($type === 'longtext') { ensureInvoiceSchema(str_contains($ddl, 'json_valid(`'.$column.'`)'), 'json_constraint_missing'); }
            $verified[] = $table.'.'.$column;
        }
    }
    verifyBaseline($pdo, $baseline);
    $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
    $record = $pdo->prepare('INSERT INTO migrations (migration,batch) VALUES (?,?)');
    $record->execute([TARGET_MIGRATION, $batch]);
    $registered = true;
    $finalMigrations = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $expectedMigrations = [...$migrations, TARGET_MIGRATION]; sort($expectedMigrations);
    ensureInvoiceSchema($finalMigrations === $expectedMigrations, 'migration_registry_mismatch');
    $rowsChecked = 0;
    foreach ($baseline['tables'] as $table => $saved) {
        $actual = rowFingerprints($pdo, $table, $saved['columns']);
        $expected = $saved['rows']; ksort($expected);
        if ($table === 'migrations') {
            foreach ($expected as $hash => $count) { ensureInvoiceSchema(($actual[$hash] ?? 0) === $count, 'old_migration_registry_changed'); }
            ensureInvoiceSchema(array_sum($actual) === array_sum($expected) + 1, 'unexpected_migration_registry_rows');
        } else { ensureInvoiceSchema($actual === $expected, 'post_registration_data_changed'); }
        $rowsChecked += array_sum($expected);
    }
    echo json_encode(['status' => 'applied_and_verified', 'database' => TARGET_DATABASE, 'migration' => TARGET_MIGRATION,
        'verified_new_columns' => $verified, 'preexisting_columns_unchanged' => true, 'preexisting_indexes_unchanged' => true,
        'preexisting_tables_checked' => count($baseline['tables']), 'preexisting_rows_checked' => $rowsChecked,
        'preexisting_data_unchanged' => true, 'invoice_amounts_numbers_lines_counters_unchanged' => true,
        'migration_registry_new_rows' => 1, 'other_pending_migrations' => $otherNames,
        'business_rows_inserted_updated_deleted' => 0, 'superpdp_requests' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    $reason = $exception instanceof PDOException ? 'sql_operation_failed'
        : (preg_match('/\A[a-z_]+\z/D', $exception->getMessage()) ? $exception->getMessage() : 'operation_failed');
    echo json_encode(['status' => 'stopped', 'reason' => $reason, 'schema_changes_started' => $ddlStarted,
        'migration_registered' => $registered, 'automatic_reversal_attempted' => false]), PHP_EOL;
    $failed = true;
} finally {
    if ($advisoryLock && $pdo instanceof PDO) { $pdo->query("SELECT RELEASE_LOCK('simpledevis:invoice-schema:20261109')"); }
}
exit(isset($failed) ? 1 : 0);
