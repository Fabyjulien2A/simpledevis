<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-800 leading-tight">
            Détail de la facture
        </h2>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div class="rounded-xl border border-green-200 bg-green-100 px-4 py-3 text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="rounded-xl border border-red-200 bg-red-100 px-4 py-3 text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-100 px-4 py-3 text-red-800">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <div class="p-6 lg:p-8 space-y-8">

                    {{-- En-tête --}}
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Facture</p>
                            <h3 class="mt-1 text-2xl font-bold text-gray-900">
                                {{ $invoice->invoice_number }}
                            </h3>
                            <p class="mt-2 text-sm text-gray-500">
                                Créée le {{ $invoice->date->format('d/m/Y') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <a href="{{ route('invoices.index') }}"
                               class="inline-flex items-center rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-800 transition hover:bg-gray-300">
                                Retour
                            </a>
                        </div>
                    </div>

                    {{-- Infos principales --}}
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                            <p class="text-sm font-medium text-gray-500">Client</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">
                                {{ $invoice->client->full_name }}
                            </p>

                            @if($invoice->client->email)
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ $invoice->client->email }}
                                </p>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                            <p class="text-sm font-medium text-gray-500">Devis lié</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">
                                {{ $invoice->quote?->quote_number ?? 'Aucun devis lié' }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                            <p class="text-sm font-medium text-gray-500">Statut</p>

                            @php
                                $statusClasses = match ($invoice->status) {
                                    'non_payee' => 'bg-red-100 text-red-700',
                                    'payee' => 'bg-green-100 text-green-700',
                                    'partiellement_payee' => 'bg-yellow-100 text-yellow-700',
                                    default => 'bg-gray-100 text-gray-700',
                                };

                                $statusLabel = match ($invoice->status) {
                                    'non_payee' => 'Non payée',
                                    'payee' => 'Payée',
                                    'partiellement_payee' => 'Partiellement payée',
                                    default => ucfirst($invoice->status),
                                };
                            @endphp

                            <div class="mt-3">
                                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $statusClasses }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                            <p class="text-sm font-medium text-gray-500">Échéance</p>

                            @if($invoice->due_date)
                                <p class="mt-2 text-base font-semibold text-gray-900">
                                    {{ $invoice->due_date->format('d/m/Y') }}
                                </p>

                                @if($invoice->is_overdue)
                                    <p class="mt-1 text-sm font-medium text-red-600">
                                        En retard de paiement
                                    </p>
                                @endif
                            @else
                                <p class="mt-2 text-sm text-gray-500">
                                    Non définie
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- Tableau lignes --}}
                    <div>
                        <div class="mb-4">
                            <h4 class="text-lg font-semibold text-gray-800">Détail de la facture</h4>
                            <p class="text-sm text-gray-500">Prestations ou produits facturés</p>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-gray-100">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-gray-50 text-gray-600">
                                        <th class="px-4 py-3 text-left font-medium">Description</th>
                                        <th class="px-4 py-3 text-left font-medium">Qté</th>
                                        <th class="px-4 py-3 text-left font-medium">Prix unitaire</th>
                                        <th class="px-4 py-3 text-left font-medium">Total HT</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse($invoice->items as $item)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-4 py-3 text-gray-800">{{ $item->description }}</td>
                                            <td class="px-4 py-3 text-gray-500">{{ number_format($item->quantity, 2) }}</td>
                                            <td class="px-4 py-3 text-gray-500">{{ number_format($item->unit_price_ht, 2) }} €</td>
                                            <td class="px-4 py-3 font-medium text-gray-900">{{ number_format($item->line_total_ht, 2) }} €</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                                Aucune ligne de facture
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Paiement + totaux + actions --}}
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                        <div class="rounded-2xl border border-gray-100 bg-white p-5">
                            <h4 class="text-lg font-semibold text-gray-800">Paiement</h4>

                            <div class="mt-4 space-y-3 text-sm">
                                <div class="flex items-center justify-between text-gray-600">
                                    <span>Payé</span>
                                    <span class="font-medium text-gray-900">
                                        {{ number_format($invoice->amount_paid_calculated, 2) }} €
                                    </span>
                                </div>

                                <div class="flex items-center justify-between text-gray-600">
                                    <span>Reste</span>
                                    <span class="font-medium text-gray-900">
                                        {{ number_format($invoice->remaining_amount, 2) }} €
                                    </span>
                                </div>
                            </div>

                            @if($invoice->payments->count())
    @php
        $lastPayment = $invoice->payments->sortByDesc('paid_at')->first();

        $lastMethodLabel = match ($lastPayment->method) {
            'virement' => 'Virement',
            'carte' => 'Carte bancaire',
            'especes' => 'Espèces',
            'cheque' => 'Chèque',
            default => ucfirst($lastPayment->method ?? 'Non renseigné'),
        };
    @endphp

    <div class="flex items-center justify-between text-gray-600">
        <span>Dernier paiement</span>
        <span class="font-medium text-gray-900">
            {{ $lastMethodLabel }}
        </span>
    </div>
