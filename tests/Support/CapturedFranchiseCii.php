<?php

namespace Tests\Support;

use RuntimeException;

/** User-supplied successful franchise conversion response; never regenerated. */
final class CapturedFranchiseCii
{
    public const SHA256 = 'c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735';

    public const PATH = __DIR__.'/../Feature/SuperPdp/superpdp_franchise_cii_reponse_2026-11-06.xml';

    public static function xml(): string
    {
        if (! is_file(self::PATH)) {
            throw new RuntimeException('Captured franchise CII missing.');
        }
        $xml = file_get_contents(self::PATH);
        if ($xml === false || hash('sha256', $xml) !== self::SHA256) {
            throw new RuntimeException('Captured franchise CII changed: review bytes and provenance before updating its hash.');
        }

        return $xml;
    }
}
