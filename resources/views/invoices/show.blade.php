<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-indigo-600">Facturation</p>
                <h2 class="text-2xl font-bold text-gray-900">Détail de la facture</h2>
            </div>
            <a href="{{ route('invoices.index') }}" class="rounded-xl border bg-white px-4 py-2 text-sm text-gray-700">Retour aux factures</a>
        </div>
    </x-slot>

@php
    $isDraft = $invoice->isDraft();

    $hasTotalCreditNote = $invoice->creditNotes()
        ->where('status', 'issued')
        ->exists();

    $statusLabel = $hasTotalCreditNote
        ? 'Annulée par avoir'
        : match ($invoice->status) {
            'payee' => 'Payée',
            'partiellement_payee' => 'Partiellement payée',
            'non_payee' => 'Non payée',
            default => ucfirst($invoice->status ?? 'Non renseigné'),
        };
@endphp

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50 py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 p-4 text-green-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif


            <div class="overflow-hidden rounded-3xl border bg-white shadow-lg">
                <div class="bg-gradient-to-r from-indigo-600 to-purple-700 px-6 py-8 sm:px-8">
                    <p class="text-sm text-indigo-100">{{ $isDraft ? 'Document en préparation' : 'Facture définitive' }}</p>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
                        <h3 class="text-3xl font-bold">{{ $isDraft ? 'Brouillon #'.$invoice->id : $invoice->invoice_number }}</h3>
                        <span class="rounded-full bg-white/20 px-4 py-2 text-sm font-semibold">{{ $isDraft ? 'Brouillon' : $statusLabel }}</span>
                    </div>
                    <p class="mt-2 text-sm text-indigo-100">Date : {{ $invoice->date?->format('d/m/Y') ?? '—' }}</p>
                </div>
                <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">Client</p>
                        <p class="mt-2 font-semibold text-gray-900">{{ $invoice->client?->company_name ?: trim(($invoice->client?->first_name ?? '').' '.($invoice->client?->last_name ?? '')) ?: '—' }}</p>
                        <p class="text-sm text-gray-500">{{ $invoice->client?->email }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">Devis lié</p>
                        <p class="mt-2 font-semibold text-gray-900">{{ $invoice->quote?->quote_number ?? 'Aucun' }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">Total TTC</p>
                        <p class="mt-2 text-xl font-bold text-gray-900">{{ number_format((float)$invoice->total_ttc, 2, ',', ' ') }} €</p>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">Échéance</p>
                        <p class="mt-2 font-semibold text-gray-900">{{ $invoice->due_date?->format('d/m/Y') ?? 'Non définie' }}</p>
                        @if(!$isDraft && !$hasTotalCreditNote && $invoice->is_overdue)
                            <p class="mt-1 text-sm font-medium text-red-600">En retard</p>
                        @endif
                    </div>
                </div>
            </div>

            @if($isDraft)
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
                    <h4 class="text-lg font-semibold text-amber-900">Facture en brouillon</h4>
                    <p class="mt-2 text-sm text-amber-800">Vérifiez les informations avant de valider. Cette opération attribuera un numéro définitif et empêchera toute modification.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('invoices.edit', $invoice) }}" class="rounded-xl border border-amber-200 bg-white px-5 py-3 text-sm font-semibold text-gray-800">Modifier le brouillon</a>
                        <form method="POST" action="{{ route('invoices.finalize', $invoice) }}" onsubmit="return confirm('Valider définitivement cette facture ? Cette action est irréversible.')">
                            @csrf
                            <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white">Valider définitivement</button>
                        </form>
                    </div>
                </div>
            @else
                <div class="rounded-3xl border bg-white p-6 shadow-lg">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900">Facturation électronique</h4>
                            <p class="mt-1 text-sm text-gray-500">{{ $invoice->is_electronic ? $invoice->pdp_status_label : 'Aucun XML généré' }}</p>
                            @if($invoice->xml_generated_at)
                                <p class="mt-1 text-sm text-gray-500">XML généré le {{ $invoice->xml_generated_at->format('d/m/Y à H:i') }}</p>
                            @endif
                        </div>
                        <a href="{{ route('invoices.xml', $invoice) }}" class="rounded-xl bg-indigo-50 px-5 py-3 text-sm font-semibold text-indigo-700">{{ $invoice->is_electronic ? 'Télécharger le XML' : 'Générer le XML' }}</a>
                    </div>
                </div>
            @endif

            {{-- Avoir total : uniquement pour une facture définitive --}}
            @if(!$isDraft)
                @php
                    $creditNotes = $invoice->creditNotes()->orderByDesc('id')->get();
                @endphp

                <div class="rounded-3xl border bg-white p-6 shadow-lg">
                    <h4 class="text-lg font-semibold text-gray-900">Avoirs</h4>

                    @if($creditNotes->isNotEmpty())
                        <p class="mt-2 text-sm text-gray-600">Un avoir a déjà été établi pour cette facture.</p>
                        <div class="mt-4 space-y-3">
                            @foreach($creditNotes as $creditNote)
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                    <div>
    <p class="font-semibold text-emerald-900">
        {{ $creditNote->credit_note_number }}
    </p>

    <p class="text-sm text-emerald-800">
        {{ $creditNote->date?->format('d/m/Y') ?? '—' }}
        — {{ $creditNote->reason }}
    </p>

    <a href="{{ route('credit-notes.pdf', $creditNote) }}"
       class="mt-3 inline-block rounded-lg px-4 py-2 text-sm font-semibold"
       style="background-color: #047857; color: #ffffff;">
        Télécharger l'avoir PDF
    </a>
