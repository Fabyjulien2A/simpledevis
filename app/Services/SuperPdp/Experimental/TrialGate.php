<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\Emission\SandboxCiiProfile;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SellerEmissionQualification;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use DateTimeImmutable;
use RuntimeException;

/** Pure local checks. No connector, SQL, refresh, route, binding or production gate. */
final class TrialGate
{
    public function inspect(
        TrialPackage $package,
        SandboxEmissionContext $context,
        Company $company,
        SuperPdpConnection $connection,
        TrialSellerEvidence $companyEvidence,
        TrialSellerEvidence $sessionEvidence,
        TrialSellerEvidence $directoryEvidence,
    ): array {
        $context->assertFor($company);
        $m = $package->manifest;
        $context->assertMatches($m['context']);
        if (PHP_SAPI !== 'cli' || $m['profile'] !== TrialPackage::PROFILE || $m['owner_user_id'] !== auth()->id()
            || $connection->company_id !== 3 || $connection->superpdp_company_id !== 18172
            || $m['oauth_client_id'] !== config('services.superpdp.client_id')
            || $package->manifest['cii_sha256'] !== hash('sha256', $package->xml)
            || $m['source_sha256'] !== CiiPreparationEvidence::hash([$m['invoice'], $m['french_context']])
            || $m['payload_sha256'] !== CiiPreparationEvidence::hash($m['payload'])
            || $m['routing']['api_verified'] !== false || $m['disable_pre_check'] !== false) {
            throw new RuntimeException('Experimental sandbox scope or immutable package mismatch.');
        }
        self::observed($m['routing']['confirmed_at']);
        foreach (['conversion', 'validation'] as $key) {
            self::observed($m[$key]['observed_at']);
            if ($m[$key]['source'] !== 'live') {
                throw new RuntimeException('Real provider conversion and validation required.');
            }
        }
        $scenario = SandboxInvoiceScenario::forContext($context, $company, $m['invoice']['date'], $m['invoice']['due_date']);
        if ($m['source_sha256'] !== CiiPreparationEvidence::hash([$scenario->data(), $scenario->frenchContext()])) {
            throw new RuntimeException('Frozen invoice differs from the approved experimental scenario.');
        }
        app(CiiDocumentChecks::class)->assertSandboxMatches($package->xml,
            ['profile' => SandboxCiiProfile::PROFILE, 'ready_for_emission' => false, 'payload' => $m['payload']], $context, $company);
        app(CiiDocumentChecks::class)->assertReport($m['validation']['report'], $package->xml);
        $access = $connection->access_token;
        if (! $connection->isConnected() || ! is_string($access) || $access === '' || $connection->tokenIsExpired()) {
            throw new RuntimeException('Decryptable, active OAuth credentials required; no refresh.');
        }
        foreach ([$companyEvidence, $sessionEvidence, $directoryEvidence] as $evidence) {
            if (! $evidence->matchesToken($access)) {
                throw new RuntimeException('Seller receipts do not belong to the current OAuth token.');
            }
        }
        $seller = app(SellerEmissionQualification::class)->assessSandbox($context, $company, $connection,
            $companyEvidence->proof, $sessionEvidence->proof, $directoryEvidence->proof);
        if (! $seller['verified']) {
            throw new RuntimeException('Fresh live seller identity, session and address required.');
        }

        return ['experimental_prerequisites_passed' => true, 'ready_for_emission' => false,
            'network_transport_implemented' => true, 'routing_api_verified' => false,
            'package_sha256' => $package->sha256(), 'seller' => $seller];
    }

    /** No request input/config flag can supply approval implicitly. This never sends. */
    public function assertApproval(TrialPackage $package, array $approval, string $attemptReference): void
    {
        $this->assertAuthorization($package, $approval, $attemptReference, 'single_deposit');
    }

    public function assertReconciliationApproval(TrialPackage $package, array $approval, string $attemptReference, int $remoteId): void
    {
        $this->assertAuthorization($package, $approval, $attemptReference, 'reconciliation_read');
        if ($remoteId <= 0 || ($approval['provider_invoice_id'] ?? null) !== $remoteId) {
            throw new RuntimeException('Explicit reconciliation remote ID required.');
        }
    }

