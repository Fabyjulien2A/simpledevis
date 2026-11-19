<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\ReadOnlyOAuthPrerequisiteException;
use App\Services\SuperPdp\SuperPdpOAuthService;
use DateTimeImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/** Publication de Tricatel uniquement ; aucune qualification de production ou de routage BQ. */
final class TricatelPublicationVerification
{
    public function __construct(private readonly VerificationAuditStore $audit) {}

    public function verify(User $actor, Company $company, SuperPdpConnection $connection, bool $authorizeNetwork = false, ?array $responses = null): array
    {
        TricatelPublicationScope::assert($actor, $company, $connection);
        $report = ['schema_version' => 1, 'kind' => 'tricatel_sandbox_publication', 'id' => (string) Str::uuid(),
            'user_id' => 4, 'company_id' => 4, 'environment' => 'sandbox', 'contract_version' => '1.39.0.beta',
            'checked_at' => now()->toIso8601String(), 'validity_minutes' => $this->validityMinutes(),
            'expected_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
            'expected_address' => TricatelPublicationScope::ADDRESS, 'provider_company_id' => $connection->superpdp_company_id,
            'source_sha256' => $this->sourceHash($company, $connection),
            'status' => 'unavailable', 'reason' => 'not_verified', 'publication_confirmed' => false,
            'routing_from_burger_queen_verified' => false, 'emission_ready' => false, 'observations' => []];
        $gateway = null;
        $operation = 'company';
        try {
            $gateway = new TricatelPublicationGateway($actor, $company, $connection, $authorizeNetwork, $responses);
            $identity = $gateway->company();
            $report['observations']['company'] = $this->evidence($identity);
            $report['identity'] = array_intersect_key($identity->data, array_flip(['id', 'env', 'number_scheme', 'number', 'formal_name', 'country']));
            if (! $this->identityMatches($report['identity'], $connection->superpdp_company_id)) {
                $report['status'] = 'refused';
                $report['reason'] = 'tricatel_identity_mismatch';
            } else {
                $operation = 'entries';
                $entries = $gateway->entries();
                $report['observations']['entries'] = $this->evidence($entries);
                $result = $this->assessEntries($entries->data, $report['identity']);
                $report = array_replace($report, $result);
                if ($result['status'] === 'matched') {
                    $live = $identity->source === 'live' && $entries->source === 'live';
                    $report['publication_confirmed'] = $live;
                    $report['status'] = $live ? 'confirmed'
                        : (in_array('simulated', [$identity->source, $entries->source], true) ? 'simulated' : 'unavailable');
                    $report['reason'] = $live ? 'exact_owned_address_published' : 'matching_observations_not_live';
                }
            }
        } catch (VerificationReadException $exception) {
            if ($observation = $gateway?->lastObservation()) {
                $report['observations'][$operation] = $this->evidence($observation);
            }
            $report['status'] = $exception->status;
            $report['reason'] = $exception->reason;
        } catch (ReadOnlyOAuthPrerequisiteException $exception) {
            $report['status'] = 'unavailable';
            $report['reason'] = $exception->reason;
            $report['prerequisite_diagnostic'] = $exception->diagnostic;
        } catch (RuntimeException) {
            $report['status'] = 'unavailable';
            $report['reason'] = 'read_only_prerequisites_unavailable';
        }
        TricatelPublicationScope::assert($actor, $company, $connection);
        if ($report['source_sha256'] !== $this->sourceHash($company, $connection)) {
            $report['status'] = 'unavailable';
            $report['reason'] = 'local_context_changed';
            $report['publication_confirmed'] = false;
        }
        $report['audit_reference'] = $this->audit->storeCompanyPublication($actor, $company, $report);

        return $report;
    }

