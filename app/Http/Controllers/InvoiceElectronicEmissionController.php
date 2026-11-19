<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SuperPdp\Emission\HttpEmission;
use App\Services\SuperPdp\Emission\InvoiceEmissionReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Two explicit POST steps and local recovery; no automatic refresh or retry. */
final class InvoiceElectronicEmissionController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoiceEmissionReadiness $readiness, HttpEmission $emission): RedirectResponse
    {
        $invoice = $readiness->owned($request->user(), $invoice);
        if ($emission->available()) {
            $key = 'emission_confirmation.'.$invoice->id;
            if ($request->input('step') === 'review') {
                $emission->review($request->user(), $invoice);
                $messages = ['Registre local examiné. Aucun dépôt ni aucune relance effectués.'];
            } elseif ($request->input('step') === 'reserve') {
                $inspection = $readiness->inspect($request->user(), $invoice);
                abort_unless($inspection['can_send'], 422);
                $reservation = $emission->reserve($request->user(), $invoice, $inspection['document_reference']);
                if ($reservation['token'] !== null) {
                    $request->session()->put($key, ['uuid' => $reservation['attempt']->reference,
                        'token' => $reservation['token'], 'expires_at' => $reservation['expires_at']]);
                }
                $messages = ['Tentative réservée. Confirmez explicitement cette tentative avant son expiration.'];
            } else {
                $request->validate(['confirm_emission' => 'required|accepted', 'attempt_reference' => 'required|uuid', 'confirmation_token' => 'required|string|max:128']);
                $attempt = $emission->confirm($request->user(), $invoice, $request->string('attempt_reference')->toString(), $request->string('confirmation_token')->toString());
                if ($attempt->status !== 'pending') {
                    $request->session()->forget($key);
                }
                $messages = [match ($attempt->status) {
                    'accepted' => $emission->simulated() ? 'Simulation de dépôt acceptée. Aucun dépôt réel.' : 'Dépôt sandbox accepté par SUPER PDP.',
                    'rejected' => 'Dépôt refusé. Aucun nouvel envoi automatique.',
                    default => 'Résultat incertain ou interrompu : examen requis, aucune relance automatique.',
                }];
            }

            return redirect()->to(route('invoices.show', $invoice).'#electronic-emission', 303)
                ->with('emission_request_messages', $messages);
        }
        $messages = [];
        if (! in_array($request->input('confirm_emission'), ['1', 1, true, 'yes', 'on'], true)) {
            $messages[] = 'Confirmez explicitement la demande d’envoi électronique avant tout dépôt.';
        } else {
            $inspection = $readiness->inspect($request->user(), $invoice);
            foreach ($inspection['groups'] as $group) {
                foreach ($group['messages'] as $message) {
                    $messages[] = $group['label'].' : '.$message;
                }
            }
            $messages[] = 'Aucun dépôt effectué : le transport HTTP reste désactivé dans cette configuration, même après confirmation.';
        }

        return redirect()->to(route('invoices.show', $invoice).'#electronic-emission', 303)
            ->with('emission_request_messages', array_values(array_unique($messages)));
    }
}
