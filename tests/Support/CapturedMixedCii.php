<?php

namespace Tests\Support;

use RuntimeException;

/** Actual user-supplied M1 CII; preserve the original bytes. */
final class CapturedMixedCii
{
    public const SHA256 = 'ee67303fefc843b00febce015e149331fea0b22da24524cc5eebab4b0457917e';

    public const PATH = __DIR__.'/../Feature/SuperPdp/superpdp_mixte_m1_cii_reponse_2026-11-07.xml';

    public static function xml(): string
    {
        if (! is_file(self::PATH)) {
            throw new RuntimeException('Captured M1 CII missing.');
        }
        $xml = file_get_contents(self::PATH);
        if ($xml === false || hash('sha256', $xml) !== self::SHA256) {
            throw new RuntimeException('Captured M1 CII changed: review bytes and provenance before updating its hash.');
        }

        return $xml;
    }
}