    /** Diagnostic des prérequis sans client API, stockage de rapport ou écriture SQL. */
    public function diagnose(User $actor, Company $company, SuperPdpConnection $connection): array
    {
        TricatelPublicationScope::assert($actor, $company, $connection);
        $result = ['status' => 'unavailable', 'reason' => null, 'checked_at' => now()->toIso8601String(),
            'api_called' => false, 'report_saved' => false, 'token_refreshed' => false,
            'publication_confirmed' => false, 'routing_from_burger_queen_verified' => false];
        try {
            app(SuperPdpOAuthService::class)->existingAccessTokenForReadOnly($company, $connection);
            $result['status'] = 'prerequisites_passed';
            $result['reason'] = 'ready_for_explicit_read_only_verification';
            $result['expires_at'] = $connection->access_token_expires_at?->toIso8601String();
        } catch (ReadOnlyOAuthPrerequisiteException $exception) {
            $result['reason'] = $exception->reason;
            $result['diagnostic'] = $exception->diagnostic;
        }

        return $result;
    }

    /** Relecture locale d'un rapport, sans API et sans prolongation de sa validité. */
    public function read(User $actor, Company $company, SuperPdpConnection $connection, string $reference): array
    {
        TricatelPublicationScope::assert($actor, $company, $connection);
        $report = $this->audit->readCompanyPublication($actor, $company, $reference);
        $invalidate = fn (string $reason, string $status = 'unavailable') => array_replace($report, [
            'publication_confirmed' => false, 'status' => $status, 'reason' => $reason,
        ]);
        if ($report['source_sha256'] !== $this->sourceHash($company, $connection)) {
            return $invalidate('local_context_changed');
        }
        foreach ($report['observations'] as $observation) {
            try {
                $date = new DateTimeImmutable($observation['observed_at']);
                if ($date->format('Y-m-d\TH:i:sP') !== $observation['observed_at'] || $date->getTimestamp() > now()->timestamp) {
                    return $invalidate('invalid_evidence_date');
                }
                if ($date->getTimestamp() <= now()->subMinutes($this->validityMinutes())->timestamp) {
                    return $invalidate('evidence_expired', 'expired');
                }
            } catch (\Throwable) {
                return $invalidate('invalid_evidence_date');
            }
        }
        if ($report['publication_confirmed']) {
            if (($report['status'] ?? null) !== 'confirmed' || ($report['environment'] ?? null) !== 'sandbox'
                || ($report['contract_version'] ?? null) !== '1.39.0.beta' || ($report['matched_entry_count'] ?? null) !== 1
                || ($report['expected_address'] ?? null) !== TricatelPublicationScope::ADDRESS
                || ($report['provider_company_id'] ?? null) !== $connection->superpdp_company_id) {
                return $invalidate('incoherent_publication');
            }
            foreach (['company' => '/companies/me', 'entries' => '/directory_entries'] as $key => $path) {
                $evidence = $report['observations'][$key] ?? [];
                if (($evidence['source'] ?? null) !== 'live' || ($evidence['request']['method'] ?? null) !== 'GET'
                    || ($evidence['request']['endpoint'] ?? null) !== 'https://api.superpdp.tech/v1.beta'.$path
                    || ($evidence['request']['http_status'] ?? null) !== 200 || ($evidence['request']['query'] ?? null) !== []
                    || ! preg_match('/\A[a-f0-9]{64}\z/', $evidence['response_sha256'] ?? '')) {
                    return $invalidate('non_live_or_incoherent_evidence');
                }
            }
            if (! $this->identityMatches($report['identity'] ?? [], $connection->superpdp_company_id)
                || ($report['published_entry']['identifier'] ?? null) !== TricatelPublicationScope::ADDRESS
                || ($report['published_entry']['is_replyto'] ?? null) !== false
                || ($report['published_entry']['status'] ?? null) !== 'created'
                || ($report['published_entry']['directory'] ?? null) !== 'peppol') {
                return $invalidate('incoherent_publication');
            }
        }

        return $report;
    }

    private function identityMatches(array $row, int $providerId): bool
    {
        return ($row['id'] ?? null) === $providerId && ($row['env'] ?? null) === 'sandbox'
            && ($row['number_scheme'] ?? null) === 'sandbox' && ($row['number'] ?? null) === '000000001'
            && ($row['formal_name'] ?? null) === 'Tricatel' && ($row['country'] ?? null) === 'FR';
    }

