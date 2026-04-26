<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Créer un devis</h2>
            <p class="mt-1 text-sm text-gray-500">
                Prépare un devis clair et professionnel pour ton client.
            </p>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('quotes.store') }}" class="space-y-8">
            @csrf

            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Informations du devis</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Sélectionne le client et configure la TVA globale.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Client
                        </label>
                        <select
                            name="client_id"
                            id="client_id"
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required
                        >
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" {{ old('client_id') == $client->id ? 'selected' : '' }}>
                                    {{ $client->full_name }}
                                </option>
                            @endforeach
                        </select>

                        @error('client_id')
                            <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="tva_rate" class="block text-sm font-medium text-gray-700 mb-2">
                            Taux de TVA
                        </label>
                        <select
                            name="tva_rate"
                            id="tva_rate"
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required
                        >
                            <option value="0" {{ old('tva_rate') == '0' ? 'selected' : '' }}>0 %</option>
                            <option value="10" {{ old('tva_rate') == '10' ? 'selected' : '' }}>10 %</option>
                            <option value="20" {{ old('tva_rate', '20') == '20' ? 'selected' : '' }}>20 %</option>
                        </select>

                        @error('tva_rate')
                            <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Lignes du devis</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Renseigne les prestations ou produits à facturer.
                        </p>
                    </div>

                    <button
                        type="button"
                        id="add-row"
                        class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                    >
                        + Ajouter une ligne
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-sm font-semibold text-gray-600">
                                <th class="px-6 py-4">Description</th>
                                <th class="px-4 py-4 w-36">Qté</th>
                                <th class="px-4 py-4 w-52">Prix unitaire (€)</th>
                                <th class="px-6 py-4 w-44 text-right">Total HT</th>
                                <th class="px-6 py-4 w-32 text-right">Action</th>
                            </tr>
                        </thead>

                        <tbody id="quote-items-body" class="divide-y divide-gray-100">
                            @php
                                $oldItems = old('items', [
                                    ['description' => '', 'quantity' => 1, 'price' => ''],
                                    ['description' => '', 'quantity' => 1, 'price' => ''],
                                    ['description' => '', 'quantity' => 1, 'price' => ''],
                                ]);
                            @endphp

                            @foreach($oldItems as $i => $oldItem)
                                <tr class="quote-row hover:bg-gray-50/70 transition">
                                    <td class="px-6 py-4">
                                        <input
                                            type="text"
                                            name="items[{{ $i }}][description]"
                                            value="{{ $oldItem['description'] ?? '' }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            placeholder="Ex : Création site vitrine"
                                        >
                                    </td>

                                    <td class="px-4 py-4">
                                        <input
                                            type="number"
                                            step="1"
                                            min="1"
                                            name="items[{{ $i }}][quantity]"
                                            value="{{ $oldItem['quantity'] ?? 1 }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 quantity-input"
                                        >
                                    </td>

                                    <td class="px-4 py-4">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="items[{{ $i }}][price]"
                                            value="{{ $oldItem['price'] ?? '' }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 price-input"
                                            placeholder="0.00"
                                        >
                                    </td>

                                    <td class="px-6 py-4 text-right">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700 line-total">
                                            0.00 €
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 text-right">
                                        <button
                                            type="button"
                                            class="remove-row inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100"
                                        >
                                            Supprimer
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @error('items')
                    <div class="px-6 pb-6">
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    </div>
                @enderror
            </div>

            <div class="flex justify-end mt-10">
                <div class="w-full max-w-md bg-white border border-gray-200 rounded-2xl shadow-lg p-6">
                    <div class="mb-6">
                        <p class="text-sm text-gray-500">Résumé</p>
                        <h3 class="text-lg font-semibold text-gray-900 mt-1">Montants du devis</h3>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between border-b pb-3">
                            <span class="text-sm text-gray-600">Total HT</span>
                            <span id="global-total-ht" class="font-semibold text-gray-900">0.00 €</span>
                        </div>

                        <div class="flex items-center justify-between border-b pb-3">
                            <span class="text-sm text-gray-600">TVA</span>
                            <span id="global-total-tva" class="font-semibold text-gray-900">0.00 €</span>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <span class="text-base font-semibold text-gray-900">Total TTC</span>
                            <span id="global-total-ttc" class="text-2xl font-bold text-blue-600">0.00 €</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('quotes.index') }}"
                   class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition">
                    Annuler
                </a>

                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition">
                    Créer le devis
                </button>
            </div>
        </form>
    </div>

    <script>
        let rowIndex = document.querySelectorAll('.quote-row').length;

        function updateQuoteTotals() {
            const rows = document.querySelectorAll('.quote-row');
            const tvaSelect = document.getElementById('tva_rate');

            let globalTotalHt = 0;
            const tvaRate = parseFloat(tvaSelect.value) || 0;

            rows.forEach((row) => {
                const quantityInput = row.querySelector('.quantity-input');
                const priceInput = row.querySelector('.price-input');
                const totalCell = row.querySelector('.line-total');

                const quantity = parseFloat(quantityInput.value) || 0;
                const price = parseFloat(priceInput.value) || 0;
                const lineTotal = quantity * price;

                totalCell.textContent = lineTotal.toFixed(2) + ' €';
                globalTotalHt += lineTotal;
            });

            const totalTva = globalTotalHt * (tvaRate / 100);
            const totalTtc = globalTotalHt + totalTva;

            document.getElementById('global-total-ht').textContent = globalTotalHt.toFixed(2) + ' €';
            document.getElementById('global-total-tva').textContent = totalTva.toFixed(2) + ' €';
            document.getElementById('global-total-ttc').textContent = totalTtc.toFixed(2) + ' €';
        }

        function bindRowEvents(row) {
            row.querySelectorAll('.quantity-input, .price-input').forEach((input) => {
                input.addEventListener('input', updateQuoteTotals);
            });

            row.querySelector('.remove-row').addEventListener('click', () => {
                const rows = document.querySelectorAll('.quote-row');

                if (rows.length <= 1) {
                    alert('Tu dois garder au moins une ligne dans le devis.');
                    return;
                }

                row.remove();
                updateQuoteTotals();
            });
        }

        function createRow() {
            const tbody = document.getElementById('quote-items-body');

            const tr = document.createElement('tr');
            tr.className = 'quote-row hover:bg-gray-50/70 transition';

            tr.innerHTML = `
                <td class="px-6 py-4">
                    <input
                        type="text"
                        name="items[${rowIndex}][description]"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Ex : Création site vitrine"
                    >
                </td>

                <td class="px-4 py-4">
                    <input
                        type="number"
                        step="1"
                        min="1"
                        name="items[${rowIndex}][quantity]"
                        value="1"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 quantity-input"
                    >
                </td>

                <td class="px-4 py-4">
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="items[${rowIndex}][price]"
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 price-input"
                        placeholder="0.00"
                    >
                </td>

                <td class="px-6 py-4 text-right">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700 line-total">
                        0.00 €
                    </span>
                </td>

                <td class="px-6 py-4 text-right">
                    <button
                        type="button"
                        class="remove-row inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100"
                    >
                        Supprimer
                    </button>
                </td>
            `;

            tbody.appendChild(tr);
            bindRowEvents(tr);
            rowIndex++;
            updateQuoteTotals();
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.quote-row').forEach(bindRowEvents);

            document.getElementById('add-row').addEventListener('click', createRow);
            document.getElementById('tva_rate').addEventListener('change', updateQuoteTotals);

            updateQuoteTotals();
        });
    </script>
</x-app-layout>