<?php

declare(strict_types=1);

// Standalone, read-only local inspection. Never boots Laravel, SQL, OAuth or a sender.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Experimental\TrialArtifactInspection;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            echo "Offline preparation only. No SQL, API, archive write or deposit.\n";
            echo "php tools/prepare-superpdp-sandbox-trial.php --cii=PATH --expected-cii-sha256=SHA256 [--validation-report=PATH]\n";
            exit(0);
        }
        if (! preg_match('/\A--(cii|expected-cii-sha256|validation-report)=(.+)\z/D', $argument, $match)
            || isset($options[$match[1]])) {
            throw new RuntimeException('Arguments refused.');
        }
        $options[$match[1]] = $match[2];
    }
    if ($options === []) {
        echo "Preparation locked: use --help. No SQL, API, archive write or deposit.\n";
        exit(0);
    }
    $read = static function (string $path, int $limit): string {
        if (preg_match('~^[a-z]+://~i', $path) || is_link($path) || ! is_file($path) || filesize($path) > $limit) {
            throw new RuntimeException('Bounded local regular file required.');
        }
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit) {
            throw new RuntimeException('Local file unavailable.');
        }

        return $bytes;
    };
    $report = isset($options['validation-report']) ? EmissionJson::object($read($options['validation-report'], 1048576)) : null;
    $result = TrialArtifactInspection::inspect($read($options['cii'] ?? '', 5242880), $options['expected-cii-sha256'] ?? '', $report);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable) {
    // No paths, raw XML, report content, credentials or exception trace in CLI output.
    fwrite(STDERR, "Offline preparation refused: check CII direction, exact addresses, expected SHA-256 and matching report. No SQL or API operation performed.\n");
    exit(1);
}
