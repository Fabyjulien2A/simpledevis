<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Emission;

use InvalidArgumentException;
use Saloon\Http\Response;

/** In-memory receipt of an existing SDK response; performs no request or persistence. */
final readonly class SandboxSellerEvidence
{
    private function __construct(
        public string $endpoint,
        public array $data,
        public string $source,
        public string $observedAt,
        public string $responseSha256,
        private string $credentialFingerprint,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $request = $response->getPsrRequest();
        $endpoint = array_search((string) $request->getUri(), [
            'company' => SandboxEmissionAuthorization::API_URL.'/companies/me',
            'session' => SandboxEmissionAuthorization::API_URL.'/oauth2_sessions/me',
            'directory' => SandboxEmissionAuthorization::API_URL.'/directory_entries',
        ], true);
        $authorization = $request->getHeaderLine('Authorization');
        if ($request->getMethod() !== 'GET' || $endpoint === false || $response->status() !== 200
            || ! preg_match('/\ABearer [^\x00-\x20\x7f]+\z/Di', $authorization)
            || strlen($response->body()) > 262144) {
            throw new InvalidArgumentException('Réponse de lecture sandbox non qualifiable.');
        }

        return new self($endpoint, EmissionJson::object($response->body()),
            $response->isMocked() ? 'simulated' : ($response->isCached() ? 'cached' : 'live'),
            now()->toIso8601String(), hash('sha256', $response->body()), hash('sha256', $authorization));
    }

    public function sharesCredentialsWith(self $other): bool
    {
        return hash_equals($this->credentialFingerprint, $other->credentialFingerprint);
    }

    public function usesAccessToken(#[\SensitiveParameter] string $token): bool
    {
        return hash_equals($this->credentialFingerprint, hash('sha256', 'Bearer '.$token));
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'source' => $this->source, 'observed_at' => $this->observedAt];
    }
}
