<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Détail du devis</h2>
    </x-slot>

    <div class="max-w-5xl mx-auto p-6">
        @if(session('success'))
            <div class="mb-4 rounded bg-green-100 px-4 py-3 text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded bg-red-100 px-4 py-3 text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="rounded-lg bg-white p-6 shadow space-y-6">
            <div>
                <h3 class="text-2xl font-bold text-gray-900">
                    {{ $quote->quote_number }}
                </h3>
                <p class="mt-1 text-sm text-gray-500">
                    Créé le {{ $quote->date->format('d/m/Y') }}
                </p>
            </div>

            <div class="border-t pt-4">
                <p class="mb-1 text-sm font-medium text-gray-700">Client</p>
                <p class="text-gray-900">{{ $quote->client->full_name }}</p>
            </div>

            <div class="border-t pt-4">
                <p class="mb-2 text-sm font-medium text-gray-700">Facture liée</p>

                @if($quote->invoice)
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="font-medium text-green-700">
                            Ce devis a été transformé en facture : {{ $quote->invoice->invoice_number }}
                        </p>

                        <a href="{{ route('invoices.show', $quote->invoice) }}"
                           class="inline-block rounded bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700">
                            Voir la facture
                        </a>
                    </div>
                @else
                    <p class="text-gray-500">
                        Aucune facture liée pour le moment.
                    </p>
                @endif
            </div>

            <div class="border-t pt-4">
                <p class="mb-2 text-sm font-medium text-gray-700">Statut</p>

                @php
                    $statusClasses = match ($quote->status) {
                        'brouillon' => 'bg-gray-100 text-gray-800',
                        'envoye' => 'bg-blue-100 text-blue-800',
                        'accepte' => 'bg-green-100 text-green-800',
                        'refuse' => 'bg-red-100 text-red-800',
                        default => 'bg-gray-100 text-gray-800',
                    };

                    $statusLabel = match ($quote->status) {
                        'brouillon' => 'Brouillon',
                        'envoye' => 'Envoyé',
                        'accepte' => 'Accepté',
                        'refuse' => 'Refusé',
                        default => ucfirst($quote->status),
                    };
                @endphp

                <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $statusClasses }}">
                    {{ $statusLabel }}
                </span>

                <div class="mt-4">
                    <a href="{{ route('quotes.edit', $quote) }}"
                       class="inline-block rounded bg-yellow-500 px-4 py-2 text-white hover:bg-yellow-600">
                        Modifier
                    </a>
                </div>
            </div>

            <div class="border-t pt-4">
                <p class="mb-3 text-sm font-medium text-gray-700">Détail du devis</p>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b bg-gray-50">
                                <th class="p-2 text-left">Description</th>
                                <th class="p-2 text-left">Qté</th>
                                <th class="p-2 text-left">Prix unitaire</th>
                                <th class="p-2 text-left">Total HT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($quote->items as $item)
                                <tr class="border-b">
                                    <td class="p-2">{{ $item->description }}</td>
                                    <td class="p-2">{{ $item->quantity }}</td>
                                    <td class="p-2">{{ number_format($item->unit_price_ht, 2) }} €</td>
                                    <td class="p-2">{{ number_format($item->line_total_ht, 2) }} €</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="p-2 text-gray-500">
                                        Aucune ligne de devis enregistrée.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="border-t pt-4 text-right space-y-2">
                @php
                    $quoteTvaRate = $quote->items->first()?->tva_rate ?? 0;
                @endphp

                <p class="text-sm text-gray-600">
                    Total HT :
                    <span class="font-medium">{{ number_format($quote->subtotal_ht, 2) }} €</span>
                </p>

                <p class="text-sm text-gray-600">
                    TVA ({{ number_format($quoteTvaRate, 0) }} %) :
                    <span class="font-medium">{{ number_format($quote->total_tva, 2) }} €</span>
                </p>

                <p class="text-xl font-bold text-gray-900">
                    Total TTC : {{ number_format($quote->total_ttc, 2) }} €
                </p>
            </div>

            <div class="border-t pt-6">
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('quotes.pdf', $quote) }}"
                       class="inline-block rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
                        Télécharger PDF
                    </a>

                    <form action="{{ route('quotes.duplicate', $quote) }}" method="POST">
    @csrf
    <button type="submit"
            class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200">
        Dupliquer
    </button>
</form>

                    @if (!$quote->invoice)
                        <form action="{{ route('quotes.convertToInvoice', $quote) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="inline-block rounded bg-red-600 px-4 py-2 text-white shadow border border-red-800 hover:bg-red-700">
                                Transformer en facture
                            </button>
                        </form>
                    @else
                        <span class="inline-block rounded bg-gray-100 px-4 py-2 text-gray-600">
                            Déjà transformé en facture
                        </span>
                    @endif

                    <a href="{{ route('quotes.index') }}"
                       class="inline-block rounded bg-gray-200 px-4 py-2 text-gray-800 hover:bg-gray-300">
                        Retour
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>