<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 leading-tight">
                Mes devis
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                Consulte, gère et suis l’ensemble de tes devis.
            </p>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Message succès --}}
            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Bandeau haut --}}
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Liste des devis</h3>
                    <p class="text-sm text-gray-500">
                        Retrouve rapidement tous tes devis et accède aux actions principales.
                    </p>
                </div>

                <a href="{{ route('quotes.create') }}"
                   class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    + Nouveau devis
                </a>
            </div>

            {{-- Cartes résumé --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Total devis</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ $quotes->total() }}</p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Brouillons</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ $quotes->getCollection()->where('status', 'brouillon')->count() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Acceptés</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ $quotes->getCollection()->where('status', 'accepte')->count() }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-gray-500">Montant visible</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">
                        {{ number_format($quotes->getCollection()->sum('total_ttc'), 2, ',', ' ') }} €
                    </p>
                </div>
            </div>

            {{-- Table des devis --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h3 class="text-lg font-semibold text-gray-900">Tous les devis</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Clique sur un devis pour le consulter ou le modifier.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-sm font-semibold text-gray-600">
                                <th class="px-6 py-4">Numéro</th>
                                <th class="px-6 py-4">Client</th>
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Statut</th>
                                <th class="px-6 py-4">Total TTC</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse($quotes as $quote)
                                @php
                                    $statusClasses = match ($quote->status) {
                                        'brouillon' => 'bg-gray-100 text-gray-700',
                                        'envoye' => 'bg-blue-100 text-blue-700',
                                        'accepte' => 'bg-green-100 text-green-700',
                                        'refuse' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };

                                    $statusLabel = match ($quote->status) {
                                        'brouillon' => 'Brouillon',
                                        'envoye' => 'Envoyé',
                                        'accepte' => 'Accepté',
                                        'refuse' => 'Refusé',
                                        default => ucfirst($quote->status),
                                    };
                                @endphp

                                <tr class="transition hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">
                                            {{ $quote->quote_number }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-gray-700">
                                        {{ $quote->client->full_name }}
                                    </td>

                                    <td class="px-6 py-4 text-gray-500">
                                        {{ $quote->date->format('d/m/Y') }}
                                    </td>

                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 font-semibold text-gray-900">
                                        {{ number_format($quote->total_ttc, 2, ',', ' ') }} €
                                    </td>

                                    <td class="px-6 py-4">
                                      <div class="flex justify-end gap-2">
    <a href="{{ route('quotes.show', $quote) }}"
       class="rounded-md bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 hover:bg-blue-100 transition">
        Voir
    </a>

    <a href="{{ route('quotes.edit', $quote) }}"
       class="rounded-md bg-yellow-50 px-3 py-1 text-xs font-medium text-yellow-600 hover:bg-yellow-100 transition">
        Modifier
    </a>

    <form action="{{ route('quotes.duplicate', $quote) }}" method="POST">
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
                                    <td colspan="6" class="px-6 py-16 text-center">
                                        <div class="mx-auto max-w-md">
                                            <div class="text-4xl mb-3">📄</div>
                                            <h4 class="text-lg font-semibold text-gray-900">Aucun devis pour le moment</h4>
                                            <p class="mt-2 text-sm text-gray-500">
                                                Commence par créer ton premier devis pour voir apparaître ta liste ici.
                                            </p>

                                            <div class="mt-6">
                                                <a href="{{ route('quotes.create') }}"
                                                   class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                                    Créer mon premier devis
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($quotes->hasPages())
                    <div class="border-t border-gray-100 bg-gray-50 px-4 py-4">
                        {{ $quotes->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>