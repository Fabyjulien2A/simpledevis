<?php

namespace App\Services\SuperPdp;

use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use RuntimeException;

/** Shared local guards. No connector, HTTP, token refresh, archive or configuration mutation. */
final class CiiPreparationGuards
{
    public function webActivation(): void
    {
        if (config('superpdp_preparation.web_enabled') !== true
            || ! app()->environment('sandbox') || config('app.env') !== 'sandbox') {
            throw new RuntimeException('Le parcours HTTP de préparation CII est réservé à une activation explicite en sandbox.');
        }
        $this->configuration();
    }

    public function configuration(): string
    {
        if (! in_array(app()->environment(), ['local', 'staging', 'sandbox', 'testing'], true)) {
            throw new RuntimeException('La préparation CII réelle est interdite dans cet environnement applicatif, notamment en production.');
        }
        if (config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL) {
            throw new RuntimeException('La préparation CII exige le contexte SUPER PDP sandbox et son URL API attendue.');
        }
        $client = config('superpdp_preparation.sandbox_client_id');
        if (! is_string($client) || $client === '' || config('services.superpdp.client_id') !== $client) {
            throw new RuntimeException('Le client OAuth sandbox de préparation doit être configuré explicitement et correspondre au client applicatif.');
        }

        return $client;
    }

    public function connection(SuperPdpConnection $connection, string $client): void
    {
        $connection->assertOAuthContext('sandbox', $client, SandboxEmissionAuthorization::API_URL);
        if (! $connection->isConnected()) {
            throw new RuntimeException('La connexion OAuth est déconnectée ou son jeton de renouvellement est absent. Reconnectez votre entreprise.');
        }
        if ($connection->tokenIsExpired()) {
            throw new RuntimeException('Le jeton OAuth est expiré, expire dans moins de cinq minutes ou sa date d’expiration est absente. Aucun renouvellement automatique n’est effectué.');
        }
        if (! $connection->access_token) {
            throw new RuntimeException('Le jeton d’accès OAuth est absent. Reconnectez votre entreprise.');
        }
        if (! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0) {
            throw new RuntimeException('La connexion OAuth ne comporte pas d’identifiant fournisseur valide.');
        }
    }

    public function activation(): array
    {
        if (config('superpdp_preparation.live_enabled') !== true) {
            throw new RuntimeException('Les opérations réelles de préparation CII sont désactivées dans la configuration.');
        }
        $approval = config('superpdp_preparation.authorization');
        if (! is_array($approval)) {
            throw new RuntimeException('Aucune autorisation explicite de préparation CII n’est configurée pour cette facture.');
        }

        return $approval;
    }

    public function authorization(int $userId, int $invoiceId, string $sourceHash, string $reference,
        SuperPdpConnection $connection, string $client, bool $liveEvidence): void
    {
        $approval = $this->activation();
        if (! $liveEvidence) {
            throw new RuntimeException('Des preuves réelles et récentes du vendeur, de sa session et du destinataire sont requises avant toute préparation distante.');
        }
        if (! is_string($approval['approved_by'] ?? null) || trim($approval['approved_by']) === ''
            || ! is_int($approval['approved_at'] ?? null) || ! is_int($approval['expires_at'] ?? null)
            || $approval['approved_at'] > now()->timestamp || $approval['expires_at'] <= now()->timestamp
            || $approval['expires_at'] <= $approval['approved_at'] || $approval['expires_at'] - $approval['approved_at'] > 900) {
            throw new RuntimeException('L’autorisation de préparation est absente, expirée ou invalide ; sa durée maximale est de quinze minutes.');
        }
        if (($approval['user_id'] ?? null) !== $userId || ($approval['invoice_id'] ?? null) !== $invoiceId
            || ($approval['source_sha256'] ?? null) !== $sourceHash || ($approval['verification_reference'] ?? null) !== $reference
            || ($approval['environment'] ?? null) !== 'sandbox' || ($approval['oauth_client_id'] ?? null) !== $client
            || ($approval['api_url'] ?? null) !== SandboxEmissionAuthorization::API_URL
            || ($approval['provider_company_id'] ?? null) !== $connection->superpdp_company_id) {
            throw new RuntimeException('L’autorisation ne correspond pas au propriétaire, à la facture figée, aux preuves ou au contexte OAuth sandbox actuel.');
        }
    }
}
