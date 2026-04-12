<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use App\Models\InvoiceItem;

class InvoiceController extends Controller
{
    /**
     * Liste des factures
     */
    public function index(Request $request): View
    {
        $query = Invoice::where('user_id', auth()->id())
            ->with('client', 'quote');

        // Recherche
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('total_ttc', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($q2) use ($search) {
                        $q2->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%");
                    });
            });
        }

        // Filtre statut
        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->where('status', '!=', 'payee')
                    ->whereDate('due_date', '<', now());
            } else {
                $query->where('status', $request->status);
            }
        }

        $invoices = $query->latest()->paginate(10)->withQueryString();

        return view('invoices.index', compact('invoices'));
    }

    /**
     * Détail d'une facture
     */
    public function show(Invoice $invoice): View
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'quote', 'items', 'payments');

        return view('invoices.show', compact('invoice'));
    }

    /**
     * Gérer les actions depuis le menu déroulant
     */
    public function handleAction(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $action = $request->input('action');

        switch ($action) {
            case 'pdf':
                return redirect()->route('invoices.pdf', $invoice);

            case 'email':
                $invoice->load('client', 'items', 'quote', 'user');

                if (empty($invoice->client->email)) {
                    return redirect()
                        ->route('invoices.show', $invoice)
                        ->with('error', 'Ce client n’a pas d’adresse e-mail.');
                }

                Mail::to($invoice->client->email)->send(new InvoiceMail($invoice));

                return redirect()
                    ->route('invoices.show', $invoice)
                    ->with('success', 'La facture a été envoyée par e-mail au client.');

            case 'pay':
                $remaining = max(0, (float) $invoice->total_ttc - (float) $invoice->amount_paid_calculated);

                if ($remaining <= 0) {
                    return redirect()
                        ->route('invoices.show', $invoice)
                        ->with('error', 'Cette facture est déjà entièrement payée.');
                }

                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount' => $remaining,
                    'paid_at' => now()->toDateString(),
                ]);

                $invoice->update([
                    'status' => 'payee',
                    'amount_paid' => $invoice->total_ttc,
                ]);

                return redirect()
                    ->route('invoices.show', $invoice)
                    ->with('success', 'La facture a été marquée comme payée.');

            case 'unpay':
                $invoice->payments()->delete();

                $invoice->update([
                    'status' => 'non_payee',
                    'amount_paid' => 0,
                ]);

                return redirect()
                    ->route('invoices.show', $invoice)
                    ->with('success', 'La facture a été repassée en non payée.');

            default:
                return redirect()
                    ->route('invoices.show', $invoice)
                    ->with('error', 'Veuillez sélectionner une action.');
        }
    }

    /**
     * Générer le PDF
     */
    public function pdf(Invoice $invoice)
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'items', 'quote', 'payments');

        $company = auth()->user()->company;

        $pdf = Pdf::loadView('invoices.pdf', compact('invoice', 'company'));

        return $pdf->download('facture-' . $invoice->invoice_number . '.pdf');
    }

    /**
     * Envoi direct par email
     */
    public function sendByEmail(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'items', 'quote', 'user');

        if (empty($invoice->client->email)) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with('error', 'Ce client n’a pas d’adresse e-mail.');
        }

        Mail::to($invoice->client->email)->send(new InvoiceMail($invoice));

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'La facture a été envoyée par e-mail au client.');
    }

    /**
     * Marquer comme payée
     */
    public function markAsPaid(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $remaining = max(0, (float) $invoice->total_ttc - (float) $invoice->amount_paid_calculated);

        if ($remaining > 0) {
            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $remaining,
                'paid_at' => now()->toDateString(),
            ]);
        }

        $invoice->update([
            'status' => 'payee',
            'amount_paid' => $invoice->total_ttc,
        ]);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'La facture a été marquée comme payée.');
    }

    /**
     * Remettre en non payée
     */
    public function markAsUnpaid(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->payments()->delete();

        $invoice->update([
            'status' => 'non_payee',
            'amount_paid' => 0,
        ]);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'La facture a été repassée en non payée.');
    }

    /**
     * Ajouter un paiement partiel
     */
    public function addPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('payments');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string'],
        ]);

        $currentPaid = (float) $invoice->payments->sum('amount');
        $newTotalPaid = $currentPaid + (float) $validated['amount'];

        if ($newTotalPaid > (float) $invoice->total_ttc) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with('error', 'Le paiement dépasse le montant total de la facture.');
        }

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'paid_at' => now()->toDateString(),
        ]);

        $invoice->refresh()->load('payments');

        $updatedPaid = (float) $invoice->payments->sum('amount');

        if ($updatedPaid <= 0) {
            $invoice->update([
                'status' => 'non_payee',
                'amount_paid' => 0,
            ]);
        } elseif ($updatedPaid >= (float) $invoice->total_ttc) {
            $invoice->update([
                'status' => 'payee',
                'amount_paid' => $updatedPaid,
            ]);
        } else {
            $invoice->update([
                'status' => 'partiellement_payee',
                'amount_paid' => $updatedPaid,
            ]);
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Paiement enregistré avec succès.');
    }

    public function duplicate(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('items');

        $newInvoice = Invoice::create([
            'user_id'        => auth()->id(),
            'client_id'      => $invoice->client_id,
            'quote_id'       => null,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'status'         => 'non_payee',
            'subtotal_ht'    => $invoice->subtotal_ht,
            'total_tva'      => $invoice->total_tva,
            'total_ttc'      => $invoice->total_ttc,
            'amount_paid'    => 0,
            'notes'          => $invoice->notes,
        ]);

        foreach ($invoice->items as $item) {
            InvoiceItem::create([
                'invoice_id'    => $newInvoice->id,
                'description'   => $item->description,
                'quantity'      => $item->quantity,
                'unit_price_ht' => $item->unit_price_ht,
                'tva_rate'      => $item->tva_rate,
                'line_total_ht' => $item->line_total_ht,
            ]);
        }

        return redirect()
            ->route('invoices.show', $newInvoice)
            ->with('success', 'La facture a bien été dupliquée.');
    }
}
