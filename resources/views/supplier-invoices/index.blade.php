<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <h2 class="text-2xl font-semibold text-gray-900">
                    Factures reçues
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Consultez et suivez les factures électroniques reçues de vos fournisseurs.
                </p>
            </div>

            <div class="w-fit rounded-full bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700">
                {{ $invoices->total() }}
                {{ $invoices->total() > 1 ? 'factures' : 'facture' }}
            </div>

        </div>
    </x-slot>

    <div class="py-8">

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            {{-- Recherche et filtres --}}
            <div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">

                <form
                    method="GET"
                    action="{{ route('supplier-invoices.index') }}"
                    class="flex flex-col gap-4 lg:flex-row lg:items-end"
                >

                    {{-- Recherche --}}
                    <div class="flex-1">

                        <label
                            for="search"
                            class="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Rechercher
                        </label>

                        <div class="relative">

                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5 text-gray-400"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"
                                    />
                                </svg>

                            </div>

                            <input
                                type="search"
                                name="search"
                                id="search"
                                value="{{ request('search') }}"
                                placeholder="Fournisseur ou numéro de facture..."
                                class="w-full rounded-xl border-gray-300 pl-11 focus:border-blue-500 focus:ring-blue-500"
                            >

                        </div>

                    </div>

                    {{-- Filtre paiement --}}
                    <div class="w-full lg:w-64">

                        <label
                            for="payment_status"
                            class="mb-2 block text-sm font-medium text-gray-700"
                        >
                            État du paiement
                        </label>

                        <select
                            name="payment_status"
                            id="payment_status"
                            class="w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">
                                Tous les paiements
                            </option>

                            <option
                                value="unpaid"
                                @selected(request('payment_status') === 'unpaid')
                            >
                                À payer
                            </option>

                            <option
                                value="partial"
                                @selected(request('payment_status') === 'partial')
                            >
                                Partiellement payées
                            </option>

                            <option
                                value="paid"
                                @selected(request('payment_status') === 'paid')
                            >
                                Payées
                            </option>
                        </select>

                    </div>

                    {{-- Boutons --}}
                    <div class="flex gap-3">

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-700"
                        >
                            Filtrer
                        </button>

                        @if(request()->filled('search') || request()->filled('payment_status'))

                            <a
                                href="{{ route('supplier-invoices.index') }}"
                                class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                            >
                                Réinitialiser
                            </a>

                        @endif

                    </div>

                </form>

            </div>

            {{-- Liste --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">

                @if($invoices->isEmpty())

                    <div class="px-6 py-16 text-center">

                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                                class="h-7 w-7 text-gray-500"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5V6.75A3.375 3.375 0 0 0 11.25 3.375H6.375A1.875 1.875 0 0 0 4.5 5.25v13.5a1.875 1.875 0 0 0 1.875 1.875h11.25A1.875 1.875 0 0 0 19.5 18.75v-4.5Z"
                                />
                            </svg>

                        </div>

                        <h3 class="mt-4 text-lg font-semibold text-gray-900">
                            Aucune facture trouvée
                        </h3>

                        <p class="mt-2 text-sm text-gray-500">
                            @if(request()->filled('search') || request()->filled('payment_status'))
                                Aucune facture ne correspond à vos critères.
                            @else
                                Les factures reçues via SUPER PDP apparaîtront ici.
                            @endif
                        </p>

                        @if(request()->filled('search') || request()->filled('payment_status'))

                            <a
                                href="{{ route('supplier-invoices.index') }}"
                                class="mt-5 inline-flex items-center rounded-xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-700"
                            >
                                Afficher toutes les factures
                            </a>

                        @endif

                    </div>

                @else

                    <table class="w-full table-fixed">

                        <thead class="border-b border-gray-200 bg-gray-50">

                            <tr>

                                <th class="w-[28%] px-5 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Fournisseur
                                </th>

                                <th class="w-[14%] px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Échéance
                                </th>

                                <th class="w-[16%] px-4 py-4 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Total TTC
                                </th>

                                <th class="w-[17%] px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Paiement
                                </th>

                                <th class="w-[15%] px-4 py-4 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Reste
                                </th>

                                <th class="w-[10%] px-5 py-4 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody class="divide-y divide-gray-100 bg-white">

                            @foreach($invoices as $invoice)

                                @php
                                    $remainingAmount = (float) (
                                        $invoice->remaining_amount
                                        ?? $invoice->total_ttc
                                    );

                                    $isOverdue =
                                        $invoice->due_date
                                        && $invoice->due_date->isPast()
                                        && $invoice->payment_status !== 'paid';

                                    $isDueSoon =
                                        $invoice->due_date
                                        && !$invoice->due_date->isPast()
                                        && now()->diffInDays(
                                            $invoice->due_date,
                                            false
                                        ) <= 7
                                        && $invoice->payment_status !== 'paid';
                                @endphp

                                <tr class="transition hover:bg-gray-50">

                                    {{-- Fournisseur + numéro --}}
                                    <td class="px-5 py-5 align-middle">

                                        <div class="truncate font-semibold text-gray-900">
                                            {{ $invoice->supplier_name }}
                                        </div>

                                        <div
                                            class="mt-1 truncate text-xs text-gray-500"
                                            title="{{ $invoice->invoice_number }}"
                                        >
                                            {{ $invoice->invoice_number }}
                                        </div>

                                    </td>

                                    {{-- Échéance --}}
                                    <td class="px-4 py-5 align-middle">

                                        <div class="whitespace-nowrap text-sm font-medium text-gray-700">
                                            {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                                        </div>

                                        @if($isOverdue)

                                            <span class="mt-1 inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">
                                                Échue
                                            </span>

                                        @elseif($isDueSoon)

                                            <span class="mt-1 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                                Bientôt
                                            </span>

                                        @endif

                                    </td>

                                    {{-- Total TTC --}}
                                    <td class="whitespace-nowrap px-4 py-5 text-right align-middle">

                                        <div class="font-semibold text-gray-900">
                                            {{ number_format(
                                                $invoice->total_ttc,
                                                2,
                                                ',',
                                                ' '
                                            ) }} €
                                        </div>

                                    </td>

                                    {{-- Statut paiement --}}
                                    <td class="px-4 py-5 text-center align-middle">

                                        <span
                                            class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold {{ $invoice->paymentStatusColor() }}"
                                        >
                                            @if($invoice->payment_status === 'partial')
                                                Partielle
                                            @else
                                                {{ $invoice->paymentStatusLabel() }}
                                            @endif
                                        </span>

                                    </td>

                                    {{-- Reste à payer --}}
                                    <td class="whitespace-nowrap px-4 py-5 text-right align-middle">

                                        <div class="font-semibold {{ $remainingAmount > 0 ? 'text-gray-900' : 'text-green-600' }}">
                                            {{ number_format(
                                                $remainingAmount,
                                                2,
                                                ',',
                                                ' '
                                            ) }} €
                                        </div>

                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-5 py-5 text-right align-middle">

                                        <a
                                            href="{{ route('supplier-invoices.show', $invoice) }}"
                                            class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-gray-700"
                                        >
                                            Voir
                                        </a>

                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                    {{-- Pagination --}}
                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $invoices->withQueryString()->links() }}
                    </div>

                @endif

            </div>

        </div>

    </div>

</x-app-layout>