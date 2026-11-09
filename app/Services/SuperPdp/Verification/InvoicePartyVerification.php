<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\FrenchSellerIdentifiers;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\InvoiceRoutingQualification;
use App\Services\SuperPdp\SellerEmissionQualification;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class InvoicePartyVerification
{
    public function __construct(
        private readonly InvoiceExportDataBuilder $source,
        private readonly SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        private readonly InvoiceRoutingQualification $routing,
        private readonly SellerEmissionQualification $seller,
        private readonly VerificationAuditStore $audit,
    ) {}

    public function verify(User $actor, Invoice $invoice, string $environment, ?array $responses = null): array
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        $report = ['schema_version' => 1, 'id' => (string) Str::uuid(), 'user_id' => $actor->id, 'invoice_id' => $stored->id,
            'checked_at' => now()->toIso8601String(), 'expected_environment' => in_array($environment, ['sandbox', 'production'], true) ? $environment : null,
            'contract_version' => '1.39.0.beta', 'emission_ready' => false, 'checks' => []];
        if (! $this->diagnostic->diagnose($stored)['local_checks_passed']) {
            $report['checks']['invoice_data'] = $this->result('unavailable', 'invoice_not_locally_eligible');

            return $this->finish($actor, $stored, $report);
        }
        $data = $this->source->buildElectronic($stored);
        $report['source_sha256'] = $this->hash($data);
        $report['party_snapshot_sha256'] = hash('sha256', $data['party_snapshot']);
        $report['emission_context_sha256'] = hash('sha256', $data['emission_context']);
        try {
            $gateway = ReadOnlyQualificationGateway::forInvoice($actor, $stored, $environment, $responses);
        } catch (VerificationReadException $exception) {
            $report['checks']['connection'] = $this->error($exception);

            return $this->finish($actor, $stored, $report);
        }
        $report['expected_provider_company_id'] = $gateway->expectedCompanyId;
        try {
            $observation = $gateway->directory(FrenchSellerIdentifiers::sirenFromSiret($data['buyer']['siret']));
            $qualified = $this->routing->assess($data, $observation->data);
            $reason = $qualified['reason'];
            $status = $qualified['qualified_for_simulation'] ? 'passed' : match ($reason) {
                'no_active_destination', 'directory_country_mismatch', 'declared_bt49_not_matched', 'unsupported_buyer' => 'refused',
                default => 'ambiguous',
            };
            $report['checks']['buyer_destination'] = $this->result($status,
                $status === 'passed' ? 'unique_active_historical_destination' : $reason, $observation);
            $report['buyer_siren'] = $qualified['buyer_siren'];
            $report['buyer_destination'] = $qualified['destination'];
        } catch (VerificationReadException $exception) {
            $report['checks']['buyer_destination'] = $this->error($exception);
        }
        // Vérifications distinctes : identité/KYB, présence d'une adresse de réception, droit d'émettre.
        $report['checks']['seller_emission_permission'] = $this->result('unavailable', 'session_contract_does_not_expose_send_permission');
        $report['checks']['seller_bt34_sending_binding'] = $this->result('unavailable', 'directory_contract_only_documents_receiving_addresses');
        try {
            $session = $gateway->session();
            $report['checks']['seller_session'] = $this->session($session, $gateway->expectedClientId);
            $sessionStatus = $report['checks']['seller_session']['status'];
            if ($sessionStatus !== 'passed') {
                $report['checks']['seller_identity'] = $this->result('unavailable', 'company_not_read_without_verified_session');
            } else {
                $company = $gateway->company();
                $report['checks']['seller_identity'] = $this->identity($data, $company, $session, $gateway);
                $identityStatus = $report['checks']['seller_identity']['status'];
                if ($identityStatus === 'passed') {
                    try {
                        $report['checks']['seller_bt34_receiving_publication'] = $this->publication($data, $gateway->ownedEntries(), $company->data, $environment);
                    } catch (VerificationReadException $exception) {
                        $report['checks']['seller_bt34_receiving_publication'] = $this->error($exception);
                    }
                }
            }
        } catch (VerificationReadException $exception) {
            $report['checks']['seller_identity'] = $this->error($exception);
        }
        foreach (['seller_identity', 'seller_session', 'seller_bt34_receiving_publication'] as $key) {
            $report['checks'][$key] ??= $this->result('unavailable', 'prerequisite_not_available');
        }
        try {
            $fresh = $stored->fresh();
            abort_unless($fresh->user_id === $actor->id, 403);
            if ($report['source_sha256'] !== $this->hash($this->source->buildElectronic($fresh))) {
                $report['checks']['source_consistency'] = $this->result('ambiguous', 'invoice_changed_during_verification');
                foreach ($report['checks'] as &$check) {
                    $check['verified'] = false;
                } unset($check);
            }
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            $report['checks']['source_consistency'] = $this->result('ambiguous', 'invoice_source_unreadable');
            foreach ($report['checks'] as &$check) {
                $check['verified'] = false;
            } unset($check);
        }

        return $this->finish($actor, $stored, $report);
    }

    private function session(VerificationObservation $observation, string $expectedClient): array
    {
        $data = $observation->data;
        if (! is_string($data['client_id'] ?? null) || ! $this->date($data['created_at'] ?? null)
            || ! in_array($data['company_verification_status'] ?? null, ['verified', 'needs_review', 'failed'], true)
            || (isset($data['user_identity_verification_status']) && ! in_array($data['user_identity_verification_status'], ['verified', 'needs_review', 'failed', 'not_verified'], true))) {
            return $this->result('ambiguous', 'malformed_or_unknown_session_state', $observation);
        }
        if ($data['client_id'] !== $expectedClient) {
            return $this->result('refused', 'oauth_client_mismatch', $observation);
        }
        if ($data['company_verification_status'] === 'failed' || ($data['user_identity_verification_status'] ?? null) === 'failed') {
            return $this->result('refused', 'seller_authority_denied', $observation);
        }
        if ($data['company_verification_status'] !== 'verified' || ($data['user_identity_verification_status'] ?? null) !== 'verified') {
            return $this->result('unavailable', 'seller_verification_pending_or_missing', $observation);
        }

        return $this->result('passed', 'session_client_kyb_and_identity_match', $observation);
    }

    private function identity(array $data, VerificationObservation $company, VerificationObservation $session, ReadOnlyQualificationGateway $gateway): array
    {
        $row = $company->data;
        if (! is_int($row['id'] ?? null) || $row['id'] <= 0 || ! $this->date($row['created_at'] ?? null)
            || ! is_string($row['number'] ?? null) || ! is_string($row['country'] ?? null)
            || ! in_array($row['number_scheme'] ?? null, ['fr_siren', 'sandbox', 'be_numero_entreprise'], true)
            || ! in_array($row['env'] ?? null, ['sandbox', 'production'], true)) {
            return $this->result('ambiguous', 'malformed_or_unknown_company_state', $company);
        }
        $existing = $this->seller->assess($data, $row, $session->data, $gateway->expectedClientId, $gateway->environment);
        if ($row['id'] !== $gateway->expectedCompanyId || ! $existing['checks_passed_for_simulation']) {
            return $this->result('refused', 'historical_seller_or_connection_scope_mismatch', $company);
        }

        return $this->result('passed', 'historical_seller_matches_authenticated_company', $company);
    }

    private function publication(array $data, VerificationObservation $observation, array $company, string $environment): array
    {
        $body = $observation->data;
        if (! isset($body['data']) || ! is_array($body['data']) || ! array_is_list($body['data'])
            || array_diff(array_keys($body), ['data']) !== []) {
            return $this->result('ambiguous', 'owned_directory_incomplete_or_unknown_envelope', $observation);
        }
        $context = InvoiceEmissionContext::resolve($data, []);
        $address = $context['seller_electronic_address'];
        if ($address['scheme'] !== '0225' || ! preg_match('/\A[0-9]{9}\z/D', $address['value'])) {
            return $this->result('unavailable', 'bt34_format_not_supported_for_publication_check', $observation);
        }
        $matches = [];
        foreach ($body['data'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['company'] ?? null)
                || ! is_int($entry['id'] ?? null) || $entry['id'] <= 0 || ! is_string($entry['identifier'] ?? null)
                || ! $this->date($entry['created_at'] ?? null) || ! in_array($entry['directory'] ?? null, ['ppf', 'peppol'], true)
                || ! in_array($entry['status'] ?? null, ['pending', 'created', 'error'], true)) {
                return $this->result('ambiguous', 'owned_directory_malformed_entry', $observation);
            }
            foreach (['id', 'number_scheme', 'number', 'env'] as $key) {
                if (($entry['company'][$key] ?? null) !== $company[$key]) {
                    return $this->result('refused', 'owned_directory_foreign_company', $observation);
                }
            }
            if ($entry['identifier'] === '0225:'.$address['value'] && $entry['directory'] === ($environment === 'production' ? 'ppf' : 'peppol')) {
                $matches[] = $entry;
            }
        }
        if (count($matches) > 1) {
            return $this->result('ambiguous', 'multiple_matching_owned_addresses', $observation);
        }
        if ($matches === []) {
            return $this->result('unavailable', 'bt34_not_listed_as_receiving_address', $observation);
        }
        $entry = $matches[0];
        if (! is_bool($entry['is_replyto'] ?? null)) {
            return $this->result('unavailable', 'replyto_classification_missing', $observation);
        }
        if ($entry['is_replyto']) {
            return $this->result('refused', 'technical_replyto_address', $observation);
        }
        if ($entry['status'] === 'error') {
            return $this->result('refused', 'owned_address_registration_error', $observation);
        }
        if ($entry['status'] !== 'created') {
            return $this->result('unavailable', 'owned_address_registration_pending', $observation);
        }
        if (array_key_exists('effective_date', $entry)) {
            $date = is_string($entry['effective_date']) ? substr($entry['effective_date'], 0, 10) : '';
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                return $this->result('ambiguous', 'effective_date_invalid', $observation);
            }
            if ($date > now('Europe/Paris')->format('Y-m-d')) {
                return $this->result('unavailable', 'receiving_address_effective_in_future', $observation);
            }
        }

        return $this->result('passed', 'bt34_listed_for_receiving_only_not_sending', $observation);
    }

    private function result(string $status, string $reason, ?VerificationObservation $observation = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'source' => $observation?->source ?? 'not_called',
            'verified' => $status === 'passed' && $observation?->source === 'live',
            'response_sha256' => $observation?->sha256, 'observed_at' => $observation?->observedAt];
    }

    private function error(VerificationReadException $exception): array
    {
        return $this->result($exception->status, $exception->reason) + ['http_status' => $exception->httpStatus];
    }

    private function finish(User $actor, Invoice $invoice, array $report): array
    {
        $states = array_column($report['checks'], 'status');
        $report['status'] = in_array('refused', $states, true) ? 'refused' : (in_array('ambiguous', $states, true) ? 'ambiguous' : (in_array('unavailable', $states, true) ? 'unavailable' : 'passed'));
        $report['audit_reference'] = $this->audit->store($actor, $invoice, $report);

        return $report;
    }

    private function date(mixed $date): bool
    {
        return is_string($date) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $date) && strtotime($date) !== false;
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
