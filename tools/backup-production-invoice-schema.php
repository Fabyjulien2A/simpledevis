<?php

declare(strict_types=1);

// Backup and read-only preflight only. No Laravel boot and no migration execution.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (): never { throw new RuntimeException('runtime_error'); });

function backupAssert(bool $condition, string $code): void
{
    if (! $condition) { throw new RuntimeException($code); }
}

$optionFile = null;
$failed = false;
try {
    backupAssert(PHP_SAPI === 'cli' && count($argv) === 2, 'arguments_refused');
    require dirname(__DIR__).'/vendor/autoload.php';
    $directory = realpath($argv[1]);
    $privateRoot = realpath((string) getenv('LOCALAPPDATA'));
    backupAssert(is_string($directory) && is_string($privateRoot)
        && str_starts_with(strtolower($directory), strtolower($privateRoot.DIRECTORY_SEPARATOR))
        && ! str_contains(strtolower($directory), 'onedrive') && is_writable($directory), 'private_directory_refused');
    $settings = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
    backupAssert(($settings['DB_DATABASE'] ?? null) === 'fabyjulien_simpledevis'
        && ($settings['DB_CONNECTION'] ?? null) === 'mysql'
        && empty($settings['DB_URL']) && empty($settings['DB_SOCKET'])
        && preg_match('/\A[A-Za-z0-9.-]+\z/D', $settings['DB_HOST'] ?? '') === 1
        && ! empty($settings['DB_USERNAME']) && ! empty($settings['DB_PASSWORD']), 'database_config_refused');
    $port = filter_var($settings['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    backupAssert($port !== false, 'database_port_refused');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10];
    if (! empty($settings['MYSQL_ATTR_SSL_CA'])) { $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['MYSQL_ATTR_SSL_CA']; }
    $pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$port.';dbname=fabyjulien_simpledevis;charset=utf8mb4',
        $settings['DB_USERNAME'], $settings['DB_PASSWORD'], $options);
    $pdo->exec('START TRANSACTION READ ONLY');
    backupAssert($pdo->query('SELECT DATABASE()')->fetchColumn() === 'fabyjulien_simpledevis', 'effective_database_refused');
    $name = '2026_11_09_000001_add_invoice_emission_preparation_fields';
    $query = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration=?');
    $query->execute([$name]);
    backupAssert((int) $query->fetchColumn() === 0, 'migration_already_recorded');
    foreach (['companies' => ['country_code', 'billing_electronic_address'], 'clients' => ['billing_electronic_address'],
        'invoices' => ['emission_profile', 'emission_context']] as $table => $columns) {
        foreach ($columns as $column) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $query->execute([$table, $column]);
            backupAssert((int) $query->fetchColumn() === 0, 'partial_migration_refused');
        }
    }
    $tables = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    backupAssert($tables !== [], 'empty_schema_refused');
    foreach ($tables as $table) {
        backupAssert($table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] === 'InnoDB', 'non_transactional_table_refused');
    }
    $counts = [];
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $type => $field) {
        $counts[$type] = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.'.$type.' WHERE '.$field.'=DATABASE()')->fetchColumn();
    }
    $optionFile = $directory.DIRECTORY_SEPARATOR.'temporary-client-'.bin2hex(random_bytes(8)).'.cnf';
    $quote = static fn (string $value): string => '"'.str_replace(["\\", '"', "\n", "\r", "\t"], ["\\\\", '\\"', '\\n', '\\r', '\\t'], $value).'"';
    $client = "[client]\nhost=".$quote($settings['DB_HOST'])."\nport=".$port."\nuser=".$quote($settings['DB_USERNAME'])."\npassword=".$quote($settings['DB_PASSWORD'])."\n";
    if (! empty($settings['MYSQL_ATTR_SSL_CA'])) { $client .= 'ssl-ca='.$quote($settings['MYSQL_ATTR_SSL_CA'])."\n"; }
    $handle = fopen($optionFile, 'x');
    backupAssert(is_resource($handle) && fwrite($handle, $client) === strlen($client), 'private_options_write_failed');
    fclose($handle);
    $dump = $directory.DIRECTORY_SEPARATOR.'before-invoice-emission-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
    $process = proc_open(['C:\\xampp\\mysql\\bin\\mysqldump.exe', '--defaults-extra-file='.$optionFile,
        '--single-transaction', '--skip-lock-tables', '--routines', '--events', '--triggers', '--hex-blob',
        '--default-character-set=utf8mb4', '--databases', 'fabyjulien_simpledevis', '--result-file='.$dump],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    backupAssert(is_resource($process), 'dump_process_failed');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    backupAssert($exitCode === 0, 'dump_command_failed');
    backupAssert($stderr === '' && $stdout === '', 'dump_warnings_refused');
    $sql = file_get_contents($dump);
    backupAssert(is_string($sql) && strlen($sql) > 100 && str_contains($sql, '-- Dump completed on ')
        && str_contains($sql, 'USE `fabyjulien_simpledevis`;'), 'dump_incomplete');
    foreach ($tables as $table) {
        backupAssert(preg_match('/\A[a-zA-Z0-9_]+\z/D', $table['TABLE_NAME']) === 1, 'table_name_refused');
        $marker = $table['TABLE_TYPE'] === 'BASE TABLE' ? 'CREATE TABLE `'.$table['TABLE_NAME'].'`' : 'VIEW `'.$table['TABLE_NAME'].'`';
        backupAssert(str_contains($sql, $marker), 'dump_schema_object_missing');
    }
    backupAssert(hash('sha256', $sql) === hash_file('sha256', $dump), 'dump_hash_mismatch');
    echo json_encode(['status' => 'backup_created_structurally_checked', 'database_confirmed' => true,
        'migration_absent' => true, 'five_columns_absent' => true, 'production_writes' => false,
        'backup_path' => $dump, 'backup_bytes' => strlen($sql), 'sha256' => hash('sha256', $sql),
        'tables_and_views_checked' => count($tables), 'additional_objects_in_source' => $counts,
        'native_dump_exit_code' => $exitCode, 'warnings' => false,
        'restore_test_performed' => false, 'migration_applied' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    $reason = $exception instanceof PDOException ? 'sql_read_or_connection_failed'
        : (preg_match('/\A[a-z_]+\z/D', $exception->getMessage()) ? $exception->getMessage() : 'operation_failed');
    echo json_encode(['status' => 'stopped', 'reason' => $reason, 'production_writes' => false, 'migration_applied' => false]), PHP_EOL;
    $failed = true;
} finally {
    // The generated file is under the validated private directory; never delete the backup.
    if (is_string($optionFile) && is_file($optionFile)) { unlink($optionFile); }
}
exit($failed ? 1 : 0);
