<?php

namespace App\Services\SuperPdp;

use InvalidArgumentException;

/**
 * Couple candidat BT-49, exclusivement pour 0225:SIREN sans suffixe.
 * Validation de forme et de concordance uniquement : aucune preuve d'existence,
 * d'activité ou de routage, aucune sélection de destination et aucun usage BT-34.
 */
final readonly class FrenchPeppolBt49Candidate
{
    private function __construct(
        public string $originalIdentifier,
        public string $scheme,
        public string $value,
    ) {}

    public static function fromIdentifier(mixed $identifier, mixed $expectedSiren): self
    {
        if (! is_string($identifier)) {
            throw new InvalidArgumentException('L’identifiant Peppol doit être une chaîne au format 0225:SIREN.');
        }
        // Refus explicite, même si cette forme serait aussi rejetée par la grammaire simple.
        if (stripos($identifier, '_replyto') !== false) {
            throw new InvalidArgumentException('Une adresse technique _replyto ne peut pas produire un candidat BT-49.');
        }
        if (! preg_match('/\A0225:([0-9]{9})\z/', $identifier, $matches)) {
            throw new InvalidArgumentException('Seul un identifiant Peppol 0225: suivi de neuf chiffres, sans suffixe ni espaces, est pris en charge.');
        }
        if (! is_string($expectedSiren) || ! preg_match('/\A[0-9]{9}\z/', $expectedSiren)) {
            throw new InvalidArgumentException('Le SIREN destinataire attendu doit être une chaîne de neuf chiffres, sans espaces.');
        }
        if ($matches[1] !== $expectedSiren) {
            throw new InvalidArgumentException('Le SIREN de l’identifiant Peppol ne correspond pas au SIREN destinataire attendu.');
        }

        return new self($identifier, '0225', $matches[1]);
    }
}
