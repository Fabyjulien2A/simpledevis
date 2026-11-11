<?php

/** Offline configuration inspection only. Never boot Laravel, create PDO, migrate or send HTTP. */
require dirname(__DIR__).'/vendor/autoload.php';

try {
    $root = dirname(__DIR__);
    $primaryHash = hash_file('sha256', $root.'/.env');
    // Deliberately contaminate this checker process to prove the profile overrides it.
    foreach (['APP_ENV' => 'production', 'DB_DATABASE' => 'forbidden_production',
        'DB_USERNAME' => 'forbidden_production_user', 'DB_URL' => 'mysql://forbidden.invalid/production',
        'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $loadProfile = require $root.'/bootstrap/superpdp-sandbox-environment.php';
    $values = $loadProfile();
    $database = require $root.'/config/database.php';
    $services = require $root.'/config/services.php';
    $sql = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.mariadb-test'));
    $primary = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    $expected = 'fabyjulien_simpledevis_test';
    $connection = $database['connections']['superpdp_sandbox'] ?? [];
    $checks = [
        'only_test_connection' => $database['default'] === 'superpdp_sandbox' && array_keys($database['connections']) === ['superpdp_sandbox'],
        'exact_database_and_account' => ($connection['database'] ?? null) === $expected && ($connection['username'] ?? null) === $expected,
        'dedicated_sql_credentials_preserved' => ($connection['password'] ?? null) === ($sql['DB_PASSWORD'] ?? null)
            && ($connection['host'] ?? null) === ($sql['DB_HOST'] ?? null),
        'sql_account_differs_from_primary' => ($connection['username'] ?? null) !== ($primary['DB_USERNAME'] ?? null),
        'no_connection_url_socket_or_replicas' => array_intersect(array_keys($connection), ['url', 'unix_socket', 'read', 'write']) === [],
        'primary_cache_not_used' => $values['APP_CONFIG_CACHE'] === $root.'/bootstrap/cache/superpdp-sandbox-config.php',
        'sandbox_oauth_environment' => $services['superpdp']['environment'] === 'sandbox',
        'official_api_url' => $services['superpdp']['api_url'] === 'https://api.superpdp.tech/v1.beta',
        'local_url_matches_callback' => $values['APP_URL'] === 'http://127.0.0.1:8000'
            && $services['superpdp']['redirect_uri'] === $values['APP_URL'].'/facturation-electronique/callback',
        'oauth_credentials_copied_without_disclosure' => $services['superpdp']['client_id'] === ($primary['SUPERPDP_CLIENT_ID'] ?? null)
            && $services['superpdp']['client_secret'] === ($primary['SUPERPDP_CLIENT_SECRET'] ?? null),
        'separate_session_and_key' => ($values['SESSION_DRIVER'] ?? null) === 'file'
            && ($values['SESSION_COOKIE'] ?? null) === 'simpledevis_sandbox_session' && $values['APP_KEY'] !== ($primary['APP_KEY'] ?? null),
        'primary_env_unchanged' => hash_file('sha256', $root.'/.env') === $primaryHash,
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Offline configuration check failed. Values are not disclosed.');
    }
    $refusals = [];
    foreach (['DB_DATABASE' => 'forbidden_production', 'DB_USERNAME' => 'forbidden_user', 'DB_CONNECTION' => 'mysql',
        'DB_URL' => 'mysql://forbidden.invalid/production', 'DB_SOCKET' => '/tmp/forbidden.sock',
        'DB_HOST' => 'forbidden;dbname=production', 'DB_PORT' => '3306;dbname=production', 'DB_PASSWORD' => ''] as $key => $invalid) {
        putenv($key.'='.$invalid);
        $_ENV[$key] = $_SERVER[$key] = $invalid;
        $refused = false;
        try {
            require $root.'/config/database.php';
        } catch (RuntimeException) {
            $refused = true;
        }
        putenv($key.'='.$values[$key]);
        $_ENV[$key] = $_SERVER[$key] = $values[$key];
        $refusals[$key] = $refused;
    }
    if (in_array(false, $refusals, true)) {
        throw new RuntimeException('A forbidden configuration was not rejected.');
    }
    echo json_encode(['mode' => 'offline_no_laravel_boot_no_pdo_no_http', 'checks' => $checks,
        'invalid_settings_refused' => $refusals, 'database' => $expected,
        'oauth_client_sandbox_registration' => 'not_verified_offline',
        'migration_application_status' => 'not_queried', 'database_grants' => 'not_queried'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Offline sandbox profile inspection refused. No secret values are printed.\n");
    exit(1);
}
