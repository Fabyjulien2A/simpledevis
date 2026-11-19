<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Contrôles vendeur distincts de BT-34 et de la réception. Aucun droit d'envoi n'est inféré. */
final class SellerEmissionQualification
{
    /**
     * Separate opt-in path. Models must already be loaded by a trusted caller.
     * Does not read SQL/tokens, refresh credentials, or authorize a deposit.
     */
    public function assessSandbox(
        SandboxEmissionContext $context,
        Company $localCompany,
        SuperPdpConnection $connection,
        SandboxSellerEvidence $company,
        SandboxSellerEvidence $session,
        SandboxSellerEvidence $directory,
    ): array {
        $context->assertFor($localCompany);
        $declaration = $context->toArray();
        $clientId = config('services.superpdp.client_id');
        if (! is_string($clientId) || $clientId === '' || ! $connection->exists
            || $connection->company_id !== $localCompany->getKey() || $connection->status !== 'connected'
            || $connection->directory_identifier !== $declaration['seller']['superpdp_identity']['number']
            || $connection->tokenIsExpired()
            || ! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0
            || ! in_array($connection->getConnectionName(), [null, 'superpdp_sandbox'], true)) {
            throw new RuntimeException('Connexion sandbox locale non qualifiée.');
        }
        $connection->assertOAuthContext('sandbox', $clientId, (string) config('services.superpdp.api_url'));
        $checks = [];
        foreach (['company' => $company, 'session' => $session, 'directory' => $directory] as $endpoint => $evidence) {
            $checks[$endpoint.'_evidence'] = $evidence->endpoint === $endpoint && $this->freshSandboxEvidence($evidence->observedAt);
        }
        $checks['same_credentials'] = $company->sharesCredentialsWith($session) && $company->sharesCredentialsWith($directory);
        $expected = $declaration['seller']['superpdp_identity'];
        $row = $company->data;
        $checks['seller_identity'] = ($row['id'] ?? null) === $connection->superpdp_company_id
            && ($row['number_scheme'] ?? null) === $expected['number_scheme'] && ($row['number'] ?? null) === $expected['number']
            && ($row['env'] ?? null) === 'sandbox' && ($row['country'] ?? null) === 'FR';
        $checks['oauth_client'] = ($session->data['client_id'] ?? null) === $clientId;
        $checks['company_verified'] = ($session->data['company_verification_status'] ?? null) === 'verified';
        $checks['user_verified'] = ($session->data['user_identity_verification_status'] ?? null) === 'verified';
        // BT-34 is checked independently; it is not treated as a permission to send.
        $checks['seller_peppol_address'] = $this->sandboxSellerAddress(
            $directory->data, $expected, $connection->superpdp_company_id, $declaration['seller']['electronic_address'],
        );
        $passed = ! in_array(false, $checks, true);
        $live = $company->source === 'live' && $session->source === 'live' && $directory->source === 'live';

        return [
            'status' => ! $passed ? 'refused' : ($live ? 'passed' : 'not_verified'),
            'verified' => $passed && $live,
            'checks_passed_for_simulation' => $passed,
            'checks' => $checks,
            'reasons' => array_keys(array_filter($checks, static fn (bool $check): bool => ! $check)),
            'pending_evidence' => $live ? [] : ['real_authenticated_checks'],
            'local_company_id' => $localCompany->getKey(), 'company_id' => $connection->superpdp_company_id,
            'environment' => 'sandbox', 'seller_identity' => $expected,
            'seller_peppol_address' => $declaration['seller']['electronic_address'],
            'evidence' => array_map(static fn (SandboxSellerEvidence $proof): array => [
                'source' => $proof->source, 'observed_at' => $proof->observedAt, 'response_sha256' => $proof->responseSha256,
            ], ['company' => $company, 'session' => $session, 'directory' => $directory]),
            'ready_for_emission' => false,
        ];
    }

    private function freshSandboxEvidence(string $timestamp): bool
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $timestamp)) {
            return false;
        }
        try {
            $date = new DateTimeImmutable($timestamp);
            $errors = DateTimeImmutable::getLastErrors();

            return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->getTimestamp() <= now()->timestamp && $date->getTimestamp() >= now()->subMinutes(15)->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    private function sandboxSellerAddress(array $body, array $identity, int $providerId, array $address): bool
    {
        if (! isset($body['data']) || ! is_array($body['data']) || ! array_is_list($body['data'])
            || array_diff(array_keys($body), ['data']) !== []) {
            return false;
        }
        $identifier = $address['scheme'].':'.$address['value'];
        $matches = [];
        foreach ($body['data'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['company'] ?? null)) {
                return false;
            }
            $owner = $entry['company'];
            if (($owner['id'] ?? null) !== $providerId || ($owner['number_scheme'] ?? null) !== $identity['number_scheme']
                || ($owner['number'] ?? null) !== $identity['number'] || ($owner['env'] ?? null) !== 'sandbox'
                || ($owner['country'] ?? null) !== 'FR' || ! is_string($entry['identifier'] ?? null)
                || (array_key_exists('is_replyto', $entry) && ! is_bool($entry['is_replyto']))) {
                return false;
            }
            if ($entry['identifier'] !== $identifier) {
                continue; // Technical reply-to entries can coexist but are never selected.
            }
            $matches[] = $entry;
        }
        if (count($matches) !== 1) {
            return false;
        }
        $entry = $matches[0];
        if (str_contains(strtolower($entry['identifier']), '_replyto') || ($entry['is_replyto'] ?? false)
            || ($entry['directory'] ?? null) !== 'peppol' || ($entry['status'] ?? null) !== 'created') {
            return false;
        }
        // The API's is_replyto field is optional; exact approved-address matching is mandatory.
        if (array_key_exists('effective_date', $entry)) {
            $effective = $entry['effective_date'];
            if (! is_string($effective)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2}))?\z/D', $effective)) {
                return false;
            }
            try {
                new DateTimeImmutable($effective);
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
            $raw = substr($effective, 0, 10);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if (! $date || $date->format('Y-m-d') !== $raw || $raw > now('Europe/Paris')->format('Y-m-d')) {
                return false;
            }
        }

        return true;
    }

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
            // Support SUPER PDP : Authorization Code, sans permission send supplémentaire
            // ni activation BT-34. Une simulation ne constitue pas une preuve réelle.
            'pending_evidence' => ['real_authenticated_checks'],
        ];
    }
}
