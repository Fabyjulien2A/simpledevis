<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use InvalidArgumentException;
use Throwable;

/** Préparation CII simulée ou via API séparée : aucun dépôt, aucune écriture SQL métier. */
final class InvoiceCiiPreparation
{
    public function __construct(
        private readonly InvoiceExportDataBuilder $source,
        private readonly SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        private readonly SuperPdpInvoicePayloadBuilder $builder,
        private readonly InvoiceRoutingQualification $routing,
        private readonly SellerEmissionQualification $seller,
        private readonly CiiDocumentChecks $checks,
        private readonly PreparedCiiArchive $archive,
    ) {}

    /**
     * Separate in-memory sandbox path: existing SDK conversion/report operations,
     * mandatory mock transport, no Invoice model, SQL, archive or emission transport.
     */
    public function prepareSandbox(
        array $data,
        SandboxEmissionContext $context,
        Company $company,
        OfflinePreparationGateway $gateway,
        ?array $frenchContext = null,
        ?SuperPdpConnection $connection = null,
        ?SandboxSellerEvidence $companyEvidence = null,
        ?SandboxSellerEvidence $sessionEvidence = null,
        ?SandboxSellerEvidence $directoryEvidence = null,
    ): array {
        $diagnose = fn () => app(SandboxPreparationDiagnostic::class)->diagnose($data, $context, $company,
            $frenchContext, $connection, $companyEvidence, $sessionEvidence, $directoryEvidence);
        $diagnostic = $diagnose();
        $result = ['mode' => 'offline_simulation', 'status' => 'blocked', 'ready_for_emission' => false,
            'deposit_performed' => false, 'document_validation' => 'not_performed',
            'official_archive' => false, 'artifact' => null, 'diagnostic' => $diagnostic];
        if (! $diagnostic['can_simulate_conversion']) {
            return $result + ['reason' => 'local_data_or_qualification_insufficient'];
        }
        $phase = 'conversion';
        try {
            $preparation = $diagnostic['preparation'];
            $xml = $gateway->convert($preparation['payload']);
            $this->checks->assertSandboxMatches($xml, $preparation, $context, $company);
            // Recheck freshness/ownership before each subsequent operation and on completion.
            if (! $diagnose()['can_simulate_conversion']) {
                return $result + ['reason' => 'qualification_changed_during_preparation'];
            }
            $phase = 'validation';
            $report = $gateway->validate($xml);
            $this->checks->assertReport($report, $xml);
            $fresh = $diagnose();
            if (! $fresh['can_simulate_conversion'] || $fresh['source_sha256'] !== $diagnostic['source_sha256']
                || $fresh['context_sha256'] !== $diagnostic['context_sha256']) {
                return $result + ['reason' => 'qualification_changed_during_preparation'];
            }

            return array_replace($result, [
                'status' => 'prepared_simulation', 'document_validation' => 'passed_simulation',
                'diagnostic' => $fresh, 'cii_sha256' => hash('sha256', $xml),
                'validation_report_sha256' => CiiPreparationEvidence::hash($report),
            ]);
        } catch (Throwable) {
            return $result + ['reason' => $phase.'_failed'];
        }
    }

    /** Documentary-only trial, not qualification, an official archive, or emission readiness. */
    public function convertSandboxScenario(
        SandboxEmissionContext $context,
        Company $company,
        SandboxInvoiceScenario $scenario,
        SandboxScenarioCiiGateway $gateway,
    ): array {
        $gateway->assertScope($context, $company, $scenario);
        $result = ['status' => 'blocked', 'ready_for_emission' => false, 'deposit_performed' => false,
            'official_archive' => false, 'routing_api_verified' => false, 'document_validation' => 'not_performed'];
        $phase = 'conversion';
        try {
            $conversion = $gateway->convert();
            $phase = 'validation';
            $validation = $gateway->validate($conversion->data['xml']);
            $gateway->assertScope($context, $company, $scenario);

            return array_replace($result, ['status' => 'documentary_test_completed',
                'document_validation' => $conversion->source === 'live' && $validation->source === 'live' ? 'passed_live' : 'passed_simulation',
                'conversion_source' => $conversion->source, 'validation_source' => $validation->source,
                'cii_sha256' => $conversion->sha256, 'validation_report_sha256' => $validation->sha256,
                'http_statuses' => $gateway->httpStatuses()]);
        } catch (Throwable) {
            return $result + ['reason' => $phase.'_failed', 'http_statuses' => $gateway->httpStatuses()];
        }
    }

