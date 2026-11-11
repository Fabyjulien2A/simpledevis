<?php

declare(strict_types=1);

use SimpleDevis\Tools\SuperPdp\CiiDiagnostic;
use SimpleDevis\Tools\SuperPdp\CiiProbeFailure;
use SimpleDevis\Tools\SuperPdp\CiiProbeProfile;
use SimpleDevis\Tools\SuperPdp\CiiProbeStorage;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (int $severity): bool {
    // Existing dependency deprecations must not leak diagnostics or block a test.
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return true;
    }
    throw new ErrorException('diagnostic_runtime_warning', 0, $severity);
});

$phase = 'arguments';
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }
    $args = array_slice($argv, 1);
    $scenario = in_array('--business-fixture', $args, true) ? 'business' : 'technical';
    $operationArgs = array_values(array_diff($args, ['--business-fixture']));
    if (count($args) !== count(array_unique($args)) || array_diff($args, ['--check', '--execute', '--confirm-fictional-sandbox', '--business-fixture']) !== []
        || (in_array('--check', $args, true) && count($operationArgs) !== 1)
        || (in_array('--execute', $args, true) !== in_array('--confirm-fictional-sandbox', $args, true))) {
        throw new RuntimeException('explicit_confirmation_or_valid_arguments_required');
    }
    $phase = 'dependency_loading';
    if (! is_file(dirname(__DIR__).'/vendor/autoload.php')) {
        throw new RuntimeException('dependency_autoload_missing');
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    if (! is_file(__DIR__.'/support/SuperPdpCiiDiagnostic.php')) {
        throw new RuntimeException('dependency_support_missing');
    }
    require __DIR__.'/support/SuperPdpCiiDiagnostic.php';
    $phase = 'configuration';
    foreach (['dom', 'libxml', 'mbstring'] as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException('php_required_extension_missing');
        }
    }
    $profile = CiiProbeProfile::fromFile(dirname(__DIR__).'/.env.sandbox');
    $phase = 'local_validation';
    $checked = CiiDiagnostic::check($scenario);
    $result = $checked;
    if (in_array('--execute', $args, true)) {
        $phase = 'private_storage';
        if (! function_exists('proc_open')) {
            throw new RuntimeException('private_storage_process_unavailable');
        }
        $storage = CiiProbeStorage::privateDefault();
        $phase = 'transport_setup';
        $result = CiiDiagnostic::live($profile, $storage, explicitlyApproved: true, scenario: $scenario);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(isset($result['error']) ? 1 : 0);
} catch (Throwable $exception) {
    // Only fixed codes are emitted. Never output exception messages, arguments,
    // filenames, subprocess stderr or traces, even for dependency/runtime errors.
    $known = [
        'cli_only', 'explicit_confirmation_or_valid_arguments_required', 'dependency_autoload_missing', 'dependency_support_missing',
        'php_required_extension_missing', 'profile_refused', 'local_contract_changed', 'database_forbidden',
        'persisted_fixture_forbidden', 'fixture_scenario_refused', 'private_windows_storage_required', 'private_storage_refused',
        'private_storage_path_refused', 'private_storage_create_failed', 'private_storage_acl_failed',
        'private_storage_acl_privilege_required', 'private_storage_permission_denied', 'private_storage_process_failed',
        'private_storage_process_unavailable', 'storage_write_failed', 'storage_scope_refused',
    ];
    $candidate = $exception instanceof CiiProbeFailure ? $exception->reason
        : ($exception::class === RuntimeException::class ? $exception->getMessage() : null);
    $error = in_array($candidate, $known, true) ? $candidate : match (true) {
        $exception instanceof ParseError => 'php_dependency_parse_error',
        $exception instanceof TypeError => 'php_type_error',
        $exception instanceof ErrorException => 'php_runtime_warning',
        $exception instanceof Error && preg_match('/^Class ".*" not found$/D', $exception->getMessage()) === 1 => 'php_class_unavailable',
        $exception instanceof Error => 'php_dependency_or_execution_error',
        $phase === 'local_validation' => 'local_validation_failed',
        default => 'local_execution_failed',
    };
    $category = match (true) {
        $exception instanceof ErrorException, $exception instanceof TypeError => 'php_execution',
        $exception instanceof Error => 'dependency_or_php_execution',
        str_starts_with($error, 'private_storage_'), $error === 'private_windows_storage_required' => 'storage_permissions',
        $phase === 'private_storage' => 'storage_permissions',
        default => $phase,
    };
    echo json_encode(['mode' => 'offline', 'error' => $error, 'error_category' => $category, 'phase' => $phase,
        'requests' => 0, 'ready_for_emission' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
