<?php

declare(strict_types=1);

use SimpleDevis\Tools\SuperPdp\AuthorizationCodeProfile;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (): never {
    throw new RuntimeException('diagnostic_runtime_error');
});

try {
    if (PHP_SAPI !== 'cli' || ! in_array(array_slice($argv, 1), [[], ['--check']], true)) {
        throw new RuntimeException('arguments_invalid');
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    require __DIR__.'/support/SuperPdpAuthorizationCodeDiagnostic.php';
    AuthorizationCodeProfile::fromFile(dirname(__DIR__).'/.env.sandbox');
    echo json_encode(['mode' => 'offline', 'configuration' => 'passed', 'requests' => 0,
        'callback_uri' => AuthorizationCodeProfile::CALLBACK, 'company_number_scheme' => 'sandbox',
        'company_number' => '000000002', 'provider_configuration' => 'not_checked'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable) {
    echo '{"mode":"offline","configuration":"refused","requests":0}', PHP_EOL;
    exit(1);
}