</div>
                                    <strong class="text-emerald-900">−{{ number_format((float) $creditNote->total_ttc, 2, ',', ' ') }} €</strong>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-sm text-gray-500">L'avoir n'efface pas les paiements déjà enregistrés. Un remboursement éventuel doit être suivi séparément.</p>
                    @else
                        <p class="mt-2 text-sm text-gray-600">Annuler intégralement cette facture par un avoir définitif. La facture d'origine sera conservée.</p>

                        <form method="POST" action="{{ route('credit-notes.store', $invoice) }}" class="mt-5 space-y-4"
                              onsubmit="return confirm('Créer un avoir total définitif pour cette facture ? Cette opération est irréversible.')">
                            @csrf
                            <div>
                                <label for="credit_note_reason" class="block text-sm font-semibold text-gray-700">Motif de l'avoir *</label>
                                <textarea id="credit_note_reason" name="reason" rows="3" maxlength="500" required
                                          class="mt-2 w-full rounded-xl border-gray-200 text-sm"
                                          placeholder="Ex. : Annulation de la prestation">{{ old('reason') }}</textarea>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 p-4">
                                <span class="text-sm font-medium text-gray-700">Montant de l'avoir total</span>
                                <strong class="text-lg text-gray-900">{{ number_format((float) $invoice->total_ttc, 2, ',', ' ') }} € TTC</strong>
                            </div>
                            <button
    type="submit"
    style="display: block; background-color: #dc2626; color: #ffffff; padding: 14px 24px; border-radius: 12px; font-weight: 600; cursor: pointer; margin-top: 20px;"
>
    Créer un avoir total
