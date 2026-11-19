<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Offline diagnostic. Missing/manual/stale evidence never becomes live permission. */
final class SandboxPreparationDiagnostic
{
    public function __construct(
        private readonly SuperPdpInvoicePayloadBuilder $builder,
        private readonly InvoiceRoutingQualification $routing,
        private readonly SellerEmissionQualification $seller,
    ) {}

    public function diagnose(
        array $data,
        SandboxEmissionContext $context,
        Company $company,
        ?array $frenchContext = null,
        ?SuperPdpConnection $connection = null,
        ?SandboxSellerEvidence $companyEvidence = null,
        ?SandboxSellerEvidence $sessionEvidence = null,
        ?SandboxSellerEvidence $directoryEvidence = null,
    ): array {
        $context->assertFor($company);
        $result = ['ready_for_emission' => false, 'can_convert_live' => false,
            'can_simulate_conversion' => false, 'local_checks_passed' => false,
            'preparation' => null, 'seller' => null, 'local_blockers' => [],
            'emission_blockers' => ['live_buyer_destination_evidence_required',
                'live_document_validation_required', 'scoped_preparation_authorization_required',
                'explicit_cli_documentary_run_required',
                'sandbox_emission_integration_not_enabled', 'deposit_authorization_required']];
        $result['routing'] = $this->routing->assessSandbox($context, $company, $context->toArray()['buyer']);
        try {
            $result['preparation'] = $this->builder->buildSandboxFromData($data, $context, $company, $frenchContext);
            $result['local_checks_passed'] = true;
        } catch (ValidationException $exception) {
            $result['local_blockers'] = array_keys($exception->errors());
        } catch (Throwable) {
            $result['local_blockers'] = ['sandbox_data_invalid'];
        }
        if ($connection === null || $companyEvidence === null || $sessionEvidence === null || $directoryEvidence === null) {
            $result['emission_blockers'][] = 'seller_evidence_required';
        } else {
            try {
                $result['seller'] = $this->seller->assessSandbox($context, $company, $connection,
                    $companyEvidence, $sessionEvidence, $directoryEvidence);
                if (! $result['seller']['verified']) {
                    $result['emission_blockers'][] = 'live_seller_evidence_required';
                }
            } catch (Throwable) {
                $result['emission_blockers'][] = 'seller_connection_or_evidence_invalid';
            }
        }
        $result['can_simulate_conversion'] = $result['local_checks_passed']
            && $result['routing']['manual_confirmation_matched']
            && ($result['seller']['checks_passed_for_simulation'] ?? false);
        if ($result['seller'] !== null && ! $result['seller']['checks_passed_for_simulation']) {
            $result['emission_blockers'][] = 'seller_checks_failed_or_stale';
        }
        $result['source_sha256'] = CiiPreparationEvidence::hash($data);
        $result['context_sha256'] = CiiPreparationEvidence::hash($context->toArray());
        $context->assertFor($company);

        return $result;
    }
}
