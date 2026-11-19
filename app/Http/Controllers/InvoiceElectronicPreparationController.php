<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationReadiness;
use App\Services\SuperPdp\CiiPreparationResultView;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\Verification\InvoiceDestinationVerification;
use Illuminate\Http\Response;
use InvalidArgumentException;

/** Consultation locale uniquement, sans passerelle d'émission ni persistance. */
class InvoiceElectronicPreparationController extends Controller
{
    public function __invoke(
        Invoice $invoice,
        SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        InvoiceExportDataBuilder $dataBuilder,
    ): Response {
        abort_unless($invoice->user_id === auth()->id(), 403);
        $invoice = Invoice::whereKey($invoice->getKey())->firstOrFail();
        abort_unless($invoice->user_id === auth()->id(), 403);
        abort_unless($invoice->isIssued(), 404);

        // Aucun paramètre HTTP ne peut qualifier une adresse ou compléter les données figées.
        $documentaryPreparation = $diagnostic->prepareLocal(auth()->user(), $invoice);
        $ciiReadiness = app(CiiPreparationReadiness::class)->inspect(auth()->user(), $invoice, $documentaryPreparation);
        $ciiResult = app(CiiPreparationResultView::class)->inspect(auth()->user(), $invoice);
        $emissionDiagnostic = $documentaryPreparation['diagnostic'];
        $destinations = app(InvoiceDestinationVerification::class);
        $destinationReport = $destinations->latest(auth()->user(), $invoice);
        $destinationValidity = $destinations->validityMinutes();
        if (($destinationReport['status'] ?? null) === 'qualified' && ($destinationReport['verified'] ?? false) === true) {
            $emissionDiagnostic['blockers'] = array_values(array_filter($emissionDiagnostic['blockers'],
                fn ($blocker) => $blocker['code'] !== 'routing.verification_unavailable'));
            $emissionDiagnostic['eligible'] = $emissionDiagnostic['blockers'] === [];
        }
        $parties = null;
        $context = [];
        $dataError = null;
        try {
            $parties = InvoiceElectronicPartyData::resolve($dataBuilder->buildElectronic($invoice), true);
            $context = \App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice)
                ? ['currency_code' => 'EUR', 'seller_electronic_address' => $invoice->emission_context['declaration']['seller']['electronic_address'],
                    'buyer_electronic_address' => $invoice->emission_context['declaration']['buyer']['electronic_address']]
                : InvoiceEmissionContext::resolve($parties, []);
        } catch (InvalidArgumentException $exception) {
            $dataError = $exception->getMessage();
        }

        return response()->view('invoices.electronic-preparation', compact(
            'invoice', 'emissionDiagnostic', 'parties', 'context', 'dataError', 'destinationReport', 'destinationValidity', 'documentaryPreparation', 'ciiReadiness', 'ciiResult',
        ))->header('Cache-Control', 'private, no-store');
    }
}
