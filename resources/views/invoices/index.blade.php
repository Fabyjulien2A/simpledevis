<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-800 leading-tight">
            Mes factures
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

            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">
                        Liste des factures
                    </h3>
                    <p class="text-sm text-gray-500">
                        Suis tes factures et l’état des paiements
                    </p>
                </div>
            </div>

            {{-- Filtres --}}
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <form method="GET" id="filter-form" class="flex flex-col gap-3 md:flex-row md:items-center">
                    <input
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Rechercher par numéro, client ou montant..."
                        class="w-full md:w-1/3 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        oninput="clearTimeout(window.invoiceSearchTimeout); window.invoiceSearchTimeout = setTimeout(() => this.form.submit(), 400);"
                    >

                    <select
                        name="status"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        onchange="this.form.submit()"
                    >
                        <option value="">Tous les statuts</option>
                        <option value="non_payee" {{ request('status') == 'non_payee' ? 'selected' : '' }}>
                            Non payée
                        </option>
                        <option value="partiellement_payee" {{ request('status') == 'partiellement_payee' ? 'selected' : '' }}>
                            Partiellement payée
                        </option>
                        <option value="payee" {{ request('status') == 'payee' ? 'selected' : '' }}>
                            Payée
                        </option>
                        <option value="overdue" {{ request('status') == 'overdue' ? 'selected' : '' }}>
                            En retard
                        </option>
                    </select>

                    <a href="{{ route('invoices.index') }}"
                       class="text-sm text-gray-500 hover:underline">
                        Réinitialiser
                    </a>
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                @if($invoices->count())
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-gray-50 text-gray-600">
                                    <th class="px-4 py-3 text-left font-medium">Numéro</th>
                                    <th class="px-4 py-3 text-left font-medium">Client</th>
                                    <th class="px-4 py-3 text-left font-medium">Devis lié</th>
                                    <th class="px-4 py-3 text-left font-medium">Date</th>
                                    <th class="px-4 py-3 text-left font-medium">Statut</th>
                                    <th class="px-4 py-3 text-left font-medium">Total TTC</th>
                                    <th class="px-4 py-3 text-left font-medium">Reste</th>
                                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                @foreach($invoices as $invoice)
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

                                    <tr class="transition hover:bg-gray-50">
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            {{ $invoice->invoice_number }}
                                        </td>

                                        <td class="px-4 py-3 text-gray-700">
                                            {{ $invoice->client->full_name }}
                                        </td>

                                        <td class="px-4 py-3 text-gray-500">
                                            {{ $invoice->quote?->quote_number ?? '—' }}
                                        </td>

                                        <td class="px-4 py-3 text-gray-500">
                                            {{ $invoice->date->format('d/m/Y') }}
                                        </td>

                                        <td class="px-4 py-3">
                                            @if($invoice->is_overdue)
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium bg-red-200 text-red-800">
                                                    En retard
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium {{ $statusClasses }}">
                                                    {{ $statusLabel }}
                                                </span>
                                            @endif
                                        </td>

                                        <td class="px-4 py-3 font-semibold text-gray-900">
                                            {{ number_format($invoice->total_ttc, 2) }} €
                                        </td>

                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            {{ number_format($invoice->remaining_amount, 2) }} €
                                        </td>

                                        <td class="px-4 py-3 text-right">
                                            <div class="flex justify-end gap-2">
                                                <a href="{{ route('invoices.show', $invoice) }}"
                                                   class="rounded-md bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 transition hover:bg-blue-100">
                                                    Voir
                                                </a>

                                                <form action="{{ route('invoices.duplicate', $invoice) }}" method="POST">
                                                    @csrf
                                                    <button type="submit"
                                                            class="rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-200">
                                                        Dupliquer
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t bg-gray-50 p-4">
                        {{ $invoices->links() }}
                    </div>
                @else
                    <div class="px-4 py-10 text-center text-gray-500">
                        Aucune facture enregistrée pour le moment.
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>