<?php

declare(strict_types=1);
use SimpleDevis\Tools\SuperPdp\ClientCredentialsDiagnostic;
use SimpleDevis\Tools\SuperPdp\DiagnosticProfile;

// CLI only. Disable PHP exception argument/error logging before reading credentials.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (): never {
    throw new RuntimeException('diagnostic_runtime_error');
});

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }
    $args = array_slice($argv, 1);
    if (count($args) !== count(array_unique($args))
        || array_diff($args, ['--check', '--execute', '--confirm-sandbox-account']) !== []
        || (in_array('--check', $args, true) && count($args) > 1)
        || (in_array('--confirm-sandbox-account', $args, true) && ! in_array('--execute', $args, true))) {
        throw new RuntimeException('arguments_invalid');
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    require __DIR__.'/support/SuperPdpClientCredentialsDiagnostic.php';
    $profile = DiagnosticProfile::fromFile(dirname(__DIR__).'/.env.sandbox');
    $live = in_array('--execute', $args, true);
    $result = $live
        ? ClientCredentialsDiagnostic::live($profile, in_array('--confirm-sandbox-account', $args, true))
        : ClientCredentialsDiagnostic::offline($profile);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($result['error'] === null ? 0 : 1);
} catch (Throwable) {
    echo '{"mode":"offline","authentication":"not_attempted","sandbox_environment":"not_checked","error":"configuration_or_arguments_refused","requests":0}', PHP_EOL;
    exit(1);
}
