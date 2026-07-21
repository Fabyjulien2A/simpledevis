<x-app-layout>

    <x-slot name="header">
        <div class="flex items-center justify-between">

            <div>
                <h2 class="text-2xl font-bold text-gray-900">
                    Facture fournisseur
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Facture électronique reçue via SUPER PDP
                </p>
            </div>

            <div class="flex items-center gap-3">

                <a
                    href="{{ route('supplier-invoices.index') }}"
                    class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    ← Retour
                </a>

            </div>

        </div>
    </x-slot>

    <div class="py-8">

        <div class="mx-auto max-w-7xl space-y-8">

            @if(session('success'))
    <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-medium text-red-700">
        {{ session('error') }}
    </div>
@endif
            {{-- Carte fournisseur --}}
<div class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">

    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-8">

        <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">

            <div>

                <h1 class="text-3xl font-bold text-white">
                    {{ $supplierInvoice->supplier_name }}
                </h1>

                <p class="mt-2 text-blue-100">
                    Facture n°
                    <span class="font-semibold">
                        {{ $supplierInvoice->invoice_number }}
                    </span>
                </p>

                <div class="mt-6 flex flex-wrap gap-3">

                    <a
    href="{{ route('supplier-invoices.pdf', $supplierInvoice) }}"
    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
>
    📄 Télécharger le PDF
</a>

    <a
        href="{{ route('supplier-invoices.json', $supplierInvoice) }}"
        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-50"
    >
        Télécharger les données JSON
                </a>

                <a
    href="{{ route('supplier-invoices.xml', $supplierInvoice) }}"
    class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/25"
>
    Télécharger le document original
</a>

    <span
        class="inline-flex cursor-not-allowed items-center gap-2 rounded-xl bg-emerald-500/50 px-4 py-2 text-sm font-semibold text-white"
        title="Fonctionnalité en préparation"
    >
        Factur-X bientôt disponible
    </span>

</div>

            </div>

            <div class="flex flex-wrap items-center gap-2 lg:justify-end">

                <span
                    class="inline-flex rounded-full bg-white/20 px-5 py-2 text-sm font-semibold text-white backdrop-blur"
                >
                    {{ $supplierInvoice->statusLabel() }}
                </span>

                <span class="inline-flex rounded-full bg-emerald-400/20 px-4 py-2 text-sm font-semibold text-emerald-50 ring-1 ring-emerald-200/30">
                    Synchronisée
                </span>

            </div>

        </div>

    </div>

    <div class="grid gap-6 p-8 md:grid-cols-4">

        <div>

            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Date facture
            </p>

            <p class="mt-2 text-lg font-semibold text-gray-900">
                {{ $supplierInvoice->invoice_date?->format('d/m/Y') }}
            </p>

        </div>

        <div>

            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Échéance
            </p>

            <p class="mt-2 text-lg font-semibold text-gray-900">
                {{ $supplierInvoice->due_date?->format('d/m/Y') ?? '-' }}
            </p>

        </div>

        <div>

            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Devise
            </p>

            <p class="mt-2 text-lg font-semibold text-gray-900">
                {{ $supplierInvoice->currency }}
            </p>

        </div>

        <div>

            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Montant TTC
            </p>

            <p class="mt-2 text-2xl font-bold text-blue-600">
                {{ number_format($supplierInvoice->total_ttc, 2, ',', ' ') }} €
            </p>

        </div>

    </div>

