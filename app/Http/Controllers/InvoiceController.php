<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\InvoiceLinePreparationService;
use App\Services\InvoicePartySnapshotCapture;
use App\Services\InvoicePdfRenderer;
use App\Services\InvoiceXmlGenerator;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoicePartySnapshotCapture $snapshotCapture) {}

    private const FRENCH_BUSINESS_PROCESS_MESSAGES = [
        'french_business_process_type.required_if' => 'Sélectionnez un cadre de facturation avant la validation définitive.',
        'french_business_process_type.required' => 'Sélectionnez et enregistrez le cadre de facturation sur le brouillon avant sa validation définitive.',
        'french_business_process_type.string' => 'Le cadre de facturation doit être B1, S1 ou M1, ou rester non renseigné sur un brouillon.',
        'french_business_process_type.in' => 'Le cadre de facturation doit être B1, S1 ou M1.',
    ];

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

    public function show(Invoice $invoice, SuperPdpEmissionEligibilityDiagnostic $diagnostic): View
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        // Autoriser aussi les données relues avant tout diagnostic.
        $invoice = Invoice::whereKey($invoice->id)->firstOrFail();
        abort_unless($invoice->user_id === auth()->id(), 403);
        $invoice->load('client', 'quote', 'items', 'payments');

        // Le diagnostic relit le régime déclaré du profil. Ne pas inventer les autres
        // données de contexte à partir des montants, notes libres ou paramètres HTTP.
        $emissionDiagnostic = $diagnostic->diagnose($invoice);

        return view('invoices.show', compact('invoice', 'emissionDiagnostic'));
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

    public function pdf(Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        // Autoriser avant la préparation et le rendu, y compris avec un modèle obsolète.
        $invoice = Invoice::whereKey($invoice->id)->firstOrFail();
        abort_unless($invoice->user_id === auth()->id(), 403);
        $document = $renderer->generate($invoice);

        // Même disposition et même nom de repli que le téléchargement DomPDF.
        return new Response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $document['filename'],
                str_replace('%', '', Str::ascii($document['filename']))),
            'Content-Length' => strlen($document['content']),
        ]);
    }

    public function sendByEmail(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $invoice = Invoice::whereKey($invoice->id)->firstOrFail();
        abort_unless($invoice->user_id === auth()->id(), 403);
        $invoice->load('client');

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

        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === auth()->id(), 403);
            abort_unless($invoice->isIssued(), 409, 'Un paiement nécessite une facture définitive.');

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
        });
    }

    public function markAsUnpaid(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === auth()->id(), 403);
            abort_if($invoice->isDraft() && $invoice->calculation_version === 'decimal-v1', 409,
                'Un brouillon decimal-v1 ne peut pas être marqué comme impayé.');

            $invoice->payments()->delete();
            $invoice->update(['status' => 'non_payee', 'amount_paid' => 0]);
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'La facture a été repassée en non payée.');
    }

    public function addPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === auth()->id(), 403);
            abort_unless($invoice->isIssued(), 409, 'Un paiement nécessite une facture définitive.');

            // Interdire les paiements sur une facture annulée par avoir total
            $hasTotalCreditNote = $invoice->creditNotes()
                ->where('status', 'issued')
                ->exists();

            if ($hasTotalCreditNote) {
                return redirect()
                    ->route('invoices.show', $invoice)
                    ->with('error', 'Impossible d’enregistrer un paiement : cette facture a été annulée par un avoir.');
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
        });
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
                'attachment; filename="facture-'.$invoice->invoice_number.'.xml"'
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

    public function store(Request $request, InvoiceLinePreparationService $linePreparation): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:draft,issue'],
            'french_business_process_type' => ['bail', 'required_if:action,issue', 'nullable', 'string', Rule::in(Invoice::FRENCH_BUSINESS_PROCESS_TYPES)],
            'client_id' => ['required', 'exists:clients,id'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.tax_treatment' => ['required', 'string', 'in:taxable,franchise,exempt,zero_rated'],
        ], self::FRENCH_BUSINESS_PROCESS_MESSAGES);

        $client = Client::where('user_id', auth()->id())
            ->findOrFail($validated['client_id']);

        $prepared = $linePreparation->prepare($this->translateFiscalItems($request->input('items')));

        DB::transaction(function () use ($validated, $client, $prepared) {
            if ($validated['action'] === 'issue') {
                // Même ordre de verrouillage que la finalisation : utilisateur, facture.
                DB::table('users')->where('id', auth()->id())->lockForUpdate()->firstOrFail();
            }

            $invoice = Invoice::create([
                'user_id' => auth()->id(),
                'client_id' => $client->id,
                'quote_id' => null,
                'invoice_number' => null,
                'date' => $validated['date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30)->toDateString(),
                'status' => 'non_payee',
                'document_status' => 'draft',
                'french_business_process_type' => $validated['french_business_process_type'] ?? null,
                'calculation_version' => 'decimal-v1',
                'subtotal_ht' => $prepared['totals']['total_ht'],
                'total_tva' => $prepared['totals']['total_vat'],
                'total_ttc' => $prepared['totals']['total_ttc'],
                'amount_paid' => 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($prepared['items'] as $item) {
                $invoice->items()->create($item);
            }

            if ($validated['action'] === 'issue') {
                // La création directe contrôle elle aussi le choix déjà enregistré.
                $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                abort_unless($invoice->user_id === auth()->id(), 403);
                abort_unless($invoice->isDraft(), 409, 'Seul un brouillon peut être validé.');
                $this->validateStoredFrenchBusinessProcessType($invoice);
                $this->snapshotCapture->captureForFinalization($invoice);
                $invoice->update([
                    'invoice_number' => Invoice::generateInvoiceNumber((int) $invoice->user_id),
                    'document_status' => 'issued',
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

    public function update(Request $request, Invoice $invoice, InvoiceLinePreparationService $linePreparation): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if ($invoice->isIssued()) {
            abort(403, 'Une facture définitive ne peut pas être modifiée.');
        }

        DB::transaction(function () use ($request, $invoice, $linePreparation) {
            // Le modèle issu du route binding peut être périmé : ne rien écrire avant relecture.
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === auth()->id(), 403);
            abort_unless($invoice->isDraft(), 403, 'Seul un brouillon peut être modifié.');
            abort_unless(in_array($invoice->calculation_version, [null, 'decimal-v1'], true), 409,
                'Cette version de calcul n’est pas prise en charge pour la modification.');

            if ($invoice->calculation_version === 'decimal-v1') {
                abort_if($invoice->payments()->exists()
                    || ! BigDecimal::of($invoice->amount_paid)->isZero()
                    || $invoice->status !== 'non_payee', 409,
                    'Un brouillon comportant un règlement ou un statut de paiement incohérent ne peut pas être modifié.');

                $validated = $request->validate([
                    'french_business_process_type' => ['bail', 'nullable', 'string', Rule::in(Invoice::FRENCH_BUSINESS_PROCESS_TYPES)],
                    'client_id' => ['required', 'exists:clients,id'],
                    'date' => ['required', 'date'],
                    'due_date' => ['nullable', 'date', 'after_or_equal:date'],
                    'notes' => ['nullable', 'string'],
                    'items' => ['required', 'array', 'min:1'],
                ], self::FRENCH_BUSINESS_PROCESS_MESSAGES);
                $client = Client::where('user_id', auth()->id())->findOrFail($validated['client_id']);
                $prepared = $linePreparation->prepare($this->translateFiscalItems($request->input('items')));

                $invoice->update([
                    'french_business_process_type' => array_key_exists('french_business_process_type', $validated) ? $validated['french_business_process_type'] : $invoice->french_business_process_type,
                    'client_id' => $client->id,
                    'date' => $validated['date'],
                    'due_date' => $validated['due_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'subtotal_ht' => $prepared['totals']['total_ht'],
                    'total_tva' => $prepared['totals']['total_vat'],
                    'total_ttc' => $prepared['totals']['total_ttc'],
                ]);
                $invoice->items()->delete();
                foreach ($prepared['items'] as $item) {
                    $invoice->items()->create($item);
                }

                return;
            }

            // Parcours historique : mêmes règles et mêmes calculs, sans migration de version.
            $validated = $request->validate([
                'french_business_process_type' => ['bail', 'nullable', 'string', Rule::in(Invoice::FRENCH_BUSINESS_PROCESS_TYPES)],
                'client_id' => ['required', 'exists:clients,id'],
                'date' => ['required', 'date'],
                'due_date' => ['nullable', 'date', 'after_or_equal:date'],
                'notes' => ['nullable', 'string'],

                'items' => ['required', 'array', 'min:1'],
                'items.*.description' => ['required', 'string'],
                'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
                'items.*.unit_price_ht' => ['required', 'numeric', 'min:0'],
                'items.*.tva_rate' => ['required', 'numeric', 'min:0'],
            ], self::FRENCH_BUSINESS_PROCESS_MESSAGES);

            $client = Client::where('user_id', auth()->id())
                ->findOrFail($validated['client_id']);

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
                'french_business_process_type' => array_key_exists('french_business_process_type', $validated) ? $validated['french_business_process_type'] : $invoice->french_business_process_type,
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

    private function translateFiscalItems(array $items): array
    {
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.tax_treatment' => ['required', 'string', 'in:taxable,franchise,exempt,zero_rated'],
        ])->validate();

        $fiscalErrors = [];
        foreach ($items as $index => &$item) {
            $category = match ($item['tax_treatment']) {
                'taxable' => 'S',
                'franchise', 'exempt' => 'E',
                'zero_rated' => 'Z',
            };
            if (array_key_exists('vat_category', $item) && $item['vat_category'] !== $category) {
                $fiscalErrors["items.$index.vat_category"] = 'La catégorie fiscale ne correspond pas au traitement choisi.';
            }
            if ($item['tax_treatment'] === 'franchise') {
                if (isset($item['exemption_reason_code']) && $item['exemption_reason_code'] !== ''
                    && $item['exemption_reason_code'] !== 'VATEX-FR-FRANCHISE') {
                    $fiscalErrors["items.$index.exemption_reason_code"] = 'La franchise en base exige le code VATEX-FR-FRANCHISE.';
                }
                $item['exemption_reason_code'] = 'VATEX-FR-FRANCHISE';
            } elseif ($item['tax_treatment'] === 'exempt'
                && ($item['exemption_reason_code'] ?? null) === 'VATEX-FR-FRANCHISE') {
                $fiscalErrors["items.$index.tax_treatment"] = 'Sélectionnez Franchise en base pour ce motif.';
            }
            $item['vat_category'] = $category;
        }
        unset($item);

        if ($fiscalErrors !== []) {
            throw ValidationException::withMessages($fiscalErrors);
        }

        return $items;
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === auth()->id(), 403);
            abort_if($invoice->isIssued(), 403, 'Une facture définitive ne peut pas être supprimée.');
            $invoice->delete();
        });

        return redirect()
            ->route('invoices.index')
            ->with('success', 'Facture supprimée avec succès.');
    }

    public function finalize(Invoice $invoice, InvoiceLinePreparationService $linePreparation): RedirectResponse
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        $finalized = DB::transaction(function () use ($invoice, $linePreparation) {
            // Même ordre que les avoirs : utilisateur, puis facture. Le générateur
            // réutilise ce verrou utilisateur dans cette même transaction.
            DB::table('users')->where('id', auth()->id())->lockForUpdate()->firstOrFail();
            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedInvoice->user_id === auth()->id(), 403);

            if (! $lockedInvoice->isDraft()) {
                return false;
            }

            abort_unless(in_array($lockedInvoice->calculation_version, [null, 'decimal-v1'], true), 409,
                'Version de calcul inconnue.');

            if ($lockedInvoice->calculation_version === 'decimal-v1') {
                abort_if($lockedInvoice->invoice_number !== null, 409, 'Ce brouillon possède déjà un numéro.');
                abort_if($lockedInvoice->payments()->exists()
                    || ! BigDecimal::of($lockedInvoice->amount_paid)->isZero()
                    || $lockedInvoice->status !== 'non_payee', 409,
                    'Les paiements ou le statut de paiement de ce brouillon sont incompatibles avec sa validation.');

                // Relecture des seules données persistées ; aucune correction à l'émission.
                $lines = $lockedInvoice->items()->orderBy('id')->lockForUpdate()->get();
                $prepared = $linePreparation->prepare($lines->map(fn (InvoiceItem $line) => $line->only([
                    'description', 'quantity', 'unit_price_ht', 'tva_rate', 'vat_category',
                    'unit_code', 'exemption_reason_code', 'exemption_reason',
                ]))->all());
                $errors = [];
                foreach ($lines as $index => $line) {
                    if (! BigDecimal::of($line->line_total_ht)->isEqualTo($prepared['items'][$index]['line_total_ht'])) {
                        $errors["items.$index.line_total_ht"] = 'Le montant HT enregistré ne correspond pas au calcul de cette ligne.';
                    }
                }
                // Les ventilations sont recalculées par le service, mais aucun instantané
                // n'est stocké : on contrôle leurs totaux et les HT de chaque ligne.
                foreach (['subtotal_ht' => 'total_ht', 'total_tva' => 'total_vat', 'total_ttc' => 'total_ttc'] as $field => $total) {
                    if (! BigDecimal::of($lockedInvoice->$field)->isEqualTo($prepared['totals'][$total])) {
                        $errors[$field] = 'Le montant enregistré ne correspond pas aux lignes et à leur ventilation TVA.';
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            $this->validateStoredFrenchBusinessProcessType($lockedInvoice);
            $this->snapshotCapture->captureForFinalization($lockedInvoice);

            // Attribuer son numéro uniquement au moment de la validation.
            $lockedInvoice->update([
                'invoice_number' => Invoice::generateInvoiceNumber((int) $lockedInvoice->user_id),
                'document_status' => 'issued',
                'status' => 'non_payee',
            ]);

            return true;
        });

        if (! $finalized) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Cette facture est déjà définitive.');
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Facture validée définitivement avec succès.');
    }

    private function validateStoredFrenchBusinessProcessType(Invoice $invoice): void
    {
        Validator::make($invoice->only('french_business_process_type'), [
            'french_business_process_type' => ['required', 'string', Rule::in(Invoice::FRENCH_BUSINESS_PROCESS_TYPES)],
        ], self::FRENCH_BUSINESS_PROCESS_MESSAGES)->validate();
    }
}
