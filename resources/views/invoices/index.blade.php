<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 leading-tight">
                Mes factures
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                Consulte, gère et suis l’ensemble de tes factures.
            </p>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 shadow-sm">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Bandeau haut --}}
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Liste des factures</h3>
                    <p class="text-sm text-gray-500">
                        Retrouve rapidement toutes tes factures et accède aux actions principales.
                    </p>
                </div>

                <a href="{{ route('invoices.create') }}"
                   class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    + Nouvelle facture
                </a>
            </div>

            {{-- Cartes résumé --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Total factures</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ $invoices->total() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Non payées</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ $invoices->getCollection()->where('status', 'non_payee')->count() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Payées</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ $invoices->getCollection()->where('status', 'payee')->count() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Montant visible</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ number_format($invoices->getCollection()->sum('total_ttc'), 2, ',', ' ') }} €
                    </p>
                </div>
            </div>

            {{-- Filtres --}}
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <form method="GET" id="invoice-filter-form" class="flex flex-col gap-3 md:flex-row md:items-center">
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

            {{-- Table des factures --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h3 class="text-lg font-semibold text-gray-900">Toutes les factures</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Clique sur une facture pour la consulter, la modifier ou la dupliquer.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-sm font-semibold text-gray-600">
                                <th class="px-6 py-4">Numéro</th>
                                <th class="px-6 py-4">Client</th>
                                <th class="px-6 py-4">Devis lié</th>
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Échéance</th>
                                <th class="px-6 py-4">Statut</th>
                                <th class="px-6 py-4">Total TTC</th>
                                <th class="px-6 py-4">Reste</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse($invoices as $invoice)
                                @php
                                    $remaining = max(
                                        0,
                                        (float) $invoice->total_ttc - (float) $invoice->amount_paid_calculated
                                    );

                                    $isOverdue = $invoice->status !== 'payee'
                                        && $invoice->due_date
                                        && $invoice->due_date->isPast();

                                    $statusClasses = match (true) {
                                        $isOverdue => 'bg-orange-100 text-orange-700',
                                        $invoice->status === 'payee' => 'bg-green-100 text-green-700',
                                        $invoice->status === 'partiellement_payee' => 'bg-yellow-100 text-yellow-700',
                                        $invoice->status === 'non_payee' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };

                                    $statusLabel = match (true) {
                                        $isOverdue => 'En retard',
                                        $invoice->status === 'payee' => 'Payée',
                                        $invoice->status === 'partiellement_payee' => 'Partielle',
                                        $invoice->status === 'non_payee' => 'Non payée',
                                        default => ucfirst($invoice->status),
                                    };
                                @endphp

                                <tr class="transition hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">
                                            {{ $invoice->invoice_number }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-gray-700">
                                        {{ $invoice->client->full_name ?? 'Client supprimé' }}
                                    </td>

                                    <td class="px-6 py-4 text-gray-500">
                                        @if($invoice->quote)
                                            <a href="{{ route('quotes.show', $invoice->quote) }}"
                                               class="text-blue-600 hover:underline">
                                                {{ $invoice->quote->quote_number }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-gray-500">
                                        {{ $invoice->date ? $invoice->date->format('d/m/Y') : '—' }}
                                    </td>

                                    <td class="px-6 py-4 text-gray-500">
                                        {{ $invoice->due_date ? $invoice->due_date->format('d/m/Y') : '—' }}
                                    </td>

                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 font-semibold text-gray-900">
                                        {{ number_format($invoice->total_ttc, 2, ',', ' ') }} €
                                    </td>

                                    <td class="px-6 py-4 font-semibold text-gray-900">
                                        {{ number_format($remaining, 2, ',', ' ') }} €
                                    </td>

                                    <td class="px-6 py-4">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('invoices.show', $invoice) }}"
                                               class="rounded-md bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 hover:bg-blue-100 transition">
                                                Voir
                                            </a>

                                            <a href="{{ route('invoices.edit', $invoice) }}"
                                               class="rounded-md bg-yellow-50 px-3 py-1 text-xs font-medium text-yellow-600 hover:bg-yellow-100 transition">
                                                Modifier
                                            </a>

                                            <form action="{{ route('invoices.duplicate', $invoice) }}" method="POST">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 transition">
                                                    Dupliquer
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-16 text-center">
                                        <div class="mx-auto max-w-md">
                                            <div class="mb-3 text-4xl">🧾</div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                Aucune facture pour le moment
                                            </h4>
                                            <p class="mt-2 text-sm text-gray-500">
                                                Commence par créer ta première facture ou transforme un devis accepté en facture.
                                            </p>

                                            <div class="mt-6">
                                                <a href="{{ route('invoices.create') }}"
                                                   class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                                    Créer ma première facture
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($invoices->hasPages())
                    <div class="border-t border-gray-100 bg-gray-50 px-4 py-4">
                        {{ $invoices->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>