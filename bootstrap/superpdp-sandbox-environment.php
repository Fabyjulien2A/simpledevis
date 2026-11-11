<?php

/** Explicit profile loader. No application boot, database, network or file writes. */
return static function (): array {
    $root = dirname(__DIR__);
    $profile = $root.'/.env.sandbox';
    if (! is_file($profile)) {
        throw new RuntimeException('Missing .env.sandbox. Refusing to fall back to the primary environment.');
    }
    try {
        $values = Dotenv\Dotenv::parse(file_get_contents($profile));
    } catch (Throwable) {
        throw new RuntimeException('Invalid sandbox profile. Values are not logged.');
    }
    $required = [
        'APP_ENV' => 'sandbox', 'APP_URL' => 'http://127.0.0.1:8000',
        'DB_CONNECTION' => 'superpdp_sandbox', 'DB_DATABASE' => 'fabyjulien_simpledevis_test',
        'DB_USERNAME' => 'fabyjulien_simpledevis_test', 'DB_URL' => '', 'DB_SOCKET' => '',
        'SUPERPDP_ENVIRONMENT' => 'sandbox', 'SUPERPDP_API_URL' => 'https://api.superpdp.tech/v1.beta',
        'SUPERPDP_REDIRECT_URI' => 'http://127.0.0.1:8000/facturation-electronique/callback',
    ];
    foreach ($required as $key => $expected) {
        if (($values[$key] ?? null) !== $expected) {
            throw new RuntimeException('Sandbox profile scope mismatch. Refusing to start.');
        }
    }
    foreach (['DB_HOST', 'DB_PASSWORD', 'APP_KEY', 'SUPERPDP_CLIENT_ID', 'SUPERPDP_CLIENT_SECRET'] as $key) {
        if (! is_string($values[$key] ?? null) || $values[$key] === '' || str_contains($values[$key], '${')) {
            throw new RuntimeException('Sandbox profile incomplete or interpolated. Values are not logged.');
        }
    }
    foreach (['APP_CONFIG_CACHE' => 'superpdp-sandbox-config.php', 'APP_ROUTES_CACHE' => 'superpdp-sandbox-routes.php'] as $key => $file) {
        $path = $root.'/bootstrap/cache/'.$file;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Sandbox cache must be absent for this initial trial. Primary cache will not be reused.');
        }
        $values[$key] = $path;
    }
    // Force this complete profile before Laravel can consult its cache or dotenv.
    // Inherited DB_*, APP_ENV and OAuth settings cannot select the primary database.
    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    return $values;
};