    public function prepare(User $actor, Invoice $invoice, OfflinePreparationGateway $gateway, string $clientId, string $environment): array
    {
        if ($clientId === '' || ! in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Client OAuth et environnement attendus obligatoires.');
        }
        $stored = $this->owned($actor, $invoice);
        $result = ['mode' => 'offline_simulation', 'ready_for_emission' => false,
            'status' => 'blocked', 'routing' => null, 'seller' => null, 'artifact' => null];
        $diagnostic = $this->diagnostic->diagnose($stored);
        if (! $diagnostic['local_checks_passed']) {
            return $result + ['reason' => 'local_data_incomplete', 'blockers' => $diagnostic['blockers']];
        }
        $data = $this->source->buildElectronic($stored);
        if (($data['emission_profile'] ?? null) !== InvoiceEmissionContext::PROFILE || ($data['emission_context'] ?? null) === null) {
            return $result + ['reason' => 'frozen_emission_context_required'];
        }
        $phase = 'qualification';
        try {
            $fingerprint = $this->fingerprint($data);
            $siren = FrenchSellerIdentifiers::sirenFromSiret($data['buyer']['siret']);
            $result['routing'] = $this->routing->assess($data, $gateway->directory($siren));
            $result['seller'] = $this->seller->assess($data, $gateway->company(), $gateway->session(), $clientId, $environment);
            if (! $result['routing']['qualified_for_simulation'] || ! $result['seller']['checks_passed_for_simulation']) {
                return $result + ['reason' => 'qualification_not_sufficient'];
            }
            $phase = 'conversion';
            $payload = $this->builder->buildFromData($data);
            $xml = $gateway->convert($payload);
            $this->checks->assertMatches($xml, $payload);
            $phase = 'validation';
            $report = $gateway->validate($xml);
            $this->checks->assertReport($report, $xml);
            // Ne pas conserver un document issu de données devenues caduques pendant la préparation.
            $fresh = $this->owned($actor, $stored);
            if (! hash_equals($fingerprint, $this->fingerprint($this->source->buildElectronic($fresh)))) {
                return $result + ['reason' => 'invoice_changed_during_preparation'];
            }
            $phase = 'private_storage';
            $manifest = [
                'schema_version' => 1, 'mode' => 'offline_simulation', 'ready_for_emission' => false,
                'user_id' => $actor->id, 'invoice_id' => $stored->id,
                'prepared_at' => now()->toIso8601String(), 'source_sha256' => $fingerprint,
                'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
                'emission_context_sha256' => hash('sha256', $data['emission_context']),
                'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'cii_sha256' => hash('sha256', $xml), 'routing' => $result['routing'], 'seller' => $result['seller'],
                'validation' => ['status' => 'passed_simulation', 'report' => $report],
            ];
            $result['artifact'] = $this->archive->store($actor, $fresh, $xml, $manifest);
            $result['status'] = 'prepared_simulation';

            return $result;
        } catch (Throwable) {
            // Aucun corps de réponse, token, XML ou information d'un autre compte dans les erreurs.
            return $result + ['reason' => $phase.'_failed', 'message' => 'La préparation a été refusée. Aucun envoi ni statut d’émission n’a été enregistré.'];
        }
    }