@endif

                            <form action="{{ route('invoices.addPayment', $invoice) }}" method="POST" class="mt-5">
    @csrf

    <label class="mb-2 block text-sm font-medium text-gray-700">
        Enregistrer un paiement
    </label>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <input
            type="number"
            name="amount"
            step="0.01"
            min="0.01"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            placeholder="0.00"
        >

        <select
            name="method"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
            <option value="virement">Virement</option>
            <option value="carte">Carte bancaire</option>
            <option value="especes">Espèces</option>
            <option value="cheque">Chèque</option>
        </select>

        <button class="rounded-lg bg-purple-600 px-4 py-2 text-white">
            Valider
        </button>
    </div>
</form>
                        </div>

                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                            <h4 class="text-lg font-semibold text-gray-800">Totaux</h4>

                            <div class="mt-4 space-y-3 text-sm">
                                <div class="flex items-center justify-between text-gray-600">
                                    <span>Total HT</span>
                                    <span class="font-medium text-gray-900">
                                        {{ number_format($invoice->subtotal_ht, 2) }} €
                                    </span>
                                </div>

                                <div class="flex items-center justify-between text-gray-600">
                                    <span>TVA</span>
                                    <span class="font-medium text-gray-900">
                                        {{ number_format($invoice->total_tva, 2) }} €
                                    </span>
                                </div>

                                <div class="border-t pt-3">
                                    <div class="flex items-center justify-between text-base font-bold text-gray-900">
                                        <span>Total TTC</span>
                                        <span>{{ number_format($invoice->total_ttc, 2) }} €</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-100 bg-white p-5">
                            <h4 class="text-lg font-semibold text-gray-800">Actions</h4>
                            <p class="mt-1 text-sm text-gray-500">
                                Gère la facture à l’aide du menu d’action.
                            </p>

                            <form action="{{ route('invoices.action', $invoice) }}" method="POST" class="mt-5 space-y-3">
                                @csrf

                                <select name="action"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-500">
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
                                        class="inline-flex w-full items-center justify-center rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-900">
                                    Valider l’action
                                </button>
                            </form>

                            <form action="{{ route('invoices.duplicate', $invoice) }}" method="POST" class="mt-3">
                                @csrf
                                <button type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200">
                                    Dupliquer la facture
                                </button>
                            </form>
                        </div>

                    </div>

                    {{-- Historique des paiements --}}
                    @if($invoice->payments->count())
    <div>
        <div class="mb-4">
            <h4 class="text-lg font-semibold text-gray-800">Historique des paiements</h4>
            <p class="text-sm text-gray-500">Liste des paiements enregistrés sur cette facture</p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-100">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-gray-600">
                        <th class="px-4 py-3 text-left font-medium">Date</th>
                        <th class="px-4 py-3 text-left font-medium">Montant</th>
                        <th class="px-4 py-3 text-left font-medium">Mode</th>
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

                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3 text-gray-700">
                                {{ $payment->paid_at->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-900">
                                {{ number_format($payment->amount, 2) }} €
                            </td>
                            <td class="px-4 py-3 text-gray-700">
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
        </div>
    </div>
</x-app-layout>