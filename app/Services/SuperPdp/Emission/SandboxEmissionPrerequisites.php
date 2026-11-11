<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use RuntimeException;

/** Local reads only. A simulated archive/report can never authorize a deposit. */
final class SandboxEmissionPrerequisites implements EmissionPrerequisites
{
    public function __construct(
        private readonly SandboxEmissionAuthorization $authorization,
        private readonly PreparedCiiArchive $archives,
        private readonly InvoiceExportDataBuilder $source,
        private readonly SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        private readonly SuperPdpInvoicePayloadBuilder $builder,
        private readonly CiiDocumentChecks $checks,
        private readonly VerificationAuditStore $audits,
    ) {}

    public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument
    {
        $this->authorization->approval();
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && $stored->user_id === $actor->id && $stored->isIssued(), 403);
        $connection = $this->authorization->connection($actor->id, $stored->id, $reference);
        if (! $this->diagnostic->diagnose($stored)['local_checks_passed']) {
            throw new RuntimeException('Local invoice prerequisites failed.');
        }
        $archive = $this->archives->readValidated($actor, $stored, $reference);
        $m = $archive['manifest'];
        $data = $this->source->buildElectronic($stored);
        $payload = $this->builder->build($stored);
        $sourceHash = $this->hash($data);
        if (($m['environment'] ?? null) !== 'sandbox' || ($m['api_url'] ?? null) !== SandboxEmissionAuthorization::API_URL
            || ($m['oauth_client_id'] ?? null) !== $connection->oauth_client_id
            || ($m['provider_company_id'] ?? null) !== $connection->superpdp_company_id
            || ($m['source_sha256'] ?? null) !== $sourceHash || ($m['payload_sha256'] ?? null) !== $this->hash($payload)
            || ($m['party_snapshot_sha256'] ?? null) !== hash('sha256', $data['party_snapshot'])
            || ($m['emission_context_sha256'] ?? null) !== hash('sha256', $data['emission_context'])
            || ($m['validation']['status'] ?? null) !== 'passed' || ($m['validation']['source'] ?? null) !== 'live'
            || ($m['validation']['cii_sha256'] ?? null) !== hash('sha256', $archive['xml'])
            || ! is_array($m['validation']['report'] ?? null) || ! is_string($m['verification_reference'] ?? null)) {
            throw new RuntimeException('Validated CII does not match the sandbox context and current immutable sources.');
        }
        $this->fresh($m['prepared_at'] ?? null);
        $this->fresh($m['validation']['observed_at'] ?? null);
        $report = $this->audits->read($actor, $stored, $m['verification_reference']);
        if (($report['status'] ?? null) !== 'passed' || ($report['expected_environment'] ?? null) !== 'sandbox'
            || ($report['expected_provider_company_id'] ?? null) !== $connection->superpdp_company_id
            || ($report['expected_oauth_client_id'] ?? null) !== $connection->oauth_client_id
            || ($report['expected_api_url'] ?? null) !== SandboxEmissionAuthorization::API_URL
            || ($report['source_sha256'] ?? null) !== $sourceHash
            || ($report['party_snapshot_sha256'] ?? null) !== $m['party_snapshot_sha256']
            || ($report['emission_context_sha256'] ?? null) !== $m['emission_context_sha256']) {
            throw new RuntimeException('Real sandbox verification evidence required.');
        }
        $this->fresh($report['checked_at'] ?? null);
        foreach (['buyer_destination', 'seller_identity', 'seller_session'] as $key) {
            $check = $report['checks'][$key] ?? [];
            if (($check['status'] ?? null) !== 'passed' || ($check['source'] ?? null) !== 'live' || ($check['verified'] ?? null) !== true
                || ! is_string($check['response_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $check['response_sha256'])) {
                throw new RuntimeException('Simulated cached or incomplete evidence refused.');
            }
            $this->fresh($check['observed_at'] ?? null);
        }
        $this->checks->assertMatches($archive['xml'], $payload);
        $this->checks->assertReport($m['validation']['report'], $archive['xml']);
        $document = new EmissionDocument($actor->id, $stored->id, $reference, $archive['xml'],
            $connection->superpdp_company_id, 'sandbox', 'validated_private_document');
        $this->authorization->document($document);

        return $document;
    }

    private function fresh(mixed $timestamp): void
    {
        if (! is_string($timestamp) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $timestamp)
            || ($time = strtotime($timestamp)) === false || $time > now()->timestamp || $time < now()->subMinutes(15)->timestamp) {
            throw new RuntimeException('Fresh timestamped real evidence required (maximum 15 minutes).');
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
