<?php

namespace App\Services\SuperPdp;

use InvalidArgumentException;

/** Validation de forme seulement, sans contrôle d'existence ou de clé. */
final class FrenchSellerIdentifiers
{
    public static function sirenFromSiret(string $siret): string
    {
        if (! preg_match('/^[0-9]{14}$/D', $siret)) {
            throw new InvalidArgumentException('Le SIRET du vendeur doit contenir exactement 14 chiffres.');
        }

        return substr($siret, 0, 9);
    }
}
