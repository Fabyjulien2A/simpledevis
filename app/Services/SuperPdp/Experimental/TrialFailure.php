<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use RuntimeException;

/** Deliberately contains no original message, exception chain, path or credentials. */
final class TrialFailure extends RuntimeException
{
    public function __construct(public readonly string $stage, public readonly string $reason, public readonly string $errorType)
    {
        parent::__construct('Sandbox diagnostic: '.$reason);
    }
}
