<?php

namespace App\Services\SuperPdp;

use RuntimeException;

/** Erreur locale stable ; aucun corps de réponse ni jeton dans le message. */
final class FrenchDirectoryConsultationException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct(match ($reason) {
            'authentication' => 'La consultation de l’annuaire SUPER PDP est refusée (authentification ou autorisation).',
            'api_error' => 'L’annuaire SUPER PDP a retourné une erreur API.',
            'unavailable' => 'L’annuaire SUPER PDP est temporairement inaccessible.',
            default => 'La réponse de l’annuaire SUPER PDP est mal formée.',
        });
    }
}