    /** Pure assessment reused by the explicitly isolated invoice profile. No SQL or request. */
    public function assessSandboxDestination(array $reply): array
    {
        // Documentary qualification for the isolated invoice profile only.
        // Publication verification above keeps requiring an explicit false.
        return $this->assessEntries($reply, ['id' => 18171], documentarySandbox: true);
    }

    private function assessEntries(array $reply, array $identity, bool $documentarySandbox = false): array
    {
        $result = fn (string $status, string $reason) => compact('status', 'reason');
        if (array_keys($reply) !== ['data'] || ! is_array($reply['data']) || ! array_is_list($reply['data'])) {
            return $result('ambiguous', 'incomplete_or_unknown_envelope');
        }
        $matches = [];
        foreach ($reply['data'] as $row) {
            if (! is_array($row) || ! is_array($row['company'] ?? null) || ! $this->identityMatches($row['company'], $identity['id'])
                || ! is_int($row['id'] ?? null) || $row['id'] <= 0 || ! is_string($row['identifier'] ?? null)
                || ! in_array($row['status'] ?? null, ['pending', 'created', 'error'], true)
                || ($row['directory'] ?? null) !== 'peppol') {
                return $result('incompatible', 'foreign_or_malformed_entry');
            }
            // Une adresse de réponse technique ne peut jamais être retenue.
            if (stripos($row['identifier'], '_replyto') !== false || ($row['is_replyto'] ?? null) === true) {
                if ($documentarySandbox && $row['identifier'] === TricatelPublicationScope::ADDRESS) {
                    return $result('refused', 'technical_replyto_address');
                }
                continue;
            }
            if ($row['identifier'] === TricatelPublicationScope::ADDRESS) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            return $result('ambiguous', 'multiple_exact_entries');
        }
        if ($matches === []) {
            return $result('absent', 'expected_address_not_published');
        }
        $row = $matches[0];
        $classification = [];
        if (($row['is_replyto'] ?? null) !== false) {
            // Champ facultatif dans 1.39.0.beta, sans default : absent/null ne vaut jamais false.
            // Conserver uniquement présence/type, pas une valeur distante potentiellement sensible.
            $classification = ['classification_diagnostic' => [
                'entry_id' => $row['id'], 'identifier' => $row['identifier'],
                'registration_status' => $row['status'],
                'field' => 'is_replyto', 'field_present' => array_key_exists('is_replyto', $row),
                'value_type' => array_key_exists('is_replyto', $row) ? get_debug_type($row['is_replyto']) : 'absent',
                'classification' => 'undetermined',
            ]];
            if (! $documentarySandbox || array_key_exists('is_replyto', $row)) {
                return $result('unavailable', 'replyto_classification_missing') + $classification;
            }
        }
        if ($row['status'] !== 'created') {
            return $result('inactive', 'registration_not_created');
        }

        $documentary = $documentarySandbox ? [
            'qualification_documentaire_sandbox' => 'passed', 'basis' => 'directory_list_contract',
            'replyto_classification' => array_key_exists('is_replyto', $row) ? 'non_replyto' : 'undetermined',
            'delivery_verified' => false, 'ready_for_emission' => false,
        ] : [];

        return $result('matched', $documentarySandbox ? 'sandbox_documentary_destination' : 'exact_owned_address')
            + $classification + $documentary + ['matched_entry_count' => 1, 'published_entry' => array_intersect_key($row,
            array_flip(['id', 'identifier', 'is_replyto', 'status', 'directory']))];
    }

    private function evidence(VerificationObservation $observation): array
    {
        return ['source' => $observation->source, 'observed_at' => $observation->observedAt,
            'response_sha256' => $observation->sha256, 'request' => $observation->request];
    }

    private function sourceHash(Company $company, SuperPdpConnection $connection): string
    {
        return TricatelPublicationScope::hash(['company' => $company->getAttributes(), 'connection' => $connection->getAttributes(),
            'oauth_client_id' => config('services.superpdp.client_id'), 'api_url' => config('services.superpdp.api_url')]);
    }

    private function validityMinutes(): int
    {
        $minutes = config('superpdp_verification.publication_validity_minutes', 15);

        return is_int($minutes) && $minutes > 0 ? $minutes : 15;
    }
}
