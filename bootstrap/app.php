<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Only an explicitly selected sandbox profile invokes this loader.
$sandboxArgs = $_SERVER['argv'] ?? [];
$sandboxSelected = getenv('APP_ENV') === 'sandbox'
    || ($_ENV['APP_ENV'] ?? null) === 'sandbox' || ($_SERVER['APP_ENV'] ?? null) === 'sandbox'
    || in_array('--env=sandbox', $sandboxArgs, true);
foreach ($sandboxArgs as $sandboxIndex => $sandboxArg) {
    $sandboxSelected = $sandboxSelected || ($sandboxArg === '--env' && ($sandboxArgs[$sandboxIndex + 1] ?? null) === 'sandbox');
}
if ($sandboxSelected) {
    foreach ($sandboxArgs as $sandboxIndex => $sandboxArg) {
        if ((str_starts_with($sandboxArg, '--env=') && $sandboxArg !== '--env=sandbox')
            || ($sandboxArg === '--env' && ($sandboxArgs[$sandboxIndex + 1] ?? null) !== 'sandbox')) {
            throw new RuntimeException('Conflicting sandbox environment selection. Nothing started.');
        }
    }
    $loadSandboxEnvironment = require __DIR__.'/superpdp-sandbox-environment.php';
    $loadSandboxEnvironment();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
