<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;

class QuoteController extends Controller
{
    /**
     * Liste des devis
     */
    public function index(Request $request): View
{
    $query = Quote::where('user_id', auth()->id())
        ->with('client', 'invoice');

    // Recherche
    if ($request->filled('search')) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('quote_number', 'like', "%{$search}%")
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
        $query->where('status', $request->status);
    }

    $quotes = $query->latest()->paginate(10)->withQueryString();

    return view('quotes.index', compact('quotes'));
}

    /**
     * Formulaire création devis
     */
    public function create(): View
    {
        $clients = Client::where('user_id', auth()->id())->get();

        return view('quotes.create', compact('clients'));
    }

    /**
     * Enregistrer un devis
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'tva_rate' => ['required', 'numeric', 'in:0,10,20'],
            'items' => ['required', 'array'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $items = collect($validated['items'])
            ->filter(function ($item) {
                return !empty($item['description']) && isset($item['quantity']) && isset($item['price']);
            })
            ->values();

        if ($items->isEmpty()) {
            return back()
                ->withErrors(['items' => 'Veuillez renseigner au moins une ligne de devis.'])
                ->withInput();
        }

        $subtotalHt = $items->sum(function ($item) {
            return $item['quantity'] * $item['price'];
        });

        $tvaRate = (float) $validated['tva_rate'];

        $totalTva = $subtotalHt * ($tvaRate / 100);
        $totalTtc = $subtotalHt + $totalTva;

        $quote = Quote::create([
            'user_id'      => auth()->id(),
            'client_id'    => $validated['client_id'],
            'quote_number' => Quote::generateQuoteNumber(),
            'date'         => now()->toDateString(),
            'status'       => 'brouillon',
            'subtotal_ht'  => $subtotalHt,
            'total_tva'    => $totalTva,
            'total_ttc'    => $totalTtc,
            'notes'        => null,
        ]);

        foreach ($items as $item) {
            $lineTotal = $item['quantity'] * $item['price'];

            QuoteItem::create([
                'quote_id'      => $quote->id,
                'description'   => $item['description'],
                'quantity'      => $item['quantity'],
                'unit_price_ht' => $item['price'],
                'tva_rate'      => $tvaRate,
                'line_total_ht' => $lineTotal,
            ]);
        }

        return redirect()
            ->route('quotes.index')
            ->with('success', 'Devis créé avec succès.');
    }

    /**
     * Afficher un devis
     */
    public function show(Quote $quote): View
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403);
        }

        $quote->load('client', 'items', 'invoice');

        return view('quotes.show', compact('quote'));
    }

    /**
     * Formulaire modification devis
     */
    public function edit(Quote $quote): View
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403);
        }

        return view('quotes.edit', compact('quote'));
    }

    /**
     * Mettre à jour le devis
     */
    public function update(Request $request, Quote $quote): RedirectResponse
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:brouillon,envoye,accepte,refuse'],
        ]);

        $quote->update([
            'status' => $validated['status'],
        ]);

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Statut mis à jour');
    }

    /**
     * Générer le PDF du devis
     */
    public function pdf(Quote $quote)
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403);
        }

        $quote->load('client', 'items');

        $company = auth()->user()->company;

        $pdf = Pdf::loadView('quotes.pdf', compact('quote', 'company'));

        return $pdf->download('devis-' . $quote->quote_number . '.pdf');
    }


    /**
     * Générer un duplicata du PDF du devis
     */

    public function duplicate(Quote $quote): RedirectResponse
{
    if ($quote->user_id !== auth()->id()) {
        abort(403);
    }

    $quote->load('items');

    $newQuote = Quote::create([
        'user_id'      => auth()->id(),
        'client_id'    => $quote->client_id,
        'quote_number' => Quote::generateQuoteNumber(),
        'date'         => now()->toDateString(),
        'status'       => 'brouillon',
        'subtotal_ht'  => $quote->subtotal_ht,
        'total_tva'    => $quote->total_tva,
        'total_ttc'    => $quote->total_ttc,
        'notes'        => $quote->notes,
    ]);

    foreach ($quote->items as $item) {
        QuoteItem::create([
            'quote_id'      => $newQuote->id,
            'description'   => $item->description,
            'quantity'      => $item->quantity,
            'unit_price_ht' => $item->unit_price_ht,
            'tva_rate'      => $item->tva_rate,
            'line_total_ht' => $item->line_total_ht,
        ]);
    }

    return redirect()
        ->route('quotes.show', $newQuote)
        ->with('success', 'Le devis a bien été dupliqué.');
}

    /**
     * Transformer un devis en facture
     */
    public function convertToInvoice(Quote $quote): RedirectResponse
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403);
        }

        if ($quote->invoice) {
            return redirect()
                ->route('quotes.show', $quote)
                ->with('error', 'Ce devis a déjà été transformé en facture.');
        }

        $quote->load('items');

        $invoice = Invoice::create([
            'user_id'        => $quote->user_id,
            'client_id'      => $quote->client_id,
            'quote_id'       => $quote->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'date'           => now()->toDateString(),
            'status'         => 'non_payee',
            'subtotal_ht'    => $quote->subtotal_ht,
            'total_tva'      => $quote->total_tva,
            'total_ttc'      => $quote->total_ttc,
            'notes'          => $quote->notes,
            'due_date' => now()->addDays(30),
        ]);

        foreach ($quote->items as $item) {
            InvoiceItem::create([
                'invoice_id'    => $invoice->id,
                'description'   => $item->description,
                'quantity'      => $item->quantity,
                'unit_price_ht' => $item->unit_price_ht,
                'tva_rate'      => $item->tva_rate,
                'line_total_ht' => $item->line_total_ht,
            ]);
        }

        $quote->update([
            'status' => 'accepte',
        ]);

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Le devis a été transformé en facture : ' . $invoice->invoice_number);
    }
}