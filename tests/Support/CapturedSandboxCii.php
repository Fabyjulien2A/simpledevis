<?php

namespace Tests\Support;

use RuntimeException;

/** User-supplied response from the second accepted conversion; never regenerated. */
final class CapturedSandboxCii
{
    public const SHA256 = '3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe';

    public const PATH = __DIR__.'/../Feature/SuperPdp/superpdp_conversion_cii_fictif_2026-11-06.xml';

    public static function xml(): string
    {
        $xml = file_get_contents(self::PATH);
        if ($xml === false || hash('sha256', $xml) !== self::SHA256) {
            throw new RuntimeException('Captured CII missing or changed: review provenance and sensitive data before updating its hash.');
        }

        return $xml;
    }
}
