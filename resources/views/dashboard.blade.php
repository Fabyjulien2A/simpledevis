<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold leading-tight text-slate-900">
                Tableau de bord
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Vue d’ensemble de ton activité
            </p>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
    @endphp

    <div class="min-h-screen bg-gradient-to-b from-slate-50 via-white to-slate-100 py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">

        <section class="relative overflow-hidden rounded-3xl bg-slate-900 p-6 text-white shadow-2xl md:p-8">
    <div class="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800"></div>
    <div class="absolute top-0 right-0 h-40 w-40 rounded-full bg-blue-500/20 blur-3xl"></div>
    <div class="absolute bottom-0 left-0 h-40 w-40 rounded-full bg-violet-500/20 blur-3xl"></div>

    <div class="relative flex flex-col gap-8 xl:flex-row xl:items-center xl:justify-between">
        <div class="max-w-3xl">
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white/90">
                    Bienvenue
                </span>

                @if($user->isSubscribed())
                    <span class="inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-400/20 px-3 py-1 text-xs font-semibold text-emerald-100">
                        {{ $user->planLabel() }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-amber-300/30 bg-amber-400/20 px-3 py-1 text-xs font-semibold text-amber-100">
                        {{ $user->planLabel() }}
                    </span>
                @endif
            </div>

            <h1 class="mt-4 text-3xl font-bold leading-tight text-white md:text-5xl">
                Heureux de te revoir 👋
            </h1>

            <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-200 md:text-base">
                Suis tes devis, tes factures, tes encaissements et l’évolution de ton activité depuis un espace moderne, clair et professionnel.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a
                    href="{{ route('quotes.create') }}"
                    class="inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg transition hover:bg-slate-100"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nouveau devis
                </a>

                <a
                    href="{{ route('clients.create') }}"
                    class="inline-flex items-center gap-2 rounded-2xl border border-white/20 bg-white/10 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/20"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.742-.479 3 3 0 0 0-4.682-2.72m.94 3.198v.001c-.58.13-1.18.2-1.8.2a8.966 8.966 0 0 1-5.4-1.8m7.2 1.6a9.094 9.094 0 0 1-3.742.479m0 0a3 3 0 0 1-5.316-1.92m5.316 1.92a8.966 8.966 0 0 1-5.4-1.8m0 0a8.966 8.966 0 0 1-2.4-2.88m2.4 2.88a3 3 0 0 1-4.682-2.72 9.094 9.094 0 0 1 3.742-.479m.94 3.199A8.966 8.966 0 0 1 3 12c0-.945.146-1.856.416-2.712m0 0A3 3 0 0 1 8.1 6.567m-4.684 2.72A9.094 9.094 0 0 1 7.158 8.8m.942-2.233A8.967 8.967 0 0 1 12 6c1.95 0 3.754.622 5.226 1.677m-9.126-1.11A3 3 0 0 1 12 3a3 3 0 0 1 3.9 3.567" />
                    </svg>
                    Nouveau client
                </a>

                @unless($user->isSubscribed())
                    <a
                        href="{{ route('pricing') }}"
                        class="inline-flex items-center gap-2 rounded-2xl border border-amber-300/30 bg-amber-400/20 px-5 py-3 text-sm font-semibold text-amber-100 transition hover:bg-amber-400/30"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9a2.25 2.25 0 0 1-2.25-2.25v-9A2.25 2.25 0 0 1 7.5 5.25h5.379a2.25 2.25 0 0 1 1.591.659l3.621 3.621a2.25 2.25 0 0 1 .659 1.591V16.5a2.25 2.25 0 0 1-2.25 2.25Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3h-6" />
                        </svg>
                        Passer à l’offre Pro
                    </a>
                @endunless
            </div>
            @unless($user->isSubscribed())
    <div class="mt-4 flex flex-wrap gap-3">
        <div class="inline-flex rounded-2xl border border-white/10 bg-white/10 px-4 py-3 text-sm text-slate-100">
            Il te reste <span class="mx-1 font-bold text-white">{{ $user->remainingFreeClients() }}</span> clients
        </div>

        <div class="inline-flex rounded-2xl border border-white/10 bg-white/10 px-4 py-3 text-sm text-slate-100">
            Il te reste <span class="mx-1 font-bold text-white">{{ $user->remainingFreeQuotes() }}</span> devis
        </div>

        <div class="inline-flex rounded-2xl border border-white/10 bg-white/10 px-4 py-3 text-sm text-slate-100">
            Il te reste <span class="mx-1 font-bold text-white">{{ $user->remainingFreeInvoices() }}</span> factures
        </div>
    </div>
@endunless
        </div>

        <div class="grid grid-cols-2 gap-4 xl:min-w-[320px]">
            <div class="rounded-2xl border border-white/10 bg-white/10 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-300">Clients</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $clientsCount }}</p>
                <p class="mt-1 text-sm text-slate-300">Base client active</p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/10 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-300">Devis</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $quotesCount }}</p>
                <p class="mt-1 text-sm text-slate-300">Documents créés</p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/10 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-300">Factures</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $invoicesCount }}</p>
                <p class="mt-1 text-sm text-slate-300">Facturation émise</p>
            </div>

            <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-200">CA du mois</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ number_format($monthlyRevenue, 2, ',', ' ') }} €</p>
                <p class="mt-1 text-sm text-emerald-100">Mois en cours</p>
            </div>
        </div>
    </div>
