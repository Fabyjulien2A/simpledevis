<?php

namespace App\Services\SuperPdp;

use InvalidArgumentException;

/** Contrôles vendeur distincts de BT-34 et de la réception. Aucun droit d'envoi n'est inféré. */
final class SellerEmissionQualification
{
    public function assess(array $data, array $company, array $session, string $clientId, string $environment): array
    {
        if ($clientId === '' || ! in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Le client OAuth et l’environnement attendus doivent être explicites.');
        }
        $siren = FrenchSellerIdentifiers::sirenFromSiret($data['seller']['siret'] ?? '');
        $reasons = [];
        if (! is_int($company['id'] ?? null) || $company['id'] <= 0
            || ($company['number_scheme'] ?? null) !== 'fr_siren' || ($company['number'] ?? null) !== $siren
            || ($company['country'] ?? null) !== 'FR') {
            $reasons[] = 'seller_identity_mismatch_or_delegation_unsupported';
        }
        if (($company['env'] ?? null) !== $environment) {
            $reasons[] = 'environment_mismatch';
        }
        if (($session['client_id'] ?? null) !== $clientId) {
            $reasons[] = 'oauth_client_mismatch';
        }
        if (($session['company_verification_status'] ?? null) !== 'verified') {
            $reasons[] = 'company_authority_not_verified';
        }
        if (($session['user_identity_verification_status'] ?? null) !== 'verified') {
            $reasons[] = 'user_identity_not_verified';
        }

        return [
            'status' => 'not_verified', 'source' => 'superpdp_authenticated_checks_simulation',
            'checks_passed_for_simulation' => $reasons === [], 'reasons' => $reasons,
            'seller_siren' => $siren, 'environment' => $environment,
            'company_id' => is_int($company['id'] ?? null) ? $company['id'] : null,
            'company_response_sha256' => hash('sha256', json_encode($company, JSON_THROW_ON_ERROR)),
            'session_response_sha256' => hash('sha256', json_encode($session, JSON_THROW_ON_ERROR)),
            // /oauth2_sessions/me ne décrit pas le droit send ni le rattachement de BT-34.
            // Un mandat in, une connexion receive ou KYB verified ne les remplacent pas.
            'pending_evidence' => ['real_authenticated_checks', 'outbound_authorization', 'bt34_provider_association'],
        ];
    }
}
