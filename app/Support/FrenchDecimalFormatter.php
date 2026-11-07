<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class FrenchDecimalFormatter
{
    /**
     * Deux décimales HALF_UP, virgule et espaces insécables, sans symbole monétaire.
     * Cet arrondi de présentation ne modifie jamais les données sources.
     */
    public function format(mixed $amount): string
    {
        // mixed permet de refuser les floats même depuis un appelant PHP non strict.
        if (! is_string($amount) || ! preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $amount)) {
            throw new InvalidArgumentException('Le montant doit être une chaîne décimale avec un point, sans exposant.');
        }

        $rounded = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = explode('.', ltrim($rounded, '-'));
        $grouped = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', "\u{00A0}", $integer);

        return ($negative ? '-' : '') . $grouped . ',' . $fraction;
    }
}
