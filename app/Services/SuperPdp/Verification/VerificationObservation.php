<?php

namespace App\Services\SuperPdp\Verification;

use Saloon\Http\Response;

/** Provenance calculée depuis la réponse SDK, jamais depuis un booléen de formulaire. */
final readonly class VerificationObservation
{
    private function __construct(public array $data, public string $source, public string $sha256, public string $observedAt) {}

    public static function fromResponse(array $data, Response $response): self
    {
        return new self($data, $response->isMocked() ? 'simulated' : ($response->isCached() ? 'cached' : 'live'),
            hash('sha256', $response->body()), now()->toIso8601String());
    }
}
