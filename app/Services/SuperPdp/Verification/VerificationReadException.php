<?php

namespace App\Services\SuperPdp\Verification;

use RuntimeException;

final class VerificationReadException extends RuntimeException
{
    public function __construct(public readonly string $status, public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct('Vérification SUPER PDP : '.$reason.'.');
    }
}
