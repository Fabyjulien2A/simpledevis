<?php

namespace App\Services\SuperPdp;

use RuntimeException;

/** Codes et métadonnées explicitement non sensibles ; jamais d'exception distante chaînée. */
final class ReadOnlyOAuthPrerequisiteException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $diagnostic = [])
    {
        parent::__construct('Prérequis OAuth en lecture seule : '.$reason.'.');
    }
}
