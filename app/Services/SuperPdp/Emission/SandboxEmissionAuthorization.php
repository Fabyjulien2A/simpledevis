<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use Illuminate\Support\Str;
use RuntimeException;

/** Closed by default; never populated from request parameters or environment flags. */
final class SandboxEmissionAuthorization
{
    public const API_URL = 'https://api.superpdp.tech/v1.beta';

    public const ORIGIN = 'https://api.superpdp.tech';

    public function approval(): array
    {
        $approval = config('superpdp_emission.authorization');
        $client = config('superpdp_emission.sandbox_client_id');
        if (config('superpdp_emission.sandbox_enabled') !== true
            || ! in_array(app()->environment(), ['local', 'staging', 'sandbox', 'testing'], true)
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== self::API_URL
            || ! is_string($client) || $client === '' || config('services.superpdp.client_id') !== $client
            || ! is_array($approval) || ! is_string($approval['approved_by'] ?? null) || trim($approval['approved_by']) === ''
            || ! is_int($approval['approved_at'] ?? null) || ! is_int($approval['expires_at'] ?? null)
            || $approval['approved_at'] > now()->timestamp || $approval['expires_at'] <= now()->timestamp
            || $approval['expires_at'] <= $approval['approved_at'] || $approval['expires_at'] - $approval['approved_at'] > 900
            || ($approval['environment'] ?? null) !== 'sandbox'
            || ($approval['api_url'] ?? null) !== self::API_URL || ($approval['oauth_client_id'] ?? null) !== $client) {
            throw new RuntimeException('Sandbox emission locked: explicit scoped authorization and sandbox configuration required.');
        }

        return $approval;
    }

    public function connection(int $userId, int $invoiceId, string $reference): SuperPdpConnection
    {
        $approval = $this->approval();
        EmissionDocument::assertReference($reference, $userId, $invoiceId);
        if (($approval['user_id'] ?? null) !== $userId || ($approval['invoice_id'] ?? null) !== $invoiceId
            || ($approval['document_reference'] ?? null) !== $reference) {
            throw new RuntimeException('Sandbox authorization scope mismatch.');
        }
        $company = Company::where('user_id', $userId)->firstOrFail();
        $connection = $company->superPdpConnection()->firstOrFail();
        $connection->assertOAuthContext('sandbox', $approval['oauth_client_id'], self::API_URL);
        if (! $connection->isConnected() || ! is_string($connection->access_token) || $connection->access_token === ''
            || $connection->tokenIsExpired() || ! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0
            || ($approval['provider_company_id'] ?? null) !== $connection->superpdp_company_id) {
            throw new RuntimeException('Qualified non-expiring sandbox OAuth credentials required; no automatic refresh.');
        }

        return $connection;
    }

    public function document(EmissionDocument $document): SuperPdpConnection
    {
        $connection = $this->connection($document->userId, $document->invoiceId, $document->reference);
        if ($document->environment !== 'sandbox' || $document->companyId !== $connection->superpdp_company_id
            || ($this->approval()['cii_sha256'] ?? null) !== $document->sha256
            || ! hash_equals($document->sha256, hash('sha256', $document->xml))) {
            throw new RuntimeException('Sandbox document authorization mismatch.');
        }

        return $connection;
    }

    public function attempt(string $reference): void
    {
        if (! Str::isUuid($reference) || ($this->approval()['attempt_reference'] ?? null) !== $reference) {
            throw new RuntimeException('Explicit authorization for this attempt UUID required.');
        }
    }
}