    /** API preparation only: independent from and never opens the emission transport. */
    public function produce(User $actor, Invoice $invoice, string $verificationReference, ?SandboxCiiPreparationGateway $gateway = null): array
    {
        $stored = $this->owned($actor, $invoice);
        $result = ['status' => 'blocked', 'ready_for_emission' => false, 'deposit_performed' => false,
            'requires_api' => true, 'local_checks_passed' => false, 'artifact' => null];
        $diagnostic = $this->diagnostic->diagnose($stored);
        if (! $diagnostic['local_checks_passed']) {
            return $result + ['reason' => 'local_data_incomplete', 'blockers' => $diagnostic['blockers']];
        }
        $result['local_checks_passed'] = true;
        $phase = 'preparation_context';
        $failureStage = 'preparation_context';
        $failure = [];
        $claim = null;
        $registry = app(CiiPreparationRegistry::class);
        try {
            $data = $this->source->buildElectronic($stored);
            $fingerprint = $this->fingerprint($data);
            $payload = $this->builder->buildFromData($data);
            $previous = $registry->existing($actor, $stored, $gateway?->reservationMode() ?? 'api');
            if ($previous !== null) {
                if ($previous['state'] === 'completed') {
                    return $previous['result'] + ['requires_api' => false, 'local_checks_passed' => true, 'reason' => 'reservation_reused'];
                }

                return $result + ['reason' => $previous['state'] === 'running' ? 'preparation_in_progress' : 'preparation_review_required'];
            }
            $gateway ??= SandboxCiiPreparationGateway::forInvoice($actor, $stored, $fingerprint, $verificationReference);
            $gateway->assertScope($actor, $stored, $fingerprint, $verificationReference);
            $gateway->context();
            $reservation = $registry->reserve($actor, $stored, $gateway->reservationMode());
            if ($reservation['state'] === 'completed') {
                return $reservation['result'] + ['requires_api' => false, 'local_checks_passed' => true, 'reason' => 'reservation_reused'];
            }
            if ($reservation['state'] !== 'running' || ! isset($reservation['claim'])) {
                return $result + ['reason' => $reservation['state'] === 'running' ? 'preparation_in_progress' : 'preparation_review_required'];
            }
            $claim = $reservation['claim'];
            $phase = 'conversion';
            $failureStage = 'conversion.prerequisites';
            $conversion = $gateway->convert($payload);
            $failureStage = 'conversion.local_cii_checks';
            $this->checks->assertInvoiceMatches($conversion->data['xml'], $payload, $stored);
            $phase = 'validation';
            $failureStage = 'validation.prerequisites';
            $validation = null;
            $validationError = false;
            try {
                $validation = $gateway->validate($conversion->data['xml']);
            } catch (Throwable $exception) {
                // A known converted document survives an unavailable/uncertain validation
                // in a non-authorized archive. Never retry and never substitute a local pass.
                $validationError = true;
                $failure = $this->recordDiagnostic($actor, $stored, $fingerprint, $verificationReference,
                    $this->failureDiagnostic($exception, $failureStage, $gateway));
            }
            $fresh = $this->owned($actor, $stored);
            if (! hash_equals($fingerprint, $this->fingerprint($this->source->buildElectronic($fresh)))) {
                $registry->uncertain($actor, $fresh, $claim);
                return $result + ['reason' => 'invoice_changed_during_preparation'];
            }
            $phase = 'private_storage';
            $failureStage = 'private_storage';
            $artifact = $this->archive->storeProduced($actor, $fresh, $conversion, $validation, $verificationReference);
            $registry->complete($actor, $fresh, $claim, $artifact);
            $claim = null;
            $result['artifact'] = $artifact;
            $result['ready_for_emission'] = $artifact['ready_for_emission'];
            $result['status'] = match ($artifact['mode']) {
                'validated_private_document' => 'prepared_validated',
                'pending_validation' => 'prepared_pending_validation',
                default => 'prepared_simulation',
            };
            if (! $validationError && $artifact['validation_status'] === 'failed') {
                $failure = $this->recordDiagnostic($actor, $fresh, $fingerprint, $verificationReference,
                    ['operation' => 'validation', 'failure_stage' => 'validation.report_checks',
                        'failure_kind' => 'validation_report_rejected'] + $gateway->responseDiagnostic('validation'));
            }

            return $result + ['reason' => $validationError ? 'validation_unavailable' : $artifact['validation_status']] + $failure;
        } catch (Throwable $exception) {
            if ($claim !== null) {
                try {
                    $registry->uncertain($actor, $stored, $claim);
                } catch (Throwable) {
                    // A truncated journal or abandoned claim remains blocking, never recreated.
                }
            }
            if ($phase !== 'preparation_context') {
                $failure = $this->recordDiagnostic($actor, $stored, $fingerprint, $verificationReference,
                    $this->failureDiagnostic($exception, $failureStage, $gateway));
            }
            return $result + ['reason' => $phase.'_failed', 'message' => 'CII preparation refused. No deposit or invoice mutation.'] + $failure;
        }
    }

    private function failureDiagnostic(Throwable $exception, string $stage, ?SandboxCiiPreparationGateway $gateway): array
    {
        if ($exception instanceof CiiPreparationFailure) {
            return $exception->diagnostic;
        }
        $operation = str_starts_with($stage, 'validation.') ? 'validation' : 'conversion';
        $kind = 'local_prerequisite_failed';
        if ($stage === 'conversion.local_cii_checks') {
            $kind = match ($exception->getMessage()) {
                'XML CII mal formé.', 'CII vide, trop volumineux, DTD ou entité interdite.' => 'invalid_xml',
                default => 'local_cii_rejected',
            };
            if ($kind === 'invalid_xml') {
                $stage = 'conversion.xml';
            }
        } elseif ($stage === 'private_storage') {
            $kind = 'private_storage_failed';
        }
        return ['operation' => $operation, 'failure_stage' => $stage, 'failure_kind' => $kind]
            + ($gateway?->responseDiagnostic($operation) ?? []);
    }

    private function recordDiagnostic(User $actor, Invoice $invoice, string $sourceHash, string $reference, array $diagnostic): array
    {
        $result = array_intersect_key($diagnostic, array_flip(['failure_stage', 'failure_kind']));
        $report = ['schema_version' => 1, 'kind' => 'cii_preparation_diagnostic',
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $actor->id, 'invoice_id' => $invoice->id,
            'status' => 'failed', 'emission_ready' => false, 'ready_for_emission' => false,
            'checked_at' => now()->toIso8601String(), 'source_sha256' => $sourceHash,
            'verification_reference' => $reference, 'diagnostic' => $diagnostic];
        try {
            $result['diagnostic_reference'] = app(Verification\VerificationAuditStore::class)->store($actor, $invoice, $report);
            $result['diagnostic_status'] = 'stored';
        } catch (Throwable) {
            // Diagnostic storage cannot bypass or reset the reservation and its uncertain result.
            $result['diagnostic_status'] = 'unavailable';
        }
        return $result;
    }

    private function owned(User $actor, Invoice $invoice): Invoice
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);

        return $stored;
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
