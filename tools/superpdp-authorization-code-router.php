<?php

declare(strict_types=1);

use SimpleDevis\Tools\SuperPdp\AuthorizationCodeProfile;
use SimpleDevis\Tools\SuperPdp\AuthorizationCodeWeb;
use SimpleDevis\Tools\SuperPdp\OAuthHttpTrace;

// Dedicated PHP development server only. Never loaded from public/index.php.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (): never {
    throw new RuntimeException('diagnostic_runtime_error');
});

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    require __DIR__.'/support/SuperPdpAuthorizationCodeDiagnostic.php';
    require __DIR__.'/support/SuperPdpOAuthHttpTrace.php';
    $capture = getenv('SUPERPDP_OAUTH_DIAGNOSTIC_TRACE') === '1';
    // Observation is the default. Real OAuth requires an explicit process mode
    // in addition to the existing live authorization and fixed sandbox profile.
    $inspectOnly = AuthorizationCodeWeb::inspectionMode(getenv('SUPERPDP_OAUTH_DIAGNOSTIC_MODE') ?: null);
    header('X-SimpleDevis-Diagnostic-Mode: '.($inspectOnly ? 'observe' : 'oauth'));
    $revision = substr(hash('sha256', hash_file('sha256', __FILE__).hash_file('sha256', __DIR__.'/support/SuperPdpAuthorizationCodeDiagnostic.php')
        .hash_file('sha256', __DIR__.'/support/SuperPdpOAuthHttpTrace.php')), 0, 16);
    header('X-SimpleDevis-Diagnostic-Revision: '.$revision);
    if (PHP_SAPI !== 'cli-server'
        || ! AuthorizationCodeWeb::allowed($_SERVER, getenv('SUPERPDP_OAUTH_DIAGNOSTIC_LIVE') === '1')) {
        http_response_code(403);
        echo 'diagnostic_disabled';

        return;
    }
    foreach (AuthorizationCodeWeb::navigationHeaders($_SERVER['REQUEST_URI'] ?? '') as $name => $value) {
        header($name.': '.$value);
    }
    // Fixed file; do not load .env, inherited DB settings or Laravel providers.
    $profile = AuthorizationCodeProfile::fromFile(dirname(__DIR__).'/.env.sandbox');
    ini_set('session.save_handler', 'files');
    session_name('simpledevis_oauth_diagnostic');
    session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'use_trans_sid' => 0,
        'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax',
        'cookie_path' => '/', 'cookie_domain' => '', 'cookie_lifetime' => 0]);
    if (explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0] === '/oauth/callback') {
        // Temporary capture must never exchange a code or contact the provider.
        if ($capture) {
            http_response_code(423);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"local_capture_callback_paused","provider_requests":0}';
            return;
        }
        $callbackSession = $_SESSION;
        unset($_SESSION['pending']);
        // Commit consumption BEFORE HTTP: a crash must not restore a reusable state.
        session_write_close();
        [$status, $headers, $body] = AuthorizationCodeWeb::handle($profile, $_SERVER, $_POST, $callbackSession, true, null, $inspectOnly);
    } else {
        $before = $_SESSION;
        $post = $_POST;
        $trace = null;
        $diagnosticId = null;
        if ($capture) {
            $trace = new OAuthHttpTrace(session_save_path().DIRECTORY_SEPARATOR.'http-trace', time());
            $diagnosticId = $trace->begin($_SERVER, $post, $before, session_id(), $_COOKIE[session_name()] ?? null, $revision, time());
            $post = OAuthHttpTrace::oauthPost($post);
            header('X-SimpleDevis-Diagnostic-Capture: local-only');
            if ($diagnosticId !== null) {
                header('X-SimpleDevis-Diagnostic-Id: '.$diagnosticId);
            }
        }
        [$status, $headers, $body] = AuthorizationCodeWeb::handle($profile, $_SERVER, $post, $_SESSION, true, null, $inspectOnly);
        if ($inspectOnly && explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0] === '/oauth/start') {
            $observation = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $observation['session_active'] = session_status() === PHP_SESSION_ACTIVE;
            $observation['session_cookie_present'] = isset($_COOKIE[session_name()]);
            if (! $observation['session_cookie_present']) {
                $observation['reason'] = 'session_cookie_missing';
            }
            $observation['revision'] = $revision;
            $observation['observed_at'] = gmdate('c');
            $body = json_encode($observation, JSON_THROW_ON_ERROR);
            // Dedicated private session directory configured by the operator.
            // The recorded object contains only the safe whitelist above.
            $trace = session_save_path().DIRECTORY_SEPARATOR.'start-observations.jsonl';
            if (is_link($trace) || (is_file($trace) && filesize($trace) > 65536)
                || file_put_contents($trace, $body."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('safe_observation_unavailable');
            }
        }
        session_write_close(); // Only state/verifier/config fingerprint/CSRF enter the session.
        if ($trace !== null) {
            $event = $trace->finish($_SESSION, OAuthHttpTrace::reason($_SERVER, $_POST, $before, $status));
            if ($status === 200 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '') === '/') {
                $body = OAuthHttpTrace::decorate($body, $diagnosticId, $revision);
            } elseif ($status === 303) {
                // Preserve OAuth validation/state/PKCE, but suppress the external
                // navigation during the explicitly enabled temporary capture.
                $status = 200;
                $headers = ['Content-Type' => 'application/json; charset=utf-8'];
                $body = json_encode(['result' => 'local_capture_complete', 'diagnostic' => $event, 'provider_requests' => 0], JSON_THROW_ON_ERROR);
            } elseif ($status === 403) {
                $report = json_decode($body, true);
                if (is_array($report)) {
                    $report['diagnostic'] = $event;
                    $body = json_encode($report, JSON_THROW_ON_ERROR);
                }
            }
        }
    }
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }
    echo $body;
} catch (Throwable) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if ($capture ?? false) {
            session_abort(); // An instrumentation failure must not erase a form.
        } else {
            $_SESSION = [];
            session_write_close();
        }
    }
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"diagnostic_configuration_or_runtime_refused"}';
}
