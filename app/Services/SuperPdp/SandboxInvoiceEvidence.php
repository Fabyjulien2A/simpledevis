<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Support\Str;
use RuntimeException;

/** SDK receipts from separately authorized reads. Never performs a request or impersonates owner 4. */
final class SandboxInvoiceEvidence
{
    public const KIND = 'sandbox_invoice_parties';

    public function record(User $actor, Invoice $invoice, SandboxSellerEvidence $company,
        SandboxSellerEvidence $session, SandboxSellerEvidence $directory, SandboxSellerEvidence $buyerDirectory): array
    {
        SandboxInvoiceProfile::assertRuntime();
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && auth()->id() === 3 && $actor->id === 3 && $stored->user_id === 3 && SandboxInvoiceProfile::is($stored), 403);
        $data = app(InvoiceExportDataBuilder::class)->buildElectronic($stored);
        app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data);
        $localCompany = Company::on($stored->getConnectionName())->findOrFail(3);
        $connection = $localCompany->superPdpConnection()->sole();
        $context = SandboxEmissionContext::forCompany($localCompany);
        $seller = app(SellerEmissionQualification::class)->assessSandbox($context, $localCompany, $connection, $company, $session, $directory);
        $credentials = $company->usesAccessToken((string) $connection->access_token)
            && $session->usesAccessToken((string) $connection->access_token) && $directory->usesAccessToken((string) $connection->access_token);
        $buyerCompany = Company::on($stored->getConnectionName())->where('user_id', 4)->findOrFail(4);
        $buyerAssessment = $this->assessBuyer($buyerDirectory, $buyerCompany->superPdpConnection()->sole());
        $buyer = $buyerAssessment['status'] === 'matched';
        $sellerPassed = $seller['checks_passed_for_simulation'] && $credentials;
        $check = fn ($passed, $proof) => ['status' => $passed ? 'passed' : 'refused', 'source' => $proof->source,
            'verified' => $passed && $proof->source === 'live', 'response_sha256' => $proof->responseSha256,
            'observed_at' => $proof->observedAt];
        $report = ['schema_version' => 1, 'kind' => self::KIND, 'id' => (string) Str::uuid(),
            'user_id' => 3, 'invoice_id' => $stored->id, 'emission_profile' => SandboxInvoiceProfile::PROFILE,
            'status' => $sellerPassed && $buyer ? 'passed' : 'refused', 'emission_ready' => false, 'ready_for_emission' => false,
            'checked_at' => now()->toIso8601String(), 'expected_environment' => 'sandbox',
            'expected_provider_company_id' => 18172, 'expected_oauth_client_id' => $connection->oauth_client_id,
            'expected_api_url' => Emission\SandboxEmissionAuthorization::API_URL,
            'source_sha256' => CiiPreparationEvidence::hash($data), 'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
            'emission_context_sha256' => hash('sha256', $data['emission_context']),
            'sandbox_declaration_sha256' => CiiPreparationEvidence::hash($stored->emission_context['declaration']),
            'oauth_credential_sha256' => hash('sha256', (string) $connection->access_token),
            'buyer_destination' => ['provider_company_id' => 18171, 'identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
                'address' => ['scheme' => '0225', 'value' => '315143296_18171']],
            'checks' => ['seller_identity' => $check($sellerPassed, $company), 'seller_session' => $check($sellerPassed, $session),
                'buyer_destination' => $check($buyer, $buyerDirectory) + ['assessment' => $buyerAssessment]]];
        $report['audit_reference'] = app(VerificationAuditStore::class)->store($actor, $stored, $report);

        return $report;
    }

    public function assessBuyer(SandboxSellerEvidence $proof, SuperPdpConnection $connection): array
    {
        SandboxInvoiceProfile::assertRuntime();
        $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), Emission\SandboxEmissionAuthorization::API_URL);
        if (! $connection->exists || $connection->id !== 2 || $connection->company_id !== 4
            || $connection->superpdp_company_id !== 18171 || $connection->directory_identifier !== '000000001'
            || ! $proof->usesAccessToken((string) $connection->access_token)) {
            return ['status' => 'refused', 'reason' => 'tricatel_authenticated_list_required'];
        }
        if ($proof->endpoint !== 'directory'
            || array_keys($proof->data) !== ['data'] || ! is_array($proof->data['data']) || ! array_is_list($proof->data['data'])) {
            return ['status' => 'ambiguous', 'reason' => 'incomplete_or_unknown_envelope'];
        }

        // The qualifier only exposes fixed reasons and classification metadata, never response bodies.
        return array_intersect_key(app(Verification\TricatelPublicationVerification::class)->assessSandboxDestination($proof->data),
            array_flip(['status', 'reason', 'classification_diagnostic', 'qualification_documentaire_sandbox',
                'basis', 'replyto_classification', 'delivery_verified', 'ready_for_emission']));
    }

    /** Reuse only existing live seller checks; never extend their original validity. */
    public function reusableSellerChecks(Invoice $invoice, array $data, SuperPdpConnection $connection, array $report): array
    {
        $this->assertReport($invoice, $data, $connection, $report);
        if (($report['source_sha256'] ?? null) !== CiiPreparationEvidence::hash($data)
            || ($report['expected_environment'] ?? null) !== 'sandbox'
            || ($report['expected_provider_company_id'] ?? null) !== $connection->superpdp_company_id
            || ($report['expected_oauth_client_id'] ?? null) !== $connection->oauth_client_id
            || ($report['expected_api_url'] ?? null) !== Emission\SandboxEmissionAuthorization::API_URL) {
            throw new RuntimeException('Seller evidence context changed.');
        }
        app(CiiPreparationEvidence::class)->fresh($report['checked_at'] ?? null);
        $checks = [];
        foreach (['seller_identity', 'seller_session'] as $key) {
            $check = $report['checks'][$key] ?? [];
            if (($check['status'] ?? null) !== 'passed' || ($check['source'] ?? null) !== 'live'
                || ($check['verified'] ?? null) !== true
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $check['response_sha256'] ?? '')) {
                throw new RuntimeException('Live passed seller evidence required.');
            }
            app(CiiPreparationEvidence::class)->fresh($check['observed_at'] ?? null);
            $checks[$key] = array_intersect_key($check, array_flip(['status', 'source', 'verified', 'response_sha256', 'observed_at']));
        }
        return $checks;
    }

    public function assertReport(Invoice $invoice, array $data, SuperPdpConnection $connection, array $report): void
    {
        SandboxInvoiceProfile::assertRuntime();
        if (($report['kind'] ?? null) !== self::KIND || ($report['emission_profile'] ?? null) !== SandboxInvoiceProfile::PROFILE
            || ($report['sandbox_declaration_sha256'] ?? null) !== CiiPreparationEvidence::hash($invoice->emission_context['declaration'])
            || ($report['oauth_credential_sha256'] ?? null) !== hash('sha256', (string) $connection->access_token)
            || ($report['buyer_destination'] ?? null) !== ['provider_company_id' => 18171,
                'identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
                'address' => ['scheme' => '0225', 'value' => '315143296_18171']]) {
            throw new RuntimeException('Preuves spécifiques Sandbox SUPER PDP absentes ou incompatibles.');
        }
    }
}
