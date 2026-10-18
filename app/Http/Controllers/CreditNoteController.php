<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class CreditNoteController extends Controller
{
    /**
     * Crée un avoir total pour une facture définitive.
     */
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        if ((int) $invoice->user_id !== (int) auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $creditNote = DB::transaction(function () use ($invoice, $validated) {

            // Sérialise les créations d'avoirs pour cet utilisateur.
            DB::table('users')
                ->where('id', $invoice->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedInvoice->isIssued()) {
                abort(422, 'Un avoir nécessite une facture définitive.');
            }

            // Pour cette première version, un seul avoir total
            // peut annuler une facture.
            if ($lockedInvoice->creditNotes()->exists()) {
                abort(422, 'Cette facture possède déjà un avoir.');
            }

            $year = now()->year;

            $counter = DB::table('credit_note_counters')
                ->where('user_id', $lockedInvoice->user_id)
                ->where('year', $year)
                ->first();

            $nextNumber = $counter
                ? (int) $counter->last_number + 1
                : 1;

            if ($counter) {
                DB::table('credit_note_counters')
                    ->where('id', $counter->id)
                    ->update([
                        'last_number' => $nextNumber,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('credit_note_counters')->insert([
                    'user_id' => $lockedInvoice->user_id,
                    'year' => $year,
                    'last_number' => $nextNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return CreditNote::create([
                'user_id' => $lockedInvoice->user_id,
                'invoice_id' => $lockedInvoice->id,
                'credit_note_number' => sprintf(
                    'AV-%d-%04d',
                    $year,
                    $nextNumber
                ),
                'date' => now()->toDateString(),
                'status' => 'issued',
                'reason' => $validated['reason'],
                'subtotal_ht' => $lockedInvoice->subtotal_ht,
                'total_tva' => $lockedInvoice->total_tva,
                'total_ttc' => $lockedInvoice->total_ttc,
                'notes' => $lockedInvoice->notes,
            ]);
        }, 3);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with(
                'success',
                'Avoir ' . $creditNote->credit_note_number . ' créé avec succès.'
            );
    }

    public function pdf(CreditNote $creditNote)
{
    // Vérifier que l'avoir appartient à l'utilisateur connecté
    abort_unless(
        $creditNote->user_id === auth()->id(),
        403
    );

    // Charger la facture d'origine et ses informations
    $creditNote->load([
        'invoice.client',
        'invoice.items',
    ]);

    // Récupérer les informations de l'entreprise
    $company = auth()->user()->company;

    // Générer le PDF
    $pdf = Pdf::loadView('credit-notes.pdf', [
        'creditNote' => $creditNote,
        'company' => $company,
    ]);

    return $pdf->download(
        'avoir-' . $creditNote->credit_note_number . '.pdf'
    );
}
}
