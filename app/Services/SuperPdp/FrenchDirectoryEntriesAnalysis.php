<?php

namespace App\Services\SuperPdp;

use InvalidArgumentException;

/** Analyse PHP pure de lignes déjà récupérées, sans sélection ni preuve de routage. */
final class FrenchDirectoryEntriesAnalysis
{
    /**
     * @param  list<mixed>  $lines  Valeur data de listEntries(), sans nouvelle consultation.
     * @param  array  $pagination  Métadonnées explicites, conservées sans interpréter les curseurs.
     * @return array{lines: list<array>, pagination: array}
     */
    public function analyze(mixed $expectedSiren, array $lines, array $pagination = []): array
    {
        if (! is_string($expectedSiren) || ! preg_match('/\A[0-9]{9}\z/', $expectedSiren)) {
            throw new InvalidArgumentException('Le SIREN attendu doit être une chaîne de neuf chiffres, sans espaces.');
        }
        if (! array_is_list($lines)) {
            throw new InvalidArgumentException('Les lignes d’annuaire doivent être une liste ordonnée.');
        }
        if (array_key_exists('has_more', $pagination) && ! is_bool($pagination['has_more'])) {
            throw new InvalidArgumentException('La métadonnée has_more doit être un booléen lorsqu’elle est fournie.');
        }

        $result = [];
        foreach ($lines as $index => $line) {
            $result[] = $this->analyzeLine($line, $index, $expectedSiren);
        }

        return ['lines' => $result, 'pagination' => $pagination];
    }

    private function analyzeLine(mixed $line, int $index, string $expectedSiren): array
    {
        $result = [
            'source_index' => $index,
            'line' => $line,
            'classification' => 'invalid_identifier',
            'identifier_status' => 'invalid',
            'reason' => 'malformed_line',
            'bt49_candidate' => null,
        ];
        if (! is_array($line) || ! is_bool($line['is_active'] ?? null)) {
            return $result;
        }
        $company = $line['company'] ?? null;
        if (! is_array($company) || ! is_string($company['number'] ?? null)
            || ! preg_match('/\A[0-9]{9}\z/', $company['number'])) {
            return array_replace($result, ['reason' => 'invalid_company_siren']);
        }
        if ($company['number'] !== $expectedSiren) {
            return array_replace($result, ['reason' => 'company_siren_mismatch']);
        }

        [$status, $reason, $candidate] = $this->analyzeIdentifier($line['identifier'] ?? null, $expectedSiren);

        return array_replace($result, [
            // Une ligne inactive conserve aussi le diagnostic distinct de son identifiant.
            'classification' => ! $line['is_active'] ? 'inactive' : match ($status) {
                'compatible' => 'active_candidate',
                'unsupported' => 'unsupported_format',
                default => 'invalid_identifier',
            },
            'identifier_status' => $status,
            'reason' => $reason,
            'bt49_candidate' => $line['is_active'] ? $candidate : null,
        ]);
    }

    private function analyzeIdentifier(mixed $identifier, string $expectedSiren): array
    {
        if (! is_string($identifier)) {
            return ['invalid', 'malformed_identifier', null];
        }
        if (stripos($identifier, '_replyto') !== false) {
            return ['invalid', 'technical_reply_address', null];
        }
        if (preg_match('/\A0225:[0-9]{9}\z/', $identifier)) {
            try {
                return ['compatible', 'simple_peppol_identifier',
                    FrenchPeppolBt49Candidate::fromIdentifier($identifier, $expectedSiren)];
            } catch (InvalidArgumentException) {
                return ['invalid', 'identifier_siren_mismatch', null];
            }
        }

        // Reconnaître une famille hors périmètre ne valide pas sa grammaire métier.
        // Ne pas transformer ces identifiants ni en extraire un candidat BT-49.
        if (preg_match('/\A(?:0225:)?([0-9]{9})(?:_[A-Za-z0-9_]+)?\z/', $identifier, $matches)) {
            if ($matches[1] !== $expectedSiren) {
                return ['invalid', 'identifier_siren_mismatch', null];
            }

            return ['unsupported', str_starts_with($identifier, '0225:') ? 'composed_format' : 'missing_peppol_prefix', null];
        }
        if (preg_match('/\A(?!0225:)[0-9]{4}:[A-Za-z0-9_]+\z/', $identifier)) {
            return ['unsupported', 'other_peppol_scheme', null];
        }

        return ['invalid', 'malformed_identifier', null];
    }
}
