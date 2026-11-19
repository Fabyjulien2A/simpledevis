<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use Saloon\Http\Response;

/** SDK receipt, including binding to the token currently used by the future sender. */
final readonly class TrialSellerEvidence
{
    private function __construct(public SandboxSellerEvidence $proof, private string $credentialHash) {}

    public static function fromResponse(Response $response): self
    {
        return new self(SandboxSellerEvidence::fromResponse($response),
            hash('sha256', $response->getPsrRequest()->getHeaderLine('Authorization')));
    }

    public function matchesToken(#[\SensitiveParameter] string $token): bool
    {
        return hash_equals($this->credentialHash, hash('sha256', 'Bearer '.$token));
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->proof->endpoint, 'source' => $this->proof->source];
    }
}
