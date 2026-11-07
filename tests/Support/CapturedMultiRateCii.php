<?php

namespace Tests\Support;

use RuntimeException;

/** Actual user-supplied multi-rate CII; bytes must never be regenerated. */
final class CapturedMultiRateCii
{
    public const SHA256 = 'beb602e30ce56d576783e2ac6116a677bf625930baf13590c679408cbb0068d3';

    public const PATH = __DIR__.'/../Feature/SuperPdp/superpdp_multitaux_cii_reponse_2026-11-06.xml';

    public static function xml(): string
    {
        if (! is_file(self::PATH)) {
            throw new RuntimeException('Captured multi-rate CII missing.');
        }
        $xml = file_get_contents(self::PATH);
        if ($xml === false || hash('sha256', $xml) !== self::SHA256) {
            throw new RuntimeException('Captured multi-rate CII changed: review bytes and provenance before updating its hash.');
        }

        return $xml;
    }
}