</button>
                        </form>
                    @endif
                </div>
            @endif

            <div class="overflow-hidden rounded-3xl border bg-white shadow-lg">
                <div class="border-b p-6">
                    <h4 class="text-lg font-semibold text-gray-900">Détail des prestations</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-6 py-4">Description</th>
                                <th class="px-6 py-4">Quantité</th>
                                <th class="px-6 py-4">Prix unitaire HT</th>
                                <th class="px-6 py-4">TVA</th>
                                <th class="px-6 py-4">Total HT</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($invoice->items as $item)
                                <tr>
                                    <td class="px-6 py-4">{{ $item->description }}</td>
                                    <td class="px-6 py-4">{{ number_format((float)$item->quantity, 2, ',', ' ') }}</td>
                                    <td class="px-6 py-4">{{ number_format((float)$item->unit_price_ht, 2, ',', ' ') }} €</td>
                                    <td class="px-6 py-4">{{ number_format((float)$item->tva_rate, 2, ',', ' ') }} %</td>
                                    <td class="px-6 py-4 font-semibold">{{ number_format((float)$item->line_total_ht, 2, ',', ' ') }} €</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Aucune ligne de facture</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="rounded-3xl border bg-white p-6 shadow-lg">
                    <h4 class="text-lg font-semibold text-gray-900">Paiements</h4>
                    <div class="mt-5 space-y-3 text-sm">
                        <div class="flex justify-between rounded-xl bg-gray-50 p-3"><span>Déjà payé</span><strong>{{ number_format((float)$invoice->amount_paid_calculated, 2, ',', ' ') }} €</strong></div>
                        <div class="flex justify-between rounded-xl bg-gray-50 p-3"><span>Reste à payer</span><strong>{{ number_format((float)$invoice->remaining_amount, 2, ',', ' ') }} €</strong></div>
                    </div>
                    @if($isDraft)
                        <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Les paiements seront disponibles après validation.</p>
                    @elseif($invoice->remaining_amount > 0)
                        <form action="{{ route('invoices.addPayment', $invoice) }}" method="POST" class="mt-5 space-y-3">
                            @csrf
                            <label for="payment_amount" class="block text-sm font-semibold text-gray-700">Enregistrer un paiement</label>
                            <input id="payment_amount" type="number" name="amount" min="0.01" step="0.01" required placeholder="Montant" class="w-full rounded-xl border-gray-200 text-sm">
                            <select name="method" required class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="virement">Virement</option>
                                <option value="carte">Carte bancaire</option>
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                            </select>
                            <button type="submit" class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white">Valider le paiement</button>
                        </form>
                    @endif
                </div>

                <div class="rounded-3xl border bg-white p-6 shadow-lg">
                    <h4 class="text-lg font-semibold text-gray-900">Totaux</h4>
                    <div class="mt-5 space-y-3 text-sm">
                        <div class="flex justify-between"><span>Sous-total HT</span><strong>{{ number_format((float)$invoice->subtotal_ht, 2, ',', ' ') }} €</strong></div>
                        <div class="flex justify-between"><span>TVA</span><strong>{{ number_format((float)$invoice->total_tva, 2, ',', ' ') }} €</strong></div>
                        <div class="flex justify-between border-t pt-4 text-lg"><span class="font-semibold">Total TTC</span><strong>{{ number_format((float)$invoice->total_ttc, 2, ',', ' ') }} €</strong></div>
                    </div>
                    @if($invoice->notes)
                        <div class="mt-6 border-t pt-4"><p class="text-sm font-semibold">Notes</p><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $invoice->notes }}</p></div>
                    @endif
                </div>

                <div class="rounded-3xl border bg-white p-6 shadow-lg">
                    <h4 class="text-lg font-semibold text-gray-900">Actions</h4>
                    @if(!$isDraft)
                        <form action="{{ route('invoices.action', $invoice) }}" method="POST" class="mt-5 space-y-3">
                            @csrf
                            <select name="action" required class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="">Choisir une action</option>
                                <option value="pdf">Télécharger PDF</option>
                                <option value="email">Envoyer par e-mail</option>
                                @if($invoice->status !== 'payee')
                                    <option value="pay">Marquer comme payée</option>
                                @endif
                            </select>
                            <button type="submit" class="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white">Valider l'action</button>
                        </form>
                    @else
                        <p class="mt-5 text-sm text-amber-800">Validez le brouillon pour accéder aux exports et à l'envoi.</p>
                    @endif
                    <form action="{{ route('invoices.duplicate', $invoice) }}" method="POST" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700">Dupliquer en brouillon</button>
                    </form>
                </div>
            </div>

            @if($invoice->payments->isNotEmpty())
                <div class="overflow-hidden rounded-3xl border bg-white shadow-lg">
                    <div class="border-b p-6"><h4 class="text-lg font-semibold text-gray-900">Historique des paiements</h4></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50"><tr><th class="px-6 py-4">Date</th><th class="px-6 py-4">Montant</th><th class="px-6 py-4">Mode</th></tr></thead>
                            <tbody class="divide-y">
                                @foreach($invoice->payments as $payment)
                                    <tr>
                                        <td class="px-6 py-4">{{ $payment->paid_at?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="px-6 py-4">{{ number_format((float)$payment->amount, 2, ',', ' ') }} €</td>
                                        <td class="px-6 py-4">{{ ucfirst($payment->method ?? 'Non renseigné') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>