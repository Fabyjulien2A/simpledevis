<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
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
        try {
            $data = $this->source->buildElectronic($stored);
            $fingerprint = $this->fingerprint($data);
            $payload = $this->builder->buildFromData($data);
            $gateway ??= SandboxCiiPreparationGateway::forInvoice($actor, $stored, $fingerprint, $verificationReference);
            $gateway->assertScope($actor, $stored, $fingerprint, $verificationReference);
            $gateway->context();
            $phase = 'conversion';
            $conversion = $gateway->convert($payload);
            $this->checks->assertMatches($conversion->data['xml'], $payload);
            $phase = 'validation';
            $validation = null;
            $validationError = false;
            try {
                $validation = $gateway->validate($conversion->data['xml']);
            } catch (Throwable) {
                // A known converted document survives an unavailable/uncertain validation
                // in a non-authorized archive. Never retry and never substitute a local pass.
                $validationError = true;
            }
            $fresh = $this->owned($actor, $stored);
            if (! hash_equals($fingerprint, $this->fingerprint($this->source->buildElectronic($fresh)))) {
                return $result + ['reason' => 'invoice_changed_during_preparation'];
            }
            $phase = 'private_storage';
            $artifact = $this->archive->storeProduced($actor, $fresh, $conversion, $validation, $verificationReference);
            $result['artifact'] = $artifact;
            $result['ready_for_emission'] = $artifact['ready_for_emission'];
            $result['status'] = match ($artifact['mode']) {
                'validated_private_document' => 'prepared_validated',
                'pending_validation' => 'prepared_pending_validation',
                default => 'prepared_simulation',
            };

            return $result + ['reason' => $validationError ? 'validation_unavailable' : $artifact['validation_status']];
        } catch (Throwable) {
            return $result + ['reason' => $phase.'_failed', 'message' => 'CII preparation refused. No deposit or invoice mutation.'];
        }
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
