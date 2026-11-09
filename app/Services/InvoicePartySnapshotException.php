<?php

namespace App\Services;

use InvalidArgumentException;

final class InvoicePartySnapshotException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'missing' => 'Instantané des parties absent : cette facture historique reste bloquée pour la préparation électronique.',
            'unsupported_version' => 'Version de l’instantané des parties non prise en charge.',
            default => 'Structure de l’instantané des parties invalide. Aucune donnée actuelle ne peut le remplacer.',
        });
    }

    public function blocker(): array
    {
        return [
            'code' => 'invoice.party_snapshot.'.$this->reason,
            'category' => match ($this->reason) {
                'missing' => 'missing_data', 'unsupported_version' => 'unsupported', default => 'invalid_data',
            },
            'field' => 'party_snapshot', 'message' => $this->getMessage(),
        ];
    }
}
