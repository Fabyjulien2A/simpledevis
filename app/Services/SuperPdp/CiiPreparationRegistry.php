<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use RuntimeException;
use Throwable;

/** Reservation is not authorization. The existing gateway must still authorize every API operation. */
final class CiiPreparationRegistry
{
    public function __construct(
        private readonly InvoiceExportDataBuilder $source,
        private readonly SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        private readonly SuperPdpInvoicePayloadBuilder $builder,
        private readonly PreparedCiiArchive $archive,
        private readonly CiiPreparationGuards $guards,
        private readonly CiiDocumentChecks $checks,
    ) {}

    public function reserve(User $actor, Invoice $invoice, string $mode): array
    {
        $context = $this->context($actor, $invoice, $mode);
        $journal = new CiiPreparationJournal($this->archive->reservationDirectory());
        $reservation = $journal->reserve($context['binding'], now()->timestamp);
        if ($reservation['result'] !== null) {
            $reservation['result'] = $this->checkedResult($actor, $context, $journal, $reservation['result']);
        }

        return $reservation;
    }

    /** Completed documents can be consulted without renewing the original API approval/proofs. */
    public function existing(User $actor, Invoice $invoice, string $mode): ?array
    {
        $context = $this->context($actor, $invoice, $mode);
        $directory = $this->archive->reservationDirectory(false);
        if ($directory === null) {
            return null;
        }
        $journal = new CiiPreparationJournal($directory);
        $reservation = $journal->read($context['binding']);
        if (($reservation['result'] ?? null) !== null) {
            $reservation['result'] = $this->checkedResult($actor, $context, $journal, $reservation['result']);
        }
        if (($reservation['state'] ?? null) === 'running') {
            // Existing slot only: expire to review, never issue a replacement capability.
            $reservation = $journal->reserve($context['binding'], now()->timestamp);
        }

        return $reservation;
    }

    public function complete(User $actor, Invoice $invoice, CiiPreparationClaim $claim, array $artifact): array
    {
        $context = $this->context($actor, $invoice, $claim->binding()['operation_mode']);
        abort_unless(CiiPreparationJournal::key($context['binding']) === $claim->id, 409);
        $journal = new CiiPreparationJournal($this->archive->reservationDirectory(false));
        $journal->assertClaim($context['binding'], $claim);
        try {
            $result = $this->checkedResult($actor, $context, $journal, $artifact);
        } catch (Throwable $exception) {
            $journal->finish($context['binding'], $claim, null, false, now()->timestamp);
            throw $exception;
        }
        $completed = ($context['binding']['operation_mode'] === 'simulation'
            && $result['status'] === 'prepared_simulation' && $result['artifact']['validation_status'] === 'passed_simulation')
            || ($context['binding']['operation_mode'] === 'api' && $result['status'] === 'prepared_validated');

        return $journal->finish($context['binding'], $claim, $result, $completed, now()->timestamp);
    }

    public function uncertain(User $actor, Invoice $invoice, CiiPreparationClaim $claim): void
    {
        $stored = $this->owned($actor, $invoice);
        $binding = $claim->binding();
        abort_unless($binding['user_id'] === $actor->id && $binding['invoice_id'] === $stored->id, 403);
        $company = Company::on($stored->getConnectionName())->where('user_id', $actor->id)->firstOrFail();
        abort_unless($company->id === $binding['company_id'], 403);
        $journal = new CiiPreparationJournal($this->archive->reservationDirectory(false));
        $journal->finish($binding, $claim, null, false, now()->timestamp);
    }

    /** Authenticated server-side read. Returns metadata only; never a filesystem path or send permission. */
    public function result(User $actor, Invoice $invoice, string $id, string $mode): ?array
    {
        $context = $this->context($actor, $invoice, $mode);
        abort_unless(hash_equals(CiiPreparationJournal::key($context['binding']), $id), 403);
        $directory = $this->archive->reservationDirectory(false);
        if ($directory === null) {
            return null;
        }
        $journal = new CiiPreparationJournal($directory);
        $reservation = $journal->read($context['binding']);
        if (($reservation['result'] ?? null) !== null) {
            $reservation['result'] = $this->checkedResult($actor, $context, $journal, $reservation['result']);
        }

        return $reservation;
    }

    /** GET-safe API: no expiry transition, quarantine, chmod, reservation or directory creation. */
    public function consult(User $actor, Invoice $invoice, ?string $id = null, bool $withDocument = false): ?array
    {
        $stored = $this->owned($actor, $invoice);
        $directory = $this->archive->reservationDirectory(false);
        // No journal means no reservation to consult. Preparation configuration
        // must not turn an absent first attempt into an uncertain operation.
        // An explicit download ID still requires the complete binding checks.
        if ($directory === null && $id === null) {
            return null;
        }
        $context = $this->context($actor, $stored, 'api');
        $expected = CiiPreparationJournal::key($context['binding']);
        abort_unless($id === null || hash_equals($expected, $id), 403);
        if ($directory === null) {
            return null;
        }
        $journal = new CiiPreparationJournal($directory);
        $reservation = $journal->read($context['binding']);
        if ($reservation === null) {
            return null;
        }
        if ($reservation['state'] === 'running' && now()->timestamp >= $reservation['reserved_at'] + 900) {
            // Display-only projection. The persistent state remains untouched by consultation.
            $reservation['state'] = 'review_required';
            $reservation['reason'] = 'interrupted_or_expired';
        }
        if ($reservation['state'] === 'review_required' && $reservation['reason'] === 'result_integrity') {
            $reservation['result'] = null;
        }
        if ($reservation['result'] !== null) {
            $reservation['result'] = $this->checkedResult($actor, $context, $journal, $reservation['result'], false, $withDocument);
        }

        return $reservation;
    }

