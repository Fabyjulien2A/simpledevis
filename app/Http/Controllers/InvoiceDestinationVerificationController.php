<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SuperPdp\Verification\InvoicePartyVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceDestinationVerificationController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoicePartyVerification $verification): RedirectResponse
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($stored->user_id === $request->user()->id, 403);
        abort_unless($stored->isIssued(), 404);
        abort_if(\App\Services\SuperPdp\SandboxInvoiceProfile::is($stored), 422,
            'Ce profil exige les qualifications sandbox distinctes ; aucune requête vers l’annuaire français ne sera effectuée.');
        try {
            // Aucun champ HTTP n'est utilisé comme preuve, adresse ou réponse d'annuaire.
            $verification->verifyDestination($request->user(), $stored);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (RuntimeException|\ErrorException) {
            return redirect()->route('invoices.electronic-preparation', $stored)
                ->with('routing_error', 'Le rapport privé n’a pas pu être enregistré. La vérification reste non acquise.');
        }

        return redirect()->route('invoices.electronic-preparation', $stored);
    }
}
