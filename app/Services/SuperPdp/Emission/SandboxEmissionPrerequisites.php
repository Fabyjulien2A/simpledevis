<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationGuards;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SandboxInvoiceEvidence;
use App\Services\SuperPdp\SandboxInvoiceProfile;
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
        $sandboxProfile = SandboxInvoiceProfile::is($stored);
        $report = $sandboxProfile
            ? app(CiiPreparationEvidence::class)->assess($actor, $stored, $data, $connection, $m['verification_reference'])['report']
            : $this->audits->read($actor, $stored, $m['verification_reference']);
        if (! $sandboxProfile && ($report['kind'] ?? null) === SandboxInvoiceEvidence::KIND) {
            throw new RuntimeException('Une preuve sandbox ne qualifie pas une facture française.');
        }
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
        $revalidated = array_key_exists('revalidated_from', $m);
        $requiredChecks = ['buyer_destination', 'seller_identity', 'seller_session'];
        if ($revalidated && $sandboxProfile) {
            $requiredChecks[] = 'seller_directory';
        }
        foreach ($requiredChecks as $key) {
            $check = $report['checks'][$key] ?? [];
            if (($check['status'] ?? null) !== 'passed' || ($check['source'] ?? null) !== 'live' || ($check['verified'] ?? null) !== true
                || ! is_string($check['response_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $check['response_sha256'])) {
                throw new RuntimeException('Simulated cached or incomplete evidence refused.');
            }
            $this->fresh($check['observed_at'] ?? null);
        }
        $this->checks->assertInvoiceMatches($archive['xml'], $payload, $stored);
        $this->checks->assertReport($m['validation']['report'], $archive['xml']);
        if ($revalidated) {
            $this->assertRevalidationLineage($actor, $stored, $reference, $archive);
            if ($sandboxProfile) {
                $this->assertRevalidationProofs($stored, $connection, $m, $report);
            }
        }
        $document = new EmissionDocument($actor->id, $stored->id, $reference, $archive['xml'],
            $connection->superpdp_company_id, 'sandbox', 'validated_private_document');
        $this->authorization->document($document);

        return $document;
    }

    private function assertRevalidationLineage(User $actor, Invoice $invoice, string $reference, array $archive): void
    {
        $manifest = $archive['manifest'];
        $parentReference = $manifest['revalidated_from'];
        if (! is_string($parentReference) || $parentReference === $reference) {
            throw new RuntimeException('Invalid original CII archive reference.');
        }
        $parent = $this->archives->readValidated($actor, $invoice, $parentReference);
        $original = $parent['manifest'];
        foreach (['source_sha256', 'payload_sha256', 'party_snapshot_sha256', 'emission_context_sha256', 'cii_sha256',
            'environment', 'api_url', 'oauth_client_id', 'provider_company_id'] as $field) {
            if (($original[$field] ?? null) !== $manifest[$field]) {
                throw new RuntimeException('Revalidation does not match the original CII fingerprints and OAuth context.');
            }
        }
        if ($parent['xml'] !== $archive['xml'] || ($original['validation']['status'] ?? null) !== 'passed'
            || ($original['validation']['source'] ?? null) !== 'live'
            || ($original['validation']['cii_sha256'] ?? null) !== $manifest['cii_sha256']
            || ! is_string($original['verification_reference'] ?? null)
            || $original['verification_reference'] === $manifest['verification_reference']) {
            throw new RuntimeException('Revalidation requires the original validated bytes and a new qualification report.');
        }
    }

    private function assertRevalidationProofs(Invoice $invoice, SuperPdpConnection $seller, array $manifest, array $report): void
    {
        foreach (['payload_sha256', 'cii_sha256'] as $field) {
            if (($report[$field] ?? null) !== $manifest[$field]) {
                throw new RuntimeException('Qualification report does not match the revalidated CII.');
            }
        }
        if (($report['cii_archive_reference'] ?? null) !== $manifest['revalidated_from']) {
            throw new RuntimeException('Qualification report does not identify the original CII archive.');
        }
        $context = $invoice->emission_context;
        $buyerId = $context['buyer_company_id'];
        $buyer = Company::on($invoice->getConnectionName())->where('user_id', $buyerId)->findOrFail($buyerId)
            ->superPdpConnection()->sole();
        app(CiiPreparationGuards::class)->connection($buyer, $seller->oauth_client_id);
        if ($seller->id !== $context['seller_connection_id'] || $buyer->id !== $context['buyer_connection_id']
            || $seller->directory_identifier !== $context['declaration']['seller']['superpdp_identity']['number']
            || $buyer->directory_identifier !== $report['buyer_destination']['identity']['number']
            || $buyer->superpdp_company_id !== $report['buyer_destination']['provider_company_id']) {
            throw new RuntimeException('Revalidation proof OAuth identities do not match the frozen parties.');
        }
        $fingerprints = [
            $seller->id => hash('sha256', $seller->access_token),
            $buyer->id => hash('sha256', $buyer->access_token),
        ];
        foreach ([
            'seller_identity' => [$seller, '/companies/me'],
            'seller_session' => [$seller, '/oauth2_sessions/me'],
            'seller_directory' => [$seller, '/directory_entries'],
            'buyer_destination' => [$buyer, '/directory_entries'],
        ] as $key => [$connection, $path]) {
            $receipt = $report['sdk_provenance'][$key] ?? [];
            $check = $report['checks'][$key];
            if (($receipt['method'] ?? null) !== 'GET' || ($receipt['endpoint'] ?? null) !== SandboxEmissionAuthorization::API_URL.$path
                || ($receipt['http_status'] ?? null) !== 200 || ($receipt['source'] ?? null) !== 'live'
                || ($receipt['connection_id'] ?? null) !== $connection->id
                || ($receipt['provider_company_id'] ?? null) !== $connection->superpdp_company_id
                || ($receipt['credential_sha256'] ?? null) !== $fingerprints[$connection->id]
                || ($receipt['observed_at'] ?? null) !== $check['observed_at']
                || ($receipt['response_sha256'] ?? null) !== $check['response_sha256']) {
                throw new RuntimeException('Live SDK provenance does not match the qualification proof and OAuth connection.');
            }
        }
    }

    private function fresh(mixed $timestamp): void
    {
        if (! is_string($timestamp) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $timestamp)
            || ($time = strtotime($timestamp)) === false || $time > now()->timestamp || $time < now()->subMinutes(15)->timestamp) {
            throw new RuntimeException('Fresh timestamped real evidence required (maximum 15 minutes).');
        }
    }

    /** Exact private evidence binding, checked again immediately before the claim. */
    public function binding(User $actor, Invoice $invoice, string $reference): string
    {
        $document = $this->authorize($actor, $invoice, $reference);
        $archive = $this->archives->readValidated($actor, $invoice, $reference);

        return $this->hash([
            'user_id' => $actor->id, 'invoice_id' => $invoice->id,
            'document_reference' => $reference, 'cii_sha256' => $document->sha256,
            'approval' => $this->authorization->approval(),
            'manifest' => $archive['manifest'],
            'evidence' => $this->audits->read($actor, $invoice, $archive['manifest']['verification_reference']),
        ]);
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
