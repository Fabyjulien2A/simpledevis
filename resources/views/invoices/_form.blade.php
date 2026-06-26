<div class="bg-white rounded-2xl shadow p-6 space-y-6">

    {{-- Client --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Client
        </label>

        <select name="client_id"
                class="w-full rounded-lg border-gray-300">

            <option value="">
                Sélectionner un client
            </option>

            @foreach($clients as $client)
                <option
                    value="{{ $client->id }}"
                    @selected(old('client_id', $invoice->client_id ?? '') == $client->id)
                >
                    {{ $client->company_name ?: $client->full_name }}
                </option>
            @endforeach

        </select>
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
        </div>

    </div>

    {{-- Ligne facture --}}
    <div>

        <h3 class="font-semibold text-lg mb-4">
            Ligne de facturation
        </h3>

        <div class="grid md:grid-cols-4 gap-4">

            <div>
                <label>Description</label>

                <input
                    type="text"
                    name="items[0][description]"
                    value="{{ old('items.0.description', $invoice->items[0]->description ?? '') }}"
                    class="w-full rounded-lg border-gray-300">
            </div>

            <div>
                <label>Quantité</label>

                <input
                    type="number"
                    step="0.01"
                    name="items[0][quantity]"
                    value="{{ old('items.0.quantity', $invoice->items[0]->quantity ?? 1) }}"
                    class="w-full rounded-lg border-gray-300">
            </div>

            <div>
                <label>Prix HT</label>

                <input
                    type="number"
                    step="0.01"
                    name="items[0][unit_price_ht]"
                    value="{{ old('items.0.unit_price_ht', $invoice->items[0]->unit_price_ht ?? '') }}"
                    class="w-full rounded-lg border-gray-300">
            </div>

            <div>
                <label>TVA %</label>

                <input
                    type="number"
                    step="0.01"
                    name="items[0][tva_rate]"
                    value="{{ old('items.0.tva_rate', $invoice->items[0]->tva_rate ?? 20) }}"
                    class="w-full rounded-lg border-gray-300">
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