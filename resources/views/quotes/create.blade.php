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

            {{-- Informations du devis --}}
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

            {{-- Lignes du devis --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900">Lignes du devis</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Renseigne les prestations ou produits à facturer.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-sm font-semibold text-gray-600">
                                <th class="px-6 py-4">Description</th>
                                <th class="px-4 py-4 w-36">Qté</th>
                                <th class="px-4 py-4 w-52">Prix unitaire (€)</th>
                                <th class="px-6 py-4 w-44 text-right">Total HT</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @for($i = 0; $i < 3; $i++)
                                <tr class="quote-row hover:bg-gray-50/70 transition">
                                    <td class="px-6 py-4">
                                        <input
                                            type="text"
                                            name="items[{{ $i }}][description]"
                                            value="{{ old("items.$i.description") }}"
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
                                            value="{{ old("items.$i.quantity", 1) }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 quantity-input"
                                        >
                                    </td>

                                    <td class="px-4 py-4">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="items[{{ $i }}][price]"
                                            value="{{ old("items.$i.price") }}"
                                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 price-input"
                                            placeholder="0.00"
                                        >
                                    </td>

                                    <td class="px-6 py-4 text-right">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700 line-total">
                                            0.00 €
                                        </span>
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                @error('items')
                    <div class="px-6 pb-6">
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    </div>
                @enderror
            </div>

            {{-- Résumé en bas --}}
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

            {{-- Actions --}}
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

        document.addEventListener('DOMContentLoaded', () => {
            const inputs = document.querySelectorAll('.quantity-input, .price-input');
            const tvaSelect = document.getElementById('tva_rate');

            inputs.forEach((input) => {
                input.addEventListener('input', updateQuoteTotals);
            });

            tvaSelect.addEventListener('change', updateQuoteTotals);

            updateQuoteTotals();
        });
    </script>
</x-app-layout>