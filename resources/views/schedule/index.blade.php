<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <h2 class="text-2xl font-bold text-gray-900">
                    Échéancier
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Encaissements clients et paiements fournisseurs à venir.
                </p>
            </div>

            <span class="w-fit rounded-full bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700">
                {{ $scheduleItems->count() }}
                {{ $scheduleItems->count() > 1 ? 'échéances' : 'échéance' }}
            </span>

        </div>
    </x-slot>

    <div class="py-8">

        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">

            {{-- Résumé --}}
            <section class="grid gap-4 md:grid-cols-3">

                <div class="rounded-3xl border border-green-100 bg-green-50 p-6 shadow-sm">

                    <p class="text-sm font-medium text-green-700">
                        À encaisser
                    </p>

                    <p class="mt-3 text-3xl font-bold text-green-800">
                        {{ number_format($incomingAmount, 2, ',', ' ') }} €
                    </p>

                    <p class="mt-2 text-sm text-green-600">
                        Factures clients en attente
                    </p>

                </div>

                <div class="rounded-3xl border border-red-100 bg-red-50 p-6 shadow-sm">

                    <p class="text-sm font-medium text-red-700">
                        À payer
                    </p>

                    <p class="mt-3 text-3xl font-bold text-red-800">
                        {{ number_format($outgoingAmount, 2, ',', ' ') }} €
                    </p>

                    <p class="mt-2 text-sm text-red-600">
                        Factures fournisseurs en attente
                    </p>

                </div>

                <div class="rounded-3xl border border-blue-100 bg-blue-50 p-6 shadow-sm">

                    <p class="text-sm font-medium text-blue-700">
                        Solde prévisionnel
                    </p>

                    <p class="mt-3 text-3xl font-bold {{ $forecastBalance >= 0 ? 'text-blue-800' : 'text-red-700' }}">
                        {{ $forecastBalance >= 0 ? '+' : '' }}
                        {{ number_format($forecastBalance, 2, ',', ' ') }} €
                    </p>

                    <p class="mt-2 text-sm text-blue-600">
                        Encaissements moins décaissements
                    </p>

                </div>

            </section>

            @php
                $groups = [
                    [
                        'title' => 'En retard',
                        'description' => 'Échéances déjà dépassées',
                        'items' => $overdueItems,
                        'badge' => 'bg-red-100 text-red-700',
                    ],
                    [
                        'title' => 'Aujourd’hui',
                        'description' => 'Échéances du jour',
                        'items' => $todayItems,
                        'badge' => 'bg-blue-100 text-blue-700',
                    ],
                    [
                        'title' => 'Dans les 7 prochains jours',
                        'description' => 'Échéances à surveiller prochainement',
                        'items' => $nextSevenDaysItems,
                        'badge' => 'bg-amber-100 text-amber-700',
                    ],
                    [
                        'title' => 'Plus tard',
                        'description' => 'Échéances prévues au-delà de 7 jours',
                        'items' => $laterItems,
                        'badge' => 'bg-gray-100 text-gray-700',
                    ],
                ];
            @endphp

            @foreach($groups as $group)

                <section class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">

                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-5">

                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ $group['title'] }}
                            </h3>

                            <p class="mt-1 text-sm text-gray-500">
                                {{ $group['description'] }}
                            </p>
                        </div>

                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $group['badge'] }}">
                            {{ $group['items']->count() }}
                        </span>

                    </div>

                    @if($group['items']->isEmpty())

                        <div class="px-6 py-10 text-center text-sm text-gray-500">
                            Aucune échéance dans cette catégorie.
                        </div>

                    @else

                        <div class="divide-y divide-gray-100">

                            @foreach($group['items'] as $item)

                                <a
                                    href="{{ $item['url'] }}"
                                    class="flex flex-col gap-4 px-6 py-5 transition hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between"
                                >

                                    <div class="min-w-0">

                                        <div class="flex flex-wrap items-center gap-2">

                                            <span class="font-semibold text-gray-900">
                                                {{ $item['title'] }}
                                            </span>

                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $item['direction'] === 'incoming'
                                                    ? 'bg-green-50 text-green-700'
                                                    : 'bg-red-50 text-red-700' }}"
                                            >
                                                {{ $item['direction'] === 'incoming'
                                                    ? 'À encaisser'
                                                    : 'À payer' }}
                                            </span>

                                        </div>

                                        <p class="mt-1 text-sm text-gray-500">
                                            {{ $item['number'] }}
                                            ·
                                            {{ $item['status_label'] }}
                                        </p>

                                    </div>

                                    <div class="flex shrink-0 items-center gap-6">

                                        <div class="text-right">

                                            <p class="text-sm text-gray-500">
                                                Échéance
                                            </p>

                                            <p class="mt-1 font-medium text-gray-900">
                                                {{ $item['due_date']->format('d/m/Y') }}
                                            </p>

                                        </div>

                                        <div class="min-w-32 text-right">

                                            <p class="text-sm text-gray-500">
                                                Reste
                                            </p>

                                            <p class="mt-1 text-lg font-bold {{ $item['direction'] === 'incoming'
                                                ? 'text-green-700'
                                                : 'text-red-700' }}"
                                            >
                                                {{ number_format(
                                                    $item['amount'],
                                                    2,
                                                    ',',
                                                    ' '
                                                ) }} €
                                            </p>

                                        </div>

                                    </div>

                                </a>

                            @endforeach

                        </div>

                    @endif

                </section>

            @endforeach

        </div>

    </div>

</x-app-layout>