    private function owned(User $actor, Invoice $invoice): Invoice
    {
        abort_unless($actor->exists && auth()->id() === $actor->id && $invoice->exists, 403);
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($stored->user_id === $actor->id, 403);
        abort_unless($stored->isIssued(), 409);

        return $stored;
    }

    private function context(User $actor, Invoice $invoice, string $mode): array
    {
        $stored = $this->owned($actor, $invoice);
        if (! in_array($mode, ['api', 'simulation'], true) || ($mode === 'simulation' && ! app()->environment('testing'))) {
            throw new RuntimeException('Mode de réservation CII interdit.');
        }
        $client = $this->guards->configuration();
        if (! $this->diagnostic->diagnose($stored)['local_checks_passed']) {
            throw new RuntimeException('Les données figées ne permettent pas une réservation CII.');
        }
        $data = $this->source->buildElectronic($stored);
        $payload = $this->builder->buildFromData($data);
        $company = Company::on($stored->getConnectionName())->where('user_id', $actor->id)->firstOrFail();
        $connection = $company->superPdpConnection()->firstOrFail();
        // Reading a previous result does not renew tokens or turn old proofs into fresh evidence.
        $connection->assertOAuthContext('sandbox', $client, Emission\SandboxEmissionAuthorization::API_URL);
        if (! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0) {
            throw new RuntimeException('Identifiant fournisseur requis pour la réservation CII.');
        }
        $binding = [
            'schema_version' => 1, 'user_id' => $actor->id, 'company_id' => $company->id, 'invoice_id' => $stored->id,
            'database_scope' => CiiPreparationEvidence::hash([$stored->getConnectionName(), $stored->getConnection()->getDatabaseName()]),
            'connection_id' => $connection->id, 'environment' => $connection->environment,
            'oauth_client_id' => $connection->oauth_client_id, 'api_url' => $connection->api_url,
            'provider_company_id' => $connection->superpdp_company_id, 'directory_identifier' => $connection->directory_identifier,
            'operation_mode' => $mode, 'contract_version' => SuperPdpInvoicePayloadValidator::SCHEMA_VERSION,
            'source_sha256' => CiiPreparationEvidence::hash($data), 'payload_sha256' => CiiPreparationEvidence::hash($payload),
            'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
            'emission_context_sha256' => hash('sha256', $data['emission_context']),
        ];

        return ['binding' => $binding, 'invoice' => $stored, 'payload' => $payload];
    }

    private function checkedResult(User $actor, array $context, CiiPreparationJournal $journal, array $artifact,
        bool $quarantine = true, bool $withDocument = false): array
    {
        // Accept a producer artifact or the metadata persisted by a previous completion.
        $artifact = $artifact['artifact'] ?? $artifact;
        try {
            $reference = $artifact['reference'] ?? null;
            if (! is_string($reference) || ! preg_match('~\Ainvoice-cii/v1/(validated|pending|simulations)/[1-9][0-9]*/[1-9][0-9]*/([a-f0-9]{64})\z~D', $reference, $match)) {
                throw new RuntimeException;
            }
            $packet = match ($match[1]) {
                'validated' => $this->archive->readValidated($actor, $context['invoice'], $reference),
                'pending' => $this->archive->readPending($actor, $context['invoice'], $reference),
                default => $this->archive->read($actor, $context['invoice'], $reference),
            };
            $manifest = $packet['manifest'];
            foreach (['user_id', 'invoice_id', 'source_sha256', 'payload_sha256', 'party_snapshot_sha256',
                'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id'] as $field) {
                if (($manifest[$field] ?? null) !== $context['binding'][$field]) {
                    throw new RuntimeException;
                }
            }
            if (($artifact['manifest_sha256'] ?? null) !== $match[2]
                || ($artifact['cii_sha256'] ?? null) !== hash('sha256', $packet['xml'])
                || ($manifest['cii_sha256'] ?? null) !== $artifact['cii_sha256']) {
                throw new RuntimeException;
            }
            $this->checks->assertInvoiceMatches($packet['xml'], $context['payload'], $context['invoice']);
            if (in_array($manifest['validation']['status'] ?? null, ['passed', 'passed_simulation'], true)) {
                $this->checks->assertReport($manifest['validation']['report'] ?? [], $packet['xml']);
            }
            $status = match ($match[1]) {
                'validated' => 'prepared_validated', 'pending' => 'prepared_pending_validation', default => 'prepared_simulation',
            };

            return ['status' => $status, 'ready_for_emission' => false, 'deposit_performed' => false,
                'artifact' => ['reference' => $reference, 'manifest_sha256' => $match[2],
                    'cii_sha256' => $manifest['cii_sha256'], 'validation_status' => $manifest['validation']['status'] ?? 'unavailable']]
                + ($withDocument ? ['document' => $packet['xml']] : []);
        } catch (Throwable) {
            if ($quarantine) {
                $journal->quarantine($context['binding'], now()->timestamp);
            }
            throw new RuntimeException('Résultat CII absent, altéré ou incompatible ; examen manuel requis, aucune nouvelle conversion.');
        }
    }
}
