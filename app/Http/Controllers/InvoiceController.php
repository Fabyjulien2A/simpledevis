<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\InvoiceXmlGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{

    public function index(Request $request): View
    {
        $query = Invoice::where('user_id', auth()->id())
            ->with('client', 'quote');

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

        if ($request->filled('status')) {
            if ($request->status === 'draft') {
                $query->where('document_status', 'draft');
            } elseif ($request->status === 'overdue') {
                $query->where('document_status', 'issued')
                    ->where('status', '!=', 'payee')
                    ->whereDate('due_date', '<', now());
            } else {
                $query->where('document_status', 'issued')
                    ->where('status', $request->status);
            }
        }

        $invoices = $query->latest()
            ->paginate(10)
            ->withQueryString();

        return view('invoices.index', compact('invoices'));
    }


    public function show(Invoice $invoice): View
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'quote', 'items', 'payments');

        return view('invoices.show', compact('invoice'));
    }

    public function handleAction(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        return match ($request->input('action')) {
            'pdf' => redirect()->route('invoices.pdf', $invoice),
            'email' => $this->sendByEmail($invoice),
            'pay' => $this->markAsPaid($invoice),
            'unpay' => $this->markAsUnpaid($invoice),
            default => redirect()
                ->route('invoices.show', $invoice)
                ->with('error', 'Veuillez sélectionner une action.'),
        };
    }

    public function pdf(Invoice $invoice): Response
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'items', 'quote', 'payments');

        $company = auth()->user()->company;

        $pdf = Pdf::loadView('invoices.pdf', compact('invoice', 'company'));

        return $pdf->download('facture-' . $invoice->invoice_number . '.pdf');
    }

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

    public function markAsPaid(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $remaining = max(
            0,
            (float) $invoice->total_ttc - (float) $invoice->amount_paid_calculated
        );

        if ($remaining <= 0) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with('error', 'Cette facture est déjà entièrement payée.');
        }

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $remaining,
            'method' => 'virement',
            'paid_at' => now()->toDateString(),
        ]);

        $invoice->update([
            'status' => 'payee',
            'amount_paid' => $invoice->total_ttc,
        ]);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'La facture a été marquée comme payée.');
    }

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
            'user_id' => auth()->id(),
            'client_id' => $invoice->client_id,
            'quote_id' => null,
            'invoice_number' => null,
            'document_status' => 'draft',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'non_payee',
            'subtotal_ht' => $invoice->subtotal_ht,
            'total_tva' => $invoice->total_tva,
            'total_ttc' => $invoice->total_ttc,
            'amount_paid' => 0,
            'notes' => $invoice->notes,
            'is_electronic' => false,
            'electronic_format' => null,
            'pdp_id' => null,
            'pdp_reference' => null,
            'pdp_status' => null,
            'xml_generated_at' => null,
            'sent_to_pdp_at' => null,
        ]);

        foreach ($invoice->items as $item) {
            InvoiceItem::create([
                'invoice_id' => $newInvoice->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_ht' => $item->unit_price_ht,
                'tva_rate' => $item->tva_rate,
                'line_total_ht' => $item->line_total_ht,
            ]);
        }

        return redirect()
            ->route('invoices.show', $newInvoice)
            ->with('success', 'La facture a bien été dupliquée.');
    }

    public function xml(Invoice $invoice, InvoiceXmlGenerator $xmlGenerator): Response
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice->load('client', 'items', 'quote', 'payments', 'user.company');

        $xmlContent = $xmlGenerator->generate($invoice);

        $invoice->markXmlGenerated();

        return response($xmlContent, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="facture-' . $invoice->invoice_number . '.xml"'
            );
    }


    public function create(): View
    {
        $clients = Client::where('user_id', auth()->id())
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get();

        return view('invoices.create', compact('clients'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:draft,issue'],
            'client_id' => ['required', 'exists:clients,id'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price_ht' => ['required', 'numeric', 'min:0'],
            'items.*.tva_rate' => ['required', 'numeric', 'min:0'],
        ]);

        $client = Client::where('user_id', auth()->id())
            ->findOrFail($validated['client_id']);

        DB::transaction(function () use ($validated, $client) {
            $subtotalHt = 0;
            $totalTva = 0;

            foreach ($validated['items'] as $item) {
                $lineHt = (float) $item['quantity'] * (float) $item['unit_price_ht'];
                $lineTva = $lineHt * ((float) $item['tva_rate'] / 100);

                $subtotalHt += $lineHt;
                $totalTva += $lineTva;
            }

            $totalTtc = $subtotalHt + $totalTva;

            $invoice = Invoice::create([
                'user_id' => auth()->id(),
                'client_id' => $client->id,
                'quote_id' => null,
                'invoice_number' => $validated['action'] === 'issue'
                    ? Invoice::generateInvoiceNumber((int) auth()->id())
                    : null,
                'date' => $validated['date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30)->toDateString(),
                'status' => 'non_payee',
                'document_status' => $validated['action'] === 'issue'
                    ? 'issued'
                    : 'draft',
                'subtotal_ht' => $subtotalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
                'amount_paid' => 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $lineHt = (float) $item['quantity'] * (float) $item['unit_price_ht'];

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price_ht' => $item['unit_price_ht'],
                    'tva_rate' => $item['tva_rate'],
                    'line_total_ht' => $lineHt,
                ]);
            }
        });

        return redirect()
            ->route('invoices.index')
            ->with(
                'success',
                $validated['action'] === 'draft'
                    ? 'Brouillon enregistré avec succès.'
                    : 'Facture validée définitivement avec succès.'
            );
    }

    public function edit(Invoice $invoice): View
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if ($invoice->isIssued()) {
            abort(403, 'Une facture définitive ne peut pas être modifiée.');
        }

        $invoice->load('items');

        $clients = Client::where('user_id', auth()->id())
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get();

        return view('invoices.edit', compact('invoice', 'clients'));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if ($invoice->isIssued()) {
            abort(403, 'Une facture définitive ne peut pas être modifiée.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price_ht' => ['required', 'numeric', 'min:0'],
            'items.*.tva_rate' => ['required', 'numeric', 'min:0'],
        ]);

        $client = Client::where('user_id', auth()->id())
            ->findOrFail($validated['client_id']);

        DB::transaction(function () use ($validated, $invoice, $client) {
            $subtotalHt = 0;
            $totalTva = 0;

            foreach ($validated['items'] as $item) {
                $lineHt = (float) $item['quantity'] * (float) $item['unit_price_ht'];
                $lineTva = $lineHt * ((float) $item['tva_rate'] / 100);

                $subtotalHt += $lineHt;
                $totalTva += $lineTva;
            }

            $totalTtc = $subtotalHt + $totalTva;

            $invoice->update([
                'client_id' => $client->id,
                'date' => $validated['date'],
                'due_date' => $validated['due_date'] ?? null,
                'subtotal_ht' => $subtotalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
                'notes' => $validated['notes'] ?? null,
            ]);

            $invoice->items()->delete();

            foreach ($validated['items'] as $item) {
                $lineHt = (float) $item['quantity'] * (float) $item['unit_price_ht'];

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price_ht' => $item['unit_price_ht'],
                    'tva_rate' => $item['tva_rate'],
                    'line_total_ht' => $lineHt,
                ]);
            }
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Facture mise à jour avec succès.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if ($invoice->isIssued()) {
            abort(403, 'Une facture définitive ne peut pas être supprimée.');
        }

        $invoice->delete();

        return redirect()
            ->route('invoices.index')
            ->with('success', 'Facture supprimée avec succès.');
    }


    public function finalize(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if (! $invoice->isDraft()) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with('error', 'Cette facture est déjà définitive.');
        }

        DB::transaction(function () use ($invoice) {
            // Verrouiller la facture pour éviter deux validations simultanées.
            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedInvoice->isDraft()) {
                abort(409, 'Cette facture a déjà été validée.');
            }

            // Attribuer son numéro uniquement au moment de la validation.
            $lockedInvoice->update([
                'invoice_number' => Invoice::generateInvoiceNumber((int) $lockedInvoice->user_id),
                'document_status' => 'issued',
                'status' => 'non_payee',
            ]);
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Facture validée définitivement avec succès.');
    }
}
