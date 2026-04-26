<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">Facturation</p>
                <h2 class="text-2xl font-bold text-gray-900 leading-tight">
                    Détail de la facture
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Consultez les informations, paiements et actions disponibles pour cette facture.
                </p>
            </div>

            <a href="{{ route('invoices.index') }}"
               class="inline-flex items-center justify-center rounded-2xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                Retour aux factures
            </a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50 py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 shadow-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $statusClasses = match ($invoice->status) {
                    'non_payee' => 'bg-red-100 text-red-700 border-red-200',
                    'payee' => 'bg-green-100 text-green-700 border-green-200',
                    'partiellement_payee' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                    default => 'bg-gray-100 text-gray-700 border-gray-200',
                };

                $statusLabel = match ($invoice->status) {
                    'non_payee' => 'Non payée',
                    'payee' => 'Payée',
                    'partiellement_payee' => 'Partiellement payée',
                    default => ucfirst($invoice->status),
                };

                $lastPayment = $invoice->payments->sortByDesc('paid_at')->first();

                $lastMethodLabel = $lastPayment
                    ? match ($lastPayment->method) {
                        'virement' => 'Virement',
                        'carte' => 'Carte bancaire',
                        'especes' => 'Espèces',
                        'cheque' => 'Chèque',
                        default => ucfirst($lastPayment->method ?? 'Non renseigné'),
                    }
                    : null;
            @endphp

            <!-- Hero facture -->
            <div class="overflow-hidden rounded-3xl border border-white/60 bg-white/95 shadow-2xl shadow-gray-200/40 backdrop-blur-sm">
                <div class="bg-gradient-to-r from-indigo-600 via-indigo-700 to-purple-700 px-6 py-8 text-white lg:px-8">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-sm font-medium text-white/75">Facture</p>
                            <h3 class="mt-2 text-3xl font-bold tracking-tight">
                                {{ $invoice->invoice_number }}
                            </h3>
                            <p class="mt-2 text-sm text-white/80">
                                Créée le {{ $invoice->date->format('d/m/Y') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <span class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold {{ $statusClasses }}">
                                {{ $statusLabel }}
                            </span>

                            @if($invoice->due_date)
                                <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-medium text-white">
                                    Échéance : {{ $invoice->due_date->format('d/m/Y') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4 lg:p-8">
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <p class="text-sm font-medium text-gray-500">Client</p>
                        <p class="mt-2 text-base font-semibold text-gray-900">
                            {{ $invoice->client->full_name }}
                        </p>
                        @if($invoice->client->email)
                            <p class="mt-1 text-sm text-gray-500">{{ $invoice->client->email }}</p>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <p class="text-sm font-medium text-gray-500">Devis lié</p>
                        <p class="mt-2 text-base font-semibold text-gray-900">
                            {{ $invoice->quote?->quote_number ?? 'Aucun devis lié' }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <p class="text-sm font-medium text-gray-500">Montant TTC</p>
                        <p class="mt-2 text-base font-semibold text-gray-900">
                            {{ number_format($invoice->total_ttc, 2, ',', ' ') }} €
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <p class="text-sm font-medium text-gray-500">Paiement</p>
                        @if($invoice->due_date)
                            <p class="mt-2 text-base font-semibold text-gray-900">
                                {{ $invoice->due_date->format('d/m/Y') }}
                            </p>

                            @if($invoice->is_overdue)
                                <p class="mt-1 text-sm font-medium text-red-600">
                                    En retard de paiement
                                </p>
                            @else
                                <p class="mt-1 text-sm text-gray-500">
                                    Échéance définie
                                </p>
                            @endif
                        @else
                            <p class="mt-2 text-sm text-gray-500">Échéance non définie</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Facturation électronique -->
            <div class="rounded-3xl border border-white/60 bg-white/95 p-6 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">
                                Facturation électronique
                            </span>

                            @if($invoice->is_electronic)
                                <span class="inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                    {{ $invoice->pdp_status_label }}
                                </span>
                            @else
                                <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                                    Non généré
                                </span>
                            @endif
                        </div>

                        @if($invoice->is_electronic)
                            <p class="mt-3 text-sm font-medium text-gray-900">
                                Cette facture a été préparée pour l’export électronique.
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                Format : {{ strtoupper($invoice->electronic_format ?? 'XML') }}
                                @if($invoice->xml_generated_at)
                                    · Généré le {{ $invoice->xml_generated_at->format('d/m/Y à H:i') }}
                                @endif
                            </p>
                        @else
                            <p class="mt-3 text-sm font-medium text-gray-900">
                                Cette facture n’a pas encore d’export électronique.
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                Télécharge le XML pour préparer cette facture à la future facturation électronique.
                            </p>
                        @endif
                    </div>

                    <a href="{{ route('invoices.xml', $invoice) }}"
                       class="inline-flex items-center justify-center rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100">
                        {{ $invoice->is_electronic ? 'Télécharger à nouveau le XML' : 'Générer le XML' }}
                    </a>
                </div>
            </div>

            <!-- Lignes de facture -->
            <div class="overflow-hidden rounded-3xl border border-white/60 bg-white/95 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                <div class="border-b border-gray-100 px-6 py-5 lg:px-8">
                    <h4 class="text-lg font-semibold text-gray-900">Lignes de facture</h4>
                    <p class="mt-1 text-sm text-gray-500">Prestations ou produits facturés</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-gray-50/80 text-gray-600">
                                <th class="px-6 py-4 text-left font-semibold">Description</th>
                                <th class="px-6 py-4 text-left font-semibold">Qté</th>
                                <th class="px-6 py-4 text-left font-semibold">Prix unitaire</th>
                                <th class="px-6 py-4 text-left font-semibold">Total HT</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($invoice->items as $item)
                                <tr class="transition hover:bg-indigo-50/30">
                                    <td class="px-6 py-4 text-gray-800">{{ $item->description }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ number_format($item->unit_price_ht, 2, ',', ' ') }} €</td>
                                    <td class="px-6 py-4 font-semibold text-gray-900">{{ number_format($item->line_total_ht, 2, ',', ' ') }} €</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-gray-500">
                                        Aucune ligne de facture
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cartes du bas -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- Paiement -->
                <div class="rounded-3xl border border-white/60 bg-white/95 p-6 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900">Paiement</h4>
                            <p class="mt-1 text-sm text-gray-500">Suivi et enregistrement des paiements</p>
                        </div>
                        <div class="rounded-2xl bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600">
                            Suivi
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div class="rounded-2xl bg-gray-50 p-4">
                            <div class="flex items-center justify-between text-sm text-gray-600">
                                <span>Payé</span>
                                <span class="font-semibold text-gray-900">
                                    {{ number_format($invoice->amount_paid_calculated, 2, ',', ' ') }} €
                                </span>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-gray-50 p-4">
                            <div class="flex items-center justify-between text-sm text-gray-600">
                                <span>Reste</span>
                                <span class="font-semibold text-gray-900">
                                    {{ number_format($invoice->remaining_amount, 2, ',', ' ') }} €
                                </span>
                            </div>
                        </div>

                        @if($lastMethodLabel)
                            <div class="rounded-2xl bg-gray-50 p-4">
                                <div class="flex items-center justify-between text-sm text-gray-600">
                                    <span>Dernier paiement</span>
                                    <span class="font-semibold text-gray-900">{{ $lastMethodLabel }}</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <form action="{{ route('invoices.addPayment', $invoice) }}" method="POST" class="mt-6 space-y-3">
                        @csrf

                        <label class="block text-sm font-semibold text-gray-700">
                            Enregistrer un paiement
                        </label>

                        <div class="grid grid-cols-1 gap-3">
                            <input
                                type="number"
                                name="amount"
                                step="0.01"
                                min="0.01"
                                class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                placeholder="Montant"
                            >

                            <select
                                name="method"
                                class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                            >
                                <option value="virement">Virement</option>
                                <option value="carte">Carte bancaire</option>
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                            </select>

                            <button
                                class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition hover:from-indigo-700 hover:to-purple-700"
                            >
                                Valider le paiement
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Totaux -->
                <div class="rounded-3xl border border-white/60 bg-white/95 p-6 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900">Totaux</h4>
                            <p class="mt-1 text-sm text-gray-500">Résumé financier de la facture</p>
                        </div>
                        <div class="rounded-2xl bg-purple-50 px-3 py-1 text-xs font-semibold text-purple-600">
                            Résumé
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div class="flex items-center justify-between rounded-2xl bg-gray-50 p-4 text-sm text-gray-600">
                            <span>Total HT</span>
                            <span class="font-semibold text-gray-900">{{ number_format($invoice->subtotal_ht, 2, ',', ' ') }} €</span>
                        </div>

                        <div class="flex items-center justify-between rounded-2xl bg-gray-50 p-4 text-sm text-gray-600">
                            <span>TVA</span>
                            <span class="font-semibold text-gray-900">{{ number_format($invoice->total_tva, 2, ',', ' ') }} €</span>
                        </div>

                        <div class="rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 p-5 text-white shadow-lg">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-white/80">Total TTC</span>
                                <span class="text-xl font-bold">{{ number_format($invoice->total_ttc, 2, ',', ' ') }} €</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="rounded-3xl border border-white/60 bg-white/95 p-6 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900">Actions</h4>
                            <p class="mt-1 text-sm text-gray-500">Gérez la facture rapidement</p>
                        </div>
                        <div class="rounded-2xl bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                            Outils
                        </div>
                    </div>

                    <form action="{{ route('invoices.action', $invoice) }}" method="POST" class="mt-6 space-y-3">
                        @csrf

                        <select name="action"
                                class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            <option value="">-- Action --</option>
                            <option value="pdf">Télécharger PDF</option>
                            <option value="email">Envoyer par email</option>

                            @if($invoice->status !== 'payee')
                                <option value="pay">Marquer comme payée</option>
                            @else
                                <option value="unpay">Remettre en non payée</option>
                            @endif
                        </select>

                        <button type="submit"
                                class="inline-flex w-full items-center justify-center rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-black">
                            Valider l’action
                        </button>
                    </form>

                    <form action="{{ route('invoices.duplicate', $invoice) }}" method="POST" class="mt-3">
                        @csrf
                        <button type="submit"
                                class="inline-flex w-full items-center justify-center rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                            Dupliquer la facture
                        </button>
                    </form>

                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">
                            Export
                        </p>

                        <a href="{{ route('invoices.xml', $invoice) }}"
                           class="inline-flex w-full items-center justify-center rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100">
                            Télécharger XML
                        </a>
                    </div>
                </div>
            </div>

            <!-- Historique -->
            @if($invoice->payments->count())
                <div class="overflow-hidden rounded-3xl border border-white/60 bg-white/95 shadow-xl shadow-gray-200/30 backdrop-blur-sm">
                    <div class="border-b border-gray-100 px-6 py-5 lg:px-8">
                        <h4 class="text-lg font-semibold text-gray-900">Historique des paiements</h4>
                        <p class="mt-1 text-sm text-gray-500">Liste des paiements enregistrés sur cette facture</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-gray-50/80 text-gray-600">
                                    <th class="px-6 py-4 text-left font-semibold">Date</th>
                                    <th class="px-6 py-4 text-left font-semibold">Montant</th>
                                    <th class="px-6 py-4 text-left font-semibold">Mode</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach($invoice->payments as $payment)
                                    @php
                                        $methodLabel = match ($payment->method) {
                                            'virement' => 'Virement',
                                            'carte' => 'Carte bancaire',
                                            'especes' => 'Espèces',
                                            'cheque' => 'Chèque',
                                            default => ucfirst($payment->method ?? 'Non renseigné'),
                                        };
                                    @endphp

                                    <tr class="transition hover:bg-indigo-50/30">
                                        <td class="px-6 py-4 text-gray-700">
                                            {{ $payment->paid_at->format('d/m/Y') }}
                                        </td>
                                        <td class="px-6 py-4 font-semibold text-gray-900">
                                            {{ number_format($payment->amount, 2, ',', ' ') }} €
                                        </td>
                                        <td class="px-6 py-4 text-gray-700">
                                            {{ $methodLabel }}
                                        </td>
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