</div>


                         {{-- Informations fournisseur --}}
            <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            Informations fournisseur
                        </h3>

                        <p class="mt-1 text-sm text-gray-500">
                            Coordonnées de l’entreprise ayant émis la facture
                        </p>
                    </div>

                    <span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                        Émetteur
                    </span>

                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-5">

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                            Nom
                        </p>

                        <p class="mt-2 font-semibold text-gray-900">
                            {{ $supplierInvoice->supplier_name }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                            SIREN
                        </p>

                        <p class="mt-2 text-gray-700">
                            {{ $supplierInvoice->supplier_siren ?: 'Non renseigné' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                            Numéro de TVA
                        </p>

                        <p class="mt-2 text-gray-700">
                            {{ $supplierInvoice->supplier_vat_number ?: 'Non renseigné' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                            Adresse
                        </p>

                        <p class="mt-2 leading-6 text-gray-700">
                            @if($supplierInvoice->supplier_address)
                                {{ $supplierInvoice->supplier_address }}

                                @if($supplierInvoice->supplier_postal_code || $supplierInvoice->supplier_city)
                                    <br>
                                @endif
                            @endif

                            @if($supplierInvoice->supplier_postal_code || $supplierInvoice->supplier_city)
                                {{ $supplierInvoice->supplier_postal_code }}
                                {{ $supplierInvoice->supplier_city }}
                            @elseif(!$supplierInvoice->supplier_address)
                                Non renseignée
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                            Adresse électronique
                        </p>

                        <p class="mt-2 break-all text-gray-700">
                            {{ $supplierInvoice->supplier_email ?: 'Non renseignée' }}
                        </p>
                    </div>

                </div>

            </div>

            {{-- Lignes de facture --}}
            <div class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">

                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            Lignes de facture
                        </h3>

                        <p class="mt-1 text-sm text-gray-500">
                            Détail des prestations et produits facturés
                        </p>
                    </div>

                    <span class="w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                        {{ $supplierInvoice->items->count() }}
                        {{ $supplierInvoice->items->count() > 1 ? 'lignes' : 'ligne' }}
                    </span>

                </div>

                @if($supplierInvoice->items->isEmpty())

                    <div class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-gray-700">
                            Aucune ligne disponible
                        </p>

                        <p class="mt-1 text-sm text-gray-500">
                            La facture ne contient pas de détail exploitable.
                        </p>
                    </div>

                @else

                    <div class="overflow-x-auto">

                        <table class="w-full min-w-[850px]">

                            <thead class="bg-gray-50">
                                <tr class="border-b border-gray-200">

                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        Description
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        Quantité
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        PU HT
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        TVA
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        Total HT
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        Total TTC
                                    </th>

                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">

                                @foreach($supplierInvoice->items as $item)

                                    <tr class="transition hover:bg-gray-50">

                                        <td class="px-6 py-5">

                                            <p class="font-medium text-gray-900">
                                                {{ $item->description }}
                                            </p>

                                            @if($item->unit_code)
                                                <p class="mt-1 text-xs text-gray-500">
                                                    Unité : {{ $item->unit_code }}
                                                </p>
                                            @endif

                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right text-sm text-gray-700">
                                            {{ number_format($item->quantity, 2, ',', ' ') }}
                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right text-sm text-gray-700">
                                            {{ number_format($item->unit_price_ht, 2, ',', ' ') }} €
                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right">

                                            <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">
                                                {{ number_format($item->vat_rate, 2, ',', ' ') }} %
                                            </span>

                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right text-sm font-semibold text-gray-900">
                                            {{ number_format($item->line_total_ht, 2, ',', ' ') }} €
                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right text-sm font-semibold text-gray-900">
                                            {{ number_format($item->line_total_ttc, 2, ',', ' ') }} €
                                        </td>

                                    </tr>

                                @endforeach

                            </tbody>

                        </table>

                    </div>

                @endif

            </div>


            {{-- Totaux et informations techniques --}}
            <div class="grid gap-8 lg:grid-cols-3">

                {{-- Informations SUPER PDP --}}
                <div class="lg:col-span-2">

                    <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">

                        <div class="flex items-center justify-between">

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">
                                    Informations SUPER PDP
                                </h3>

                                <p class="mt-1 text-sm text-gray-500">
                                    Données techniques liées à la réception électronique
                                </p>
                            </div>

                            <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700">
                                Synchronisée
                            </span>

                        </div>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2">

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Identifiant facture
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $supplierInvoice->superpdp_invoice_id ?: 'Non renseigné' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Identifiant entreprise
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $supplierInvoice->superpdp_company_id ?: 'Non renseigné' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Direction
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $supplierInvoice->direction === 'in' ? 'Entrante' : ucfirst($supplierInvoice->direction ?? 'Non renseignée') }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Type de document
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $supplierInvoice->type_code ?: 'Facture' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Date de réception
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $supplierInvoice->received_at?->format('d/m/Y à H:i') ?? 'Non renseignée' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Reste à payer
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ number_format($supplierInvoice->amount_due ?? $supplierInvoice->total_ttc, 2, ',', ' ') }} €
                                </p>
                            </div>

                        </div>

                    </div>

                </div>

                {{-- Totaux --}}
                <div>

                    <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">

                        <h3 class="text-lg font-semibold text-gray-900">
                            Récapitulatif
                        </h3>

                        <div class="mt-6 space-y-4">

                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-500">
                                    Total HT
                                </span>

                                <span class="font-semibold text-gray-900">
                                    {{ number_format($supplierInvoice->total_ht, 2, ',', ' ') }} €
                                </span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-500">
                                    TVA
                                </span>

                                <span class="font-semibold text-gray-900">
                                    {{ number_format($supplierInvoice->total_vat, 2, ',', ' ') }} €
                                </span>
                            </div>

                            <div class="border-t border-gray-200 pt-4">

                                <div class="flex items-center justify-between">

                                    <span class="text-base font-semibold text-gray-900">
                                        Total TTC
                                    </span>

                                    <span class="text-2xl font-bold text-blue-600">
                                        {{ number_format($supplierInvoice->total_ttc, 2, ',', ' ') }} €
                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>







            {{-- Paiement fournisseur --}}
<div class="mt-6 rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                Paiement fournisseur
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                Enregistrez les règlements effectués pour cette facture.
            </p>
        </div>

        <span
            class="w-fit rounded-full px-3 py-1 text-xs font-semibold {{ $supplierInvoice->paymentStatusColor() }}"
        >
            {{ $supplierInvoice->paymentStatusLabel() }}
        </span>

    </div>

    {{-- Résumé --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-3">

        <div class="rounded-2xl bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Montant TTC
            </p>

            <p class="mt-2 text-lg font-bold text-gray-900">
                {{ number_format($supplierInvoice->total_ttc, 2, ',', ' ') }} €
            </p>
        </div>

        <div class="rounded-2xl bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Montant payé
            </p>

            <p class="mt-2 text-lg font-bold text-green-600">
                {{ number_format($supplierInvoice->paid_amount ?? 0, 2, ',', ' ') }} €
            </p>
        </div>

        <div class="rounded-2xl bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                Reste à payer
            </p>

            <p class="mt-2 text-lg font-bold text-red-600">
                {{ number_format(
                    $supplierInvoice->remaining_amount
                        ?? $supplierInvoice->total_ttc,
                    2,
                    ',',
                    ' '
                ) }} €
            </p>
        </div>

    </div>

    {{-- Erreurs --}}
    @if($errors->any())

        <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4">

            <p class="text-sm font-semibold text-red-700">
                Le paiement n’a pas pu être enregistré.
            </p>

            <ul class="mt-2 space-y-1 text-sm text-red-600">
                @foreach($errors->all() as $error)
                    <li>• {{ $error }}</li>
                @endforeach
            </ul>

        </div>

    @endif

    {{-- Formulaire --}}
    @if($supplierInvoice->payment_status !== 'paid')

        <form
            method="POST"
            action="{{ route('supplier-invoices.payments.store', $supplierInvoice) }}"
            class="mt-6 space-y-5"
        >
            @csrf

            <div class="grid gap-5 md:grid-cols-2">

                <div>
                    <label
                        for="amount"
                        class="mb-2 block text-sm font-medium text-gray-700"
                    >
                        Montant payé
                    </label>

                    <div class="relative">
                        <input
                            type="number"
                            name="amount"
                            id="amount"
                            step="0.01"
                            min="0.01"
                            max="{{ $supplierInvoice->remaining_amount ?? $supplierInvoice->total_ttc }}"
                            value="{{ old(
                                'amount',
                                $supplierInvoice->remaining_amount
                                    ?? $supplierInvoice->total_ttc
                            ) }}"
                            class="w-full rounded-xl border-gray-300 pr-12 focus:border-blue-500 focus:ring-blue-500"
                            required
                        >

                        <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-sm text-gray-500">
                            €
                        </span>
                    </div>
                </div>

                <div>
                    <label
                        for="paid_at"
                        class="mb-2 block text-sm font-medium text-gray-700"
                    >
                        Date du paiement
                    </label>

                    <input
                        type="date"
                        name="paid_at"
                        id="paid_at"
                        value="{{ old('paid_at', now()->format('Y-m-d')) }}"
                        class="w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                        required
                    >
                </div>

                <div>
                    <label
                        for="method"
                        class="mb-2 block text-sm font-medium text-gray-700"
                    >
                        Mode de paiement
                    </label>

                    <select
                        name="method"
                        id="method"
                        class="w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                        required
                    >
                        <option value="">
                            Sélectionner
                        </option>

                        <option value="virement" @selected(old('method') === 'virement')>
                            Virement
                        </option>

                        <option value="carte" @selected(old('method') === 'carte')>
                            Carte bancaire
                        </option>

                        <option value="cheque" @selected(old('method') === 'cheque')>
                            Chèque
                        </option>

                        <option value="especes" @selected(old('method') === 'especes')>
                            Espèces
                        </option>

                        <option value="prelevement" @selected(old('method') === 'prelevement')>
                            Prélèvement
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="reference"
                        class="mb-2 block text-sm font-medium text-gray-700"
                    >
                        Référence
                    </label>

                    <input
                        type="text"
                        name="reference"
                        id="reference"
                        value="{{ old('reference') }}"
                        placeholder="Ex. VIR-2026-001"
                        class="w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                    >
                </div>

            </div>

            <div>
                <label
                    for="notes"
                    class="mb-2 block text-sm font-medium text-gray-700"
                >
                    Notes
                </label>

                <textarea
                    name="notes"
                    id="notes"
                    rows="3"
                    placeholder="Commentaire facultatif"
                    class="w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                >{{ old('notes') }}</textarea>
            </div>

            <div class="flex justify-end">

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-700"
                >
                    Enregistrer le paiement
                </button>

            </div>

        </form>

    @else

        <div class="mt-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4">

            <p class="font-semibold text-green-700">
                Cette facture est entièrement payée.
            </p>

            <p class="mt-1 text-sm text-green-600">
                Aucun autre paiement ne peut être ajouté.
            </p>

        </div>

    @endif

    {{-- Historique des paiements --}}
    <div class="mt-8 border-t border-gray-200 pt-6">

        <div class="flex items-center justify-between">

            <h4 class="font-semibold text-gray-900">
                Historique des paiements
            </h4>

            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                {{ $supplierInvoice->payments->count() }}
                {{ $supplierInvoice->payments->count() > 1 ? 'paiements' : 'paiement' }}
            </span>

        </div>

        @if($supplierInvoice->payments->isEmpty())

            <div class="mt-4 rounded-2xl bg-gray-50 px-5 py-6 text-center">

                <p class="text-sm font-medium text-gray-700">
                    Aucun paiement enregistré
                </p>

                <p class="mt-1 text-sm text-gray-500">
                    Les règlements apparaîtront ici.
                </p>

            </div>

        @else

            <div class="mt-4 divide-y divide-gray-100">

                @foreach($supplierInvoice->payments as $payment)

                    @php
                        $methodLabel = match ($payment->method) {
                            'virement' => 'Virement',
                            'carte' => 'Carte bancaire',
                            'cheque' => 'Chèque',
                            'especes' => 'Espèces',
                            'prelevement' => 'Prélèvement',
                            default => ucfirst($payment->method),
                        };
                    @endphp

                    <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">

                        <div>
                            <p class="font-semibold text-gray-900">
                                {{ number_format($payment->amount, 2, ',', ' ') }} €
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                {{ $methodLabel }}

                                @if($payment->reference)
                                    · Réf. {{ $payment->reference }}
                                @endif
                            </p>

                            @if($payment->notes)
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ $payment->notes }}
                                </p>
                            @endif
                        </div>

                        <span class="text-sm font-medium text-gray-600">
                            {{ $payment->paid_at->format('d/m/Y') }}
                        </span>

                    </div>

                @endforeach

            </div>

        @endif

    </div>

