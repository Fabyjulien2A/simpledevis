@php
    $items = old('items');

    if (!$items && isset($invoice)) {
        $items = $invoice->items->map(function ($item) {
            return [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_ht' => $item->unit_price_ht,
                'tva_rate' => $item->tva_rate,
            ];
        })->toArray();
    }

    if (!$items) {
        $items = [
            [
                'description' => '',
                'quantity' => 1,
                'unit_price_ht' => '',
                'tva_rate' => 20,
            ],
        ];
    }
@endphp

<div class="bg-white rounded-2xl shadow p-6 space-y-6">

    {{-- Client --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Client
        </label>

        <select name="client_id"
                class="w-full rounded-lg border-gray-300">
            <option value="">Sélectionner un client</option>

            @foreach($clients as $client)
                <option value="{{ $client->id }}"
                    @selected(old('client_id', $invoice->client_id ?? '') == $client->id)>
                    {{ $client->company_name ?: $client->full_name }}
                </option>
            @endforeach
        </select>

        @error('client_id')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Dates --}}
    <div class="grid md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Date facture
            </label>

            <input
                type="date"
                name="date"
                value="{{ old('date', isset($invoice) ? $invoice->date->format('Y-m-d') : now()->format('Y-m-d')) }}"
                class="w-full rounded-lg border-gray-300">

            @error('date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Date échéance
            </label>

            <input
                type="date"
                name="due_date"
                value="{{ old('due_date', isset($invoice) && $invoice->due_date ? $invoice->due_date->format('Y-m-d') : now()->addDays(30)->format('Y-m-d')) }}"
                class="w-full rounded-lg border-gray-300">

            @error('due_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Lignes facture --}}
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-lg text-gray-900">
                    Lignes de facturation
                </h3>
                <p class="text-sm text-gray-500">
                    Ajoute une ligne par produit ou prestation.
                </p>
            </div>

            <button
                type="button"
                id="add-invoice-line"
                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                + Ajouter une ligne
            </button>
        </div>

        <div id="invoice-lines" class="space-y-4">
            @foreach($items as $index => $item)
                <div class="invoice-line rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="grid gap-4 md:grid-cols-12">

                        <div class="md:col-span-5">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Description
                            </label>
                            <input
                                type="text"
                                name="items[{{ $index }}][description]"
                                value="{{ $item['description'] ?? '' }}"
                                class="w-full rounded-lg border-gray-300 invoice-description">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Quantité
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="items[{{ $index }}][quantity]"
                                value="{{ $item['quantity'] ?? 1 }}"
                                class="w-full rounded-lg border-gray-300 invoice-quantity">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Prix HT
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="items[{{ $index }}][unit_price_ht]"
                                value="{{ $item['unit_price_ht'] ?? '' }}"
                                class="w-full rounded-lg border-gray-300 invoice-price">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                TVA %
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="items[{{ $index }}][tva_rate]"
                                value="{{ $item['tva_rate'] ?? 20 }}"
                                class="w-full rounded-lg border-gray-300 invoice-tva">
                        </div>

                        <div class="md:col-span-1 flex items-end">
                            <button
                                type="button"
                                class="remove-invoice-line w-full rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-100">
                                ✕
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 text-right text-sm text-gray-500">
                        Total ligne HT :
                        <span class="font-semibold text-gray-900 line-total">0,00 €</span>
                    </div>
                </div>
            @endforeach
        </div>

        @error('items')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Totaux --}}
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5">
        <div class="flex justify-end">
            <div class="w-full max-w-sm space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Total HT</span>
                    <span class="font-semibold text-gray-900" id="total-ht">0,00 €</span>
                </div>

                <div class="flex justify-between">
                    <span class="text-gray-500">Total TVA</span>
                    <span class="font-semibold text-gray-900" id="total-tva">0,00 €</span>
                </div>

                <div class="border-t border-gray-200 pt-2 flex justify-between text-base">
                    <span class="font-semibold text-gray-900">Total TTC</span>
                    <span class="font-bold text-gray-900" id="total-ttc">0,00 €</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Notes --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Notes
        </label>

        <textarea
            name="notes"
            rows="4"
            class="w-full rounded-lg border-gray-300">{{ old('notes', $invoice->notes ?? '') }}</textarea>
    </div>

    {{-- Boutons --}}
    <div class="flex gap-3">
        <button
            type="submit"
            class="bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700">
            Enregistrer la facture
        </button>

        <a href="{{ route('invoices.index') }}"
           class="bg-gray-200 px-6 py-3 rounded-lg">
            Annuler
        </a>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const linesContainer = document.getElementById('invoice-lines');
        const addButton = document.getElementById('add-invoice-line');

        function formatMoney(value) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'EUR'
            }).format(value || 0);
        }

        function refreshIndexes() {
            const lines = linesContainer.querySelectorAll('.invoice-line');

            lines.forEach((line, index) => {
                line.querySelector('.invoice-description').name = `items[${index}][description]`;
                line.querySelector('.invoice-quantity').name = `items[${index}][quantity]`;
                line.querySelector('.invoice-price').name = `items[${index}][unit_price_ht]`;
                line.querySelector('.invoice-tva').name = `items[${index}][tva_rate]`;
            });
        }

        function calculateTotals() {
            let totalHt = 0;
            let totalTva = 0;

            linesContainer.querySelectorAll('.invoice-line').forEach(line => {
                const quantity = parseFloat(line.querySelector('.invoice-quantity').value) || 0;
                const price = parseFloat(line.querySelector('.invoice-price').value) || 0;
                const tvaRate = parseFloat(line.querySelector('.invoice-tva').value) || 0;

                const lineHt = quantity * price;
                const lineTva = lineHt * (tvaRate / 100);

                totalHt += lineHt;
                totalTva += lineTva;

                line.querySelector('.line-total').textContent = formatMoney(lineHt);
            });

            document.getElementById('total-ht').textContent = formatMoney(totalHt);
            document.getElementById('total-tva').textContent = formatMoney(totalTva);
            document.getElementById('total-ttc').textContent = formatMoney(totalHt + totalTva);
        }

        function bindLineEvents(line) {
            line.querySelectorAll('input').forEach(input => {
                input.addEventListener('input', calculateTotals);
            });

            line.querySelector('.remove-invoice-line').addEventListener('click', function () {
                const lines = linesContainer.querySelectorAll('.invoice-line');

                if (lines.length === 1) {
                    return;
                }

                line.remove();
                refreshIndexes();
                calculateTotals();
            });
        }

        addButton.addEventListener('click', function () {
            const firstLine = linesContainer.querySelector('.invoice-line');
            const newLine = firstLine.cloneNode(true);

            newLine.querySelector('.invoice-description').value = '';
            newLine.querySelector('.invoice-quantity').value = 1;
            newLine.querySelector('.invoice-price').value = '';
            newLine.querySelector('.invoice-tva').value = 20;
            newLine.querySelector('.line-total').textContent = '0,00 €';

            linesContainer.appendChild(newLine);

            refreshIndexes();
            bindLineEvents(newLine);
            calculateTotals();
        });

        linesContainer.querySelectorAll('.invoice-line').forEach(bindLineEvents);

        refreshIndexes();
        calculateTotals();
    });
</script>