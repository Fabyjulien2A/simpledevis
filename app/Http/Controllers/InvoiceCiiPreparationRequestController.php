<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationGuards;
use App\Services\SuperPdp\CiiPreparationReadiness;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Sandbox opt-in only. The existing producer owns all conversions and reservations. */
final class InvoiceCiiPreparationRequestController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice,
        SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        CiiPreparationReadiness $readiness,
        InvoiceExportDataBuilder $source,
        CiiPreparationGuards $guards,
        CiiPreparationEvidence $evidence,
        CiiPreparationRegistry $registry): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($invoice->user_id === $actor->id, 403);
        $invoice = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && $invoice->user_id === $actor->id, 403);
        abort_unless($invoice->isIssued(), 404);
        $company = $actor->company()->first();
        abort_unless($company !== null && $company->user_id === $actor->id, 403);
        // In the current schema invoices belong to a company through their owner.
        abort_unless(in_array($invoice->getRawOriginal('company_id'), [null, $company->id], true), 403);

        $productionAttempted = false;
        try {
            // The HTTP body provides no approval, reference, identity, hash or document data.
            $prepared = $diagnostic->prepareLocal($actor, $invoice);
            $inspection = $readiness->inspect($actor, $invoice, $prepared);
            $messages = [];
            foreach ($inspection['groups'] as $group) {
                foreach ($group['messages'] as $message) {
                    $messages[] = $group['label'].' : '.$message;
                }
            }
            if ($messages !== []) {
                return $this->back($invoice, 'refused', array_values(array_unique($messages)));
            }

            // Re-read and recheck immediately before considering an operation or stored result.
            $data = $source->buildElectronic($invoice);
            $hash = CiiPreparationEvidence::hash($data);
            if ($hash !== $inspection['source_sha256']) {
                return $this->back($invoice, 'refused', ['Les données figées ont changé pendant le contrôle. Aucune préparation déclenchée.']);
            }
            $reference = $inspection['verification_reference'];
            $client = $guards->configuration();
            $connection = $company->superPdpConnection()->firstOrFail();
            abort_unless($connection->company_id === $company->id, 403);
            $guards->connection($connection, $client);
            $proofs = $evidence->assess($actor, $invoice, $data, $connection, $reference);
            $guards->authorization($actor->id, $invoice->id, $hash, $reference, $connection, $client, $proofs['live']);

            // GET-safe registry read: do not reserve, expire, quarantine or reconvert anything.
            $previous = $registry->consult($actor, $invoice);
            if ($previous !== null) {
                if ($previous['state'] === 'completed' && $previous['result'] !== null) {
                    return $this->back($invoice, 'existing', ['Un résultat vérifié est déjà disponible dans la section Document CII. Aucune nouvelle conversion déclenchée.']);
                }

                return $this->back($invoice, $previous['state'] === 'running' ? 'in_progress' : 'review', [
                    $previous['state'] === 'running'
                        ? 'Une préparation est déjà en cours. Aucune nouvelle conversion déclenchée.'
                        : 'La préparation précédente est interrompue ou incertaine et doit être examinée. Aucune relance automatique.',
                ]);
            }

            try {
                $guards->webActivation();
            } catch (\RuntimeException) {
                return $this->back($invoice, 'closed', ['Les prérequis sont réunis, mais le parcours HTTP de préparation réelle reste fermé. Aucun appel SUPER PDP ni document généré.']);
            }
            // Resolve only after every server-side guard. Never pass an HTTP-built approval or gateway.
            $productionAttempted = true;
            $result = app(InvoiceCiiPreparation::class)->produce($actor, $invoice, $reference);
            if (($result['status'] ?? null) === 'prepared_validated') {
                return $this->back($invoice, 'prepared', ['Le document CII a été préparé et sa validation a réussi. Consultez le résultat et son téléchargement privé ci-dessous. Aucun envoi de facture effectué.']);
            }
            if (($result['reason'] ?? null) === 'preparation_in_progress') {
                return $this->back($invoice, 'in_progress', ['Une préparation est déjà en cours. Aucune nouvelle conversion déclenchée.']);
            }
            if (($result['status'] ?? null) === 'prepared_simulation') {
                return $this->back($invoice, 'review', ['Un document de simulation uniquement est disponible. Sa conformité réelle n’est pas établie ; aucune relance automatique ni émission.']);
            }

            return $this->back($invoice, 'review', [($result['status'] ?? null) === 'prepared_pending_validation'
                ? 'Le document CII est conservé, mais sa validation a échoué ou est indisponible. Consultez le résultat ; aucune relance automatique ni émission.'
                : 'La préparation est bloquée, interrompue ou incertaine et doit être examinée dans la section Document CII. Aucune relance automatique ni émission.']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            if ($productionAttempted) {
                return $this->back($invoice, 'review', ['Le résultat de préparation est incertain et doit être examiné. Consultez la section Document CII ; aucune relance automatique ni émission.']);
            }

            return $this->back($invoice, 'refused', ['Les prérequis ou le résultat existant ne peuvent pas être vérifiés. Aucune préparation déclenchée. Consultez les contrôles de la section Document CII.']);
        }
    }

    private function back(Invoice $invoice, string $status, array $messages): RedirectResponse
    {
        return redirect()->to(route('invoices.electronic-preparation', $invoice).'#cii-document-title', 303)
            ->with('cii_request_status', $status)->with('cii_request_messages', $messages);
    }
}