    public function assertEventApproval(TrialPackage $package, array $approval, string $attemptReference, int $remoteId): void
    {
        $this->assertAuthorization($package, $approval, $attemptReference, 'event_read');
        if ($remoteId <= 0 || ($approval['provider_invoice_id'] ?? null) !== $remoteId) {
            throw new RuntimeException('Explicit outgoing event invoice ID required.');
        }
    }

    private function assertAuthorization(TrialPackage $package, array $approval, string $attemptReference, string $purpose): void
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        $m = $package->manifest;
        if (! auth()->check() || auth()->id() !== $m['owner_user_id']
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== $m['api_url']
            || config('services.superpdp.client_id') !== $m['oauth_client_id']
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $attemptReference)
            || ($approval['profile'] ?? null) !== TrialPackage::PROFILE
            || ($approval['purpose'] ?? null) !== $purpose
            || ($approval['environment'] ?? null) !== 'sandbox'
            || ($approval['owner_user_id'] ?? null) !== $m['owner_user_id']
            || ($approval['local_company_id'] ?? null) !== 3 || ($approval['provider_company_id'] ?? null) !== 18172
            || ($approval['buyer_identity'] ?? null) !== ['number_scheme' => 'sandbox', 'number' => '000000001']
            || ($approval['recipient'] ?? null) !== '0225:315143296_18171'
            || ($approval['routing_evidence'] ?? null) !== 'operator_confirmed_without_api_proof'
            || ($approval['disable_pre_check'] ?? null) !== false
            || ($approval['oauth_client_id'] ?? null) !== $m['oauth_client_id']
            || ($approval['api_url'] ?? null) !== $m['api_url']
            || ($approval['package_sha256'] ?? null) !== $package->sha256()
            || ($approval['cii_sha256'] ?? null) !== $m['cii_sha256']
            || ($approval['attempt_reference'] ?? null) !== $attemptReference
            || ! is_string($approval['approved_by'] ?? null) || trim($approval['approved_by']) === ''
            || ! is_int($approval['approved_at'] ?? null) || ! is_int($approval['expires_at'] ?? null)
            || $approval['approved_at'] > now()->timestamp || $approval['expires_at'] <= now()->timestamp
            || $approval['expires_at'] <= $approval['approved_at'] || $approval['expires_at'] - $approval['approved_at'] > 900) {
            throw new RuntimeException('Explicit scoped experimental deposit approval required.');
        }
    }

    /** Request specification only: no connector or send operation exists in this module. */
    public function requestPlan(TrialPackage $package, string $attemptReference, array $approval): CreateInvoiceRequest
    {
        $this->assertApproval($package, $approval, $attemptReference);

        return new CreateInvoiceRequest($package->xml, 'application/xml', $attemptReference, false);
    }

    /** Full recheck immediately before a durable claim. Still performs no HTTP request. */
    public function prepareClaim(
        TrialPackage $package,
        SandboxEmissionContext $context,
        Company $company,
        SuperPdpConnection $connection,
        TrialSellerEvidence $companyEvidence,
        TrialSellerEvidence $sessionEvidence,
        TrialSellerEvidence $directoryEvidence,
        string $archivePath,
        TrialLedger $ledger,
        array $approval,
    ): array {
        $this->inspect($package, $context, $company, $connection, $companyEvidence, $sessionEvidence, $directoryEvidence);
        TrialPackage::verifyArchive($archivePath, $package->sha256());

        return $ledger->claim($package, $approval, $context, $company, $connection,
            $companyEvidence, $sessionEvidence, $directoryEvidence, $archivePath);
    }

    public static function fresh(string $timestamp): void
    {
        if (self::observed($timestamp) < now()->timestamp - 900) {
            throw new RuntimeException('Evidence must be fresh (15 minutes maximum).');
        }
    }

    public static function observed(string $timestamp): int
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $timestamp)) {
            throw new RuntimeException('Strict timestamp with timezone required.');
        }
        $date = new DateTimeImmutable($timestamp);
        $errors = DateTimeImmutable::getLastErrors();
        if (($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->getTimestamp() > now()->timestamp) {
            throw new RuntimeException('Valid observation required, never future-dated.');
        }

        return $date->getTimestamp();
    }
}
