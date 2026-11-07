@php
    $isCreation = !isset($invoice);
    $usesDecimalFields = $isCreation || $invoice->calculation_version === 'decimal-v1';
    $items = old('items');

    if (!$items && isset($invoice)) {
        $items = $invoice->items->map(function ($item) use ($usesDecimalFields) {
            $line = [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_ht' => $item->unit_price_ht,
                'tva_rate' => $item->tva_rate,
            ];
            if ($usesDecimalFields) {
                $line += [
                    'unit_code' => $item->unit_code,
                    'exemption_reason_code' => $item->exemption_reason_code,
                    'exemption_reason' => $item->exemption_reason,
                    'tax_treatment' => match ($item->vat_category) {
                        'S' => 'taxable',
                        'E' => $item->exemption_reason_code === 'VATEX-FR-FRANCHISE' ? 'franchise' : 'exempt',
                        'Z' => 'zero_rated',
                        default => '',
                    },
                ];
            }

            return $line;
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

    @if($errors->any())
        <div class="rounded-lg bg-red-50 p-4 text-red-700" role="alert">
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
                @php
                    // Normalisation décimale textuelle, sans conversion en float.
                    $selectedRate = (string)($item['tva_rate'] ?? '');
                    if (str_contains($selectedRate, '.')) {
                        $selectedRate = rtrim(rtrim($selectedRate, '0'), '.');
                    }
                @endphp
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
                            @if($usesDecimalFields)
                                <select name="items[{{ $index }}][tva_rate]" class="w-full rounded-lg border-gray-300 invoice-tva">
                                    <option value="">Choisir un taux</option>
                                    @foreach(['20' => '20 %', '10' => '10 %', '5.5' => '5,5 %', '2.1' => '2,1 %', '0' => '0 % (hors TVA applicable)'] as $rate => $label)
                                        <option value="{{ $rate }}" @selected($selectedRate === (string)$rate)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="items[{{ $index }}][tva_rate]"
                                value="{{ $item['tva_rate'] ?? 20 }}"
                                class="w-full rounded-lg border-gray-300 invoice-tva">
                            @endif
                        </div>

                        <div class="md:col-span-1 flex items-end">
                            <button
                                type="button"
                                class="remove-invoice-line w-full rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-100">
                                ✕
                            </button>
                        </div>
                    </div>

                    @if($usesDecimalFields)
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Unité de facturation</label>
                                <select name="items[{{ $index }}][unit_code]" class="invoice-unit w-full rounded-lg border-gray-300" required>
                                    <option value="">Choisir une unité</option>
                                    @foreach(['C62' => 'Unité', 'HUR' => 'Heure', 'MTR' => 'Mètre'] as $code => $label)
                                        <option value="{{ $code }}" @selected(($item['unit_code'] ?? '') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Traitement fiscal</label>
                                <select name="items[{{ $index }}][tax_treatment]" class="invoice-treatment w-full rounded-lg border-gray-300" required>
                                    <option value="">Choisir un traitement</option>
                                    @foreach(['taxable' => 'TVA applicable', 'franchise' => 'Franchise en base', 'exempt' => 'Autre exonération', 'zero_rated' => 'Taux zéro'] as $code => $label)
                                        <option value="{{ $code }}" @selected(($item['tax_treatment'] ?? '') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="invoice-exemption-fields md:col-span-2 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Code du motif d’exonération</label>
                                    <input type="text" name="items[{{ $index }}][exemption_reason_code]" value="{{ $item['exemption_reason_code'] ?? '' }}" maxlength="255" class="invoice-exemption-code w-full rounded-lg border-gray-300">
                                    <p class="mt-1 text-xs text-gray-500">La franchise utilise le code VATEX-FR-FRANCHISE.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Justification de l’exonération</label>
                                    <textarea name="items[{{ $index }}][exemption_reason]" class="invoice-exemption-reason w-full rounded-lg border-gray-300" rows="2">{{ $item['exemption_reason'] ?? '' }}</textarea>
                                    <p class="mt-1 text-xs text-gray-500">Pour une autre exonération, renseignez au moins un code ou une justification.</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    @foreach(['description', 'quantity', 'unit_price_ht', 'tva_rate', 'unit_code', 'tax_treatment', 'vat_category', 'exemption_reason_code', 'exemption_reason', 'line_total_ht'] as $field)
                        @error("items.$index.$field")
                            <p class="invoice-field-error mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    @endforeach

                    <div class="mt-3 text-right text-sm text-gray-500">
                        {{ $usesDecimalFields ? 'Estimation HT de la ligne :' : 'Total ligne HT :' }}
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
        @if($usesDecimalFields)
            <p class="mb-4 text-sm text-amber-900" role="status">
                Aperçu indicatif : les montants exacts sont calculés par le serveur à l’enregistrement.
                Des écarts d’arrondi sont possibles, notamment avec plusieurs taux de TVA ou de petits montants.
            </p>
        @endif
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
<div class="flex flex-wrap gap-3">

    @if(!isset($invoice))
        <button
            type="submit"
            name="action"
            value="draft"
            class="bg-gray-800 text-white px-6 py-3 rounded-lg hover:bg-gray-900">
            Enregistrer comme brouillon
        </button>

        <button
            type="submit"
            name="action"
            value="issue"
            class="bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700"
            onclick="return confirm('Valider définitivement cette facture ? Elle ne pourra plus être modifiée.')">
            Valider définitivement
        </button>
    @else
        <button
            type="submit"
            class="bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700">
            Enregistrer les modifications
        </button>
    @endif

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
        const usesDecimalFields = @json($usesDecimalFields);

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
                if (usesDecimalFields) {
                    for (const [selector, field] of [
                        ['.invoice-unit', 'unit_code'], ['.invoice-treatment', 'tax_treatment'],
                        ['.invoice-exemption-code', 'exemption_reason_code'], ['.invoice-exemption-reason', 'exemption_reason']
                    ]) {
                        line.querySelector(selector).name = `items[${index}][${field}]`;
                    }
                }
            });
        }

        function calculateTotals() {
            let totalHt = 0;
            let totalTva = 0;
            let complete = true;

            linesContainer.querySelectorAll('.invoice-line').forEach(line => {
                const quantity = parseFloat(line.querySelector('.invoice-quantity').value) || 0;
                const price = parseFloat(line.querySelector('.invoice-price').value) || 0;
                const tvaRate = parseFloat(line.querySelector('.invoice-tva').value) || 0;
                if (usesDecimalFields && (!line.querySelector('.invoice-treatment').value
                    || !line.querySelector('.invoice-unit').value
                    || !line.querySelector('.invoice-quantity').value
                    || !line.querySelector('.invoice-price').value
                    || line.querySelector('.invoice-tva').value === '')) {
                    complete = false;
                }

                const lineHt = quantity * price;
                const lineTva = lineHt * (tvaRate / 100);

                totalHt += lineHt;
                totalTva += lineTva;

                line.querySelector('.line-total').textContent = formatMoney(lineHt);
            });

            document.getElementById('total-ht').textContent = complete ? formatMoney(totalHt) : 'À compléter';
            document.getElementById('total-tva').textContent = complete ? formatMoney(totalTva) : 'À compléter';
            document.getElementById('total-ttc').textContent = complete ? formatMoney(totalHt + totalTva) : 'À compléter';
        }

        function updateTreatment(line, changed = false) {
            if (!usesDecimalFields) return;
            const treatment = line.querySelector('.invoice-treatment').value;
            const exempt = treatment === 'franchise' || treatment === 'exempt';
            line.querySelector('.invoice-exemption-fields').hidden = !exempt;
            const code = line.querySelector('.invoice-exemption-code');
            code.readOnly = treatment === 'franchise';
            if (changed) {
                line.querySelector('.invoice-exemption-reason').value = '';
                code.value = treatment === 'franchise' ? 'VATEX-FR-FRANCHISE' : '';
                line.querySelector('.invoice-tva').value = treatment && treatment !== 'taxable' ? '0' : '';
            }
        }

        function bindLineEvents(line) {
            line.querySelectorAll('input, select, textarea').forEach(input => {
                input.addEventListener('input', calculateTotals);
            });
            if (usesDecimalFields) {
                line.querySelector('.invoice-treatment').addEventListener('change', function () {
                    updateTreatment(line, true);
                    calculateTotals();
                });
                updateTreatment(line);
            }

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
            newLine.querySelector('.invoice-tva').value = usesDecimalFields ? '' : 20;
            newLine.querySelectorAll('.invoice-field-error').forEach(error => error.remove());
            if (usesDecimalFields) {
                newLine.querySelector('.invoice-unit').value = '';
                newLine.querySelector('.invoice-treatment').value = '';
                newLine.querySelector('.invoice-exemption-code').value = '';
                newLine.querySelector('.invoice-exemption-reason').value = '';
            }
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