</div>

 

            {{-- Historique SUPER PDP --}}
            <div class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">

                {{-- En-tête --}}
                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            Historique SUPER PDP
                        </h3>

                        <p class="mt-1 text-sm text-gray-500">
                            Historique des traitements effectués par la plateforme
                            de facturation électronique.
                        </p>
                    </div>

                    <span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                        {{ $supplierInvoice->events->count() }}
                        {{ $supplierInvoice->events->count() > 1 ? 'événements' : 'événement' }}
                    </span>

                </div>

                {{-- Contenu --}}
                <div class="p-6 sm:p-8">

                    @if($supplierInvoice->events->isEmpty())

                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">

                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-gray-500">

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-6 w-6"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                    />
                                </svg>

                            </div>

                            <p class="mt-4 text-sm font-semibold text-gray-700">
                                Aucun événement disponible
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                Les événements apparaîtront ici après synchronisation
                                avec SUPER PDP.
                            </p>

                        </div>

                    @else

                        <div class="relative">

                            @foreach($supplierInvoice->events as $event)

                                @php
                                    $eventLabel = match ($event->status) {
                                        'fr:202' => 'Facture reçue',
                                        'fr:203' => 'Facture en traitement',
                                        'fr:204' => 'Facture acceptée',
                                        'fr:205' => 'Facture rejetée',
                                        default => $event->event_type
                                            ?: 'Événement SUPER PDP',
                                    };

                                    $eventDescription = match ($event->status) {
                                        'fr:202' => 'La facture a été reçue par la plateforme.',
                                        'fr:203' => 'La facture est en cours de traitement.',
                                        'fr:204' => 'La facture a été acceptée.',
                                        'fr:205' => 'La facture a été rejetée.',
                                        default => $event->event_type
                                            ?: 'Un nouvel événement a été enregistré.',
                                    };

                                    $eventCircleColor = match ($event->status) {
                                        'fr:202' => 'bg-blue-100 text-blue-700 ring-blue-200',
                                        'fr:203' => 'bg-amber-100 text-amber-700 ring-amber-200',
                                        'fr:204' => 'bg-green-100 text-green-700 ring-green-200',
                                        'fr:205' => 'bg-red-100 text-red-700 ring-red-200',
                                        default => 'bg-gray-100 text-gray-700 ring-gray-200',
                                    };

                                    $eventBadgeColor = match ($event->status) {
                                        'fr:202' => 'bg-blue-50 text-blue-700',
                                        'fr:203' => 'bg-amber-50 text-amber-700',
                                        'fr:204' => 'bg-green-50 text-green-700',
                                        'fr:205' => 'bg-red-50 text-red-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                @endphp

                                <div class="relative flex gap-5">

                                    {{-- Colonne de la timeline --}}
                                    <div class="flex flex-col items-center">

                                        <div class="z-10 flex h-11 w-11 shrink-0 items-center justify-center rounded-full ring-4 {{ $eventCircleColor }}">

                                            @switch($event->status)

                                                @case('fr:202')
                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke-width="1.7"
                                                        stroke="currentColor"
                                                        class="h-5 w-5"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="M3 16.5V21h18v-4.5M12 3v13.5m0 0 4.5-4.5M12 16.5 7.5 12"
                                                        />
                                                    </svg>
                                                    @break

                                                @case('fr:203')
                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke-width="1.7"
                                                        stroke="currentColor"
                                                        class="h-5 w-5"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                                        />
                                                    </svg>
                                                    @break

                                                @case('fr:204')
                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke-width="1.7"
                                                        stroke="currentColor"
                                                        class="h-5 w-5"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="m4.5 12.75 6 6 9-13.5"
                                                        />
                                                    </svg>
                                                    @break

                                                @case('fr:205')
                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke-width="1.7"
                                                        stroke="currentColor"
                                                        class="h-5 w-5"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="M6 18 18 6M6 6l12 12"
                                                        />
                                                    </svg>
                                                    @break

                                                @default
                                                    <span class="text-sm font-bold">
                                                        •
                                                    </span>

                                            @endswitch

                                        </div>

                                        @if(!$loop->last)
                                            <div class="my-2 min-h-16 w-px flex-1 bg-gray-200"></div>
                                        @endif

                                    </div>

                                    {{-- Informations de l’événement --}}
                                    <div class="min-w-0 flex-1 pb-8">

                                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">

                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                                                <div>

                                                    <div class="flex flex-wrap items-center gap-2">

                                                        <h4 class="font-semibold text-gray-900">
                                                            {{ $eventLabel }}
                                                        </h4>

                                                        @if($event->status)
                                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $eventBadgeColor }}">
                                                                {{ $event->status }}
                                                            </span>
                                                        @endif

                                                    </div>

                                                    <p class="mt-2 text-sm leading-6 text-gray-600">
                                                        {{ $eventDescription }}
                                                    </p>

                                                    @if(
                                                        $event->event_type
                                                        && $event->event_type !== $eventLabel
                                                        && $event->event_type !== $eventDescription
                                                    )
                                                        <p class="mt-2 text-xs text-gray-400">
                                                            Message SUPER PDP :
                                                            {{ $event->event_type }}
                                                        </p>
                                                    @endif

                                                </div>

                                                <div class="shrink-0 text-left sm:text-right">

                                                    <p class="text-sm font-medium text-gray-700">
                                                        {{ $event->event_date?->format('d/m/Y') ?? 'Date inconnue' }}
                                                    </p>

                                                    <p class="mt-1 text-xs text-gray-500">
                                                        {{ $event->event_date?->format('H:i') ?? '' }}
                                                    </p>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            @endforeach

                        </div>

                    @endif

                </div>

            </div>



    </div>

</x-app-layout>