</section>

            <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="group rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50 backdrop-blur transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Clients</p>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">
                                {{ $clientsCount }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-3 text-slate-500 transition group-hover:bg-slate-900 group-hover:text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.742-.479 3 3 0 0 0-4.682-2.72m.94 3.198v.001c-.58.13-1.18.2-1.8.2a8.966 8.966 0 0 1-5.4-1.8m7.2 1.6a9.094 9.094 0 0 1-3.742.479m0 0a3 3 0 0 1-5.316-1.92m5.316 1.92a8.966 8.966 0 0 1-5.4-1.8m0 0a8.966 8.966 0 0 1-2.4-2.88m2.4 2.88a3 3 0 0 1-4.682-2.72 9.094 9.094 0 0 1 3.742-.479m.94 3.199A8.966 8.966 0 0 1 3 12c0-.945.146-1.856.416-2.712m0 0A3 3 0 0 1 8.1 6.567m-4.684 2.72A9.094 9.094 0 0 1 7.158 8.8m.942-2.233A8.967 8.967 0 0 1 12 6c1.95 0 3.754.622 5.226 1.677m-9.126-1.11A3 3 0 0 1 12 3a3 3 0 0 1 3.9 3.567" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Clients enregistrés
                    </p>
                    @unless($user->isSubscribed())
    <p class="mt-2 text-xs font-medium text-orange-600">
        Limite gratuite : {{ $user->remainingFreeClients() }} restant(s)
    </p>
@endunless
                </div>

                <div class="group rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50 backdrop-blur transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Devis</p>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">
                                {{ $quotesCount }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-3 text-slate-500 transition group-hover:bg-slate-900 group-hover:text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375H14.25m-4.5 0H7.875A3.375 3.375 0 0 0 4.5 11.625v6A3.375 3.375 0 0 0 7.875 21h8.25a3.375 3.375 0 0 0 3.375-3.375V14.25m-9-6.75V3m0 4.5 1.5-1.5m-1.5 1.5-1.5-1.5" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Devis créés
                    </p>

@unless($user->isSubscribed())
    <p class="mt-2 text-xs font-medium text-orange-600">
        Limite gratuite : {{ $user->remainingFreeQuotes() }} restant(s)
    </p>
@endunless

                </div>

                <div class="group rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50 backdrop-blur transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Factures</p>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">
                                {{ $invoicesCount }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-3 text-slate-500 transition group-hover:bg-slate-900 group-hover:text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v3.375A2.625 2.625 0 0 1 16.875 20.25H7.125A2.625 2.625 0 0 1 4.5 17.625V6.375A2.625 2.625 0 0 1 7.125 3.75h6.19a2.625 2.625 0 0 1 1.857.769l3.56 3.56a2.625 2.625 0 0 1 .768 1.856V14.25Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 12h7.5m-7.5 3h4.5" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Factures émises
                    </p>
                    @unless($user->isSubscribed())
    <p class="mt-2 text-xs font-medium text-orange-600">
        Limite gratuite : {{ $user->remainingFreeInvoices() }} restant(s)
    </p>
@endunless
                </div>

                <div class="group rounded-3xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-sm shadow-emerald-100/50 transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-emerald-700">CA du mois</p>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-slate-900">
                                {{ number_format($monthlyRevenue, 2, ',', ' ') }} €
                            </p>
                        </div>
                        <div class="rounded-2xl bg-emerald-100 p-3 text-emerald-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m0 0 3-3m-3 3-3-3M3.75 9h16.5" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">
                        Factures du mois en cours
                    </p>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-3xl border border-emerald-100 bg-white p-6 shadow-sm shadow-slate-200/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Montant encaissé</p>
                            <p class="mt-3 text-3xl font-bold text-emerald-700">
                                {{ number_format($amountCollected, 2, ',', ' ') }} €
                            </p>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-3 text-emerald-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.5 4.5L21.75 7.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 7.5h5.25v5.25" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Paiements réellement enregistrés
                    </p>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Reste à encaisser</p>
                            <p class="mt-3 text-3xl font-bold text-slate-900">
                                {{ number_format($amountToCollect, 2, ',', ' ') }} €
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-3 text-slate-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2.25" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Montant restant sur les factures
                    </p>
                </div>

                <div class="rounded-3xl border border-rose-100 bg-gradient-to-br from-rose-50 to-white p-6 shadow-sm shadow-rose-100/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-rose-600">Factures en retard</p>
                            <p class="mt-3 text-3xl font-bold text-rose-700">
                                {{ $overdueInvoicesCount }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-rose-100 p-3 text-rose-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.95 3.374H4.647c-1.733 0-2.816-1.874-1.95-3.374L10.05 3.374c.866-1.5 3.034-1.5 3.9 0l7.353 12.752ZM12 16.5h.008v.008H12V16.5Z" />
                            </svg>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-rose-500">
                        Échéance dépassée
                    </p>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50 xl:col-span-2">
                    <div class="mb-6 flex items-end justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-semibold text-slate-900">
                                Actions rapides
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Accède rapidement aux sections principales.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <a
                            href="{{ route('clients.index') }}"
                            class="group rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm text-slate-500">Gestion</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-900">
                                        Voir les clients
                                    </p>
                                </div>
                                <span class="rounded-xl bg-slate-100 p-2 text-slate-600 transition group-hover:bg-slate-900 group-hover:text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Consulter et gérer ta base clients
                            </p>
                        </a>

                        <a
                            href="{{ route('quotes.index') }}"
                            class="group rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm text-slate-500">Devis</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-900">
                                        Voir les devis
                                    </p>
                                </div>
                                <span class="rounded-xl bg-slate-100 p-2 text-slate-600 transition group-hover:bg-slate-900 group-hover:text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Accéder à tous tes devis
                            </p>
                        </a>

                        <a
                            href="{{ route('invoices.index') }}"
                            class="group rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm text-slate-500">Facturation</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-900">
                                        Voir les factures
                                    </p>
                                </div>
                                <span class="rounded-xl bg-slate-100 p-2 text-slate-600 transition group-hover:bg-slate-900 group-hover:text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Suivre les paiements et les factures
                            </p>
                        </a>

                        <a
                            href="{{ route('company.edit') }}"
                            class="group rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm text-slate-500">Entreprise</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-900">
                                        Modifier mes infos
                                    </p>
                                </div>
                                <span class="rounded-xl bg-slate-100 p-2 text-slate-600 transition group-hover:bg-slate-900 group-hover:text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Logo, coordonnées et informations légales
                            </p>
                        </a>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50">
                    <h3 class="text-xl font-semibold text-slate-900">
                        Vue rapide
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Les points à surveiller aujourd’hui.
                    </p>

                    <div class="mt-6 space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm font-medium text-slate-500">
                                        Factures à suivre
                                    </p>
                                    <p class="mt-2 text-3xl font-bold text-slate-900">
                                        {{ $unpaidInvoicesCount }}
                                    </p>
                                    <p class="mt-2 text-sm text-slate-500">
                                        Non payées ou partiellement payées
                                    </p>
                                </div>
                                <div class="rounded-xl bg-white p-2 text-slate-600 shadow-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2.25" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-rose-100 bg-rose-50 p-5">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm font-medium text-rose-600">
                                        Urgent
                                    </p>
                                    <p class="mt-2 text-3xl font-bold text-rose-700">
                                        {{ $overdueInvoicesCount }}
                                    </p>
                                    <p class="mt-2 text-sm text-rose-500">
                                        Factures en retard
                                    </p>
                                </div>
                                <div class="rounded-xl bg-white/80 p-2 text-rose-700 shadow-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.95 3.374H4.647c-1.733 0-2.816-1.874-1.95-3.374L10.05 3.374c.866-1.5 3.034-1.5 3.9 0l7.353 12.752ZM12 16.5h.008v.008H12V16.5Z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <div class="rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50">
                    <div class="mb-5 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-slate-900">
                                Derniers devis
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Les devis récemment créés.
                            </p>
                        </div>

                        <a
                            href="{{ route('quotes.index') }}"
                            class="text-sm font-semibold text-slate-700 transition hover:text-slate-900"
                        >
                            Voir tout
                        </a>
                    </div>

                    <div class="space-y-3">
                        @forelse($recentQuotes as $quote)
                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4 transition duration-200 hover:border-slate-300 hover:bg-white">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="font-semibold text-slate-900">
                                            {{ $quote->quote_number }}
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Créé le {{ $quote->created_at->format('d/m/Y') }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm text-slate-500">Montant</p>
                                        <p class="font-semibold text-slate-900">
                                            {{ number_format($quote->total_ttc, 2, ',', ' ') }} €
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                                <p class="text-sm text-slate-500">
                                    Aucun devis récent.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm shadow-slate-200/50">
                    <div class="mb-5 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-slate-900">
                                Dernières factures
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Les dernières factures émises.
                            </p>
                        </div>

                        <a
                            href="{{ route('invoices.index') }}"
                            class="text-sm font-semibold text-slate-700 transition hover:text-slate-900"
                        >
                            Voir tout
                        </a>
                    </div>

                    <div class="space-y-3">
                        @forelse($recentInvoices as $invoice)
                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4 transition duration-200 hover:border-slate-300 hover:bg-white">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="font-semibold text-slate-900">
                                            {{ $invoice->invoice_number }}
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Créée le {{ $invoice->created_at->format('d/m/Y') }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm text-slate-500">Montant</p>
                                        <p class="font-semibold text-slate-900">
                                            {{ number_format($invoice->total_ttc, 2, ',', ' ') }} €
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                                <p class="text-sm text-slate-500">
                                    Aucune facture récente.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </section>

        </div>
    </div>
</x-app-layout>