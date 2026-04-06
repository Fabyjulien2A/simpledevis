<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 leading-tight">
                Tableau de bord
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                Vue d’ensemble de ton activité
            </p>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Hero --}}
            <div class="rounded-3xl bg-gradient-to-r from-slate-900 to-slate-700 p-6 md:p-8 text-white shadow-lg">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-gray-300 font-semibold">
                            Bienvenue
                        </p>
                        <h1 class="mt-2 text-2xl md:text-3xl font-bold">
                            Heureux de te revoir 👋
                        </h1>
                        <p class="mt-2 text-sm text-gray-200 max-w-2xl">
                            Suis ton activité, tes devis, tes factures et tes encaissements depuis un espace clair et professionnel.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('quotes.create') }}"
                           class="inline-flex items-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-gray-100">
                            Nouveau devis
                        </a>

                        <a href="{{ route('clients.create') }}"
                           class="inline-flex items-center rounded-xl bg-white-10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/20">
                            Nouveau client
                        </a>
                    </div>
                </div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Clients</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $clientsCount }}</p>
                    <p class="mt-1 text-sm text-gray-400">Clients enregistrés</p>
                </div>

                <div class="rounded-2xl bg-white p-5 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Devis</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $quotesCount }}</p>
                    <p class="mt-1 text-sm text-gray-400">Devis créés</p>
                </div>

                <div class="rounded-2xl bg-white p-5 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Factures</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $invoicesCount }}</p>
                    <p class="mt-1 text-sm text-gray-400">Factures émises</p>
                </div>

                <div class="rounded-2xl bg-white p-5 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Montant encaissé</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ number_format($amountCollected, 2, ',', ' ') }} €</p>
                    <p class="mt-1 text-sm text-gray-400">Total des paiements reçus</p>
                </div>
            </div>

            {{-- Main blocks --}}
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="xl:col-span-2 rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <div class="mb-5">
                        <h3 class="text-lg font-semibold text-gray-800">
                            Actions rapides
                        </h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Accède rapidement aux sections principales du SaaS.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <a href="{{ route('clients.index') }}"
                           class="rounded-xl border border-gray-200 p-5 hover:shadow-md hover:border-blue-200 transition bg-white">
                            <p class="text-sm text-gray-500">Gestion</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">Voir les clients</p>
                            <p class="mt-2 text-sm text-gray-400">Consulter et gérer ta base clients</p>
                        </a>

                        <a href="{{ route('quotes.index') }}"
                           class="rounded-xl border border-gray-200 p-5 hover:shadow-md hover:border-blue-200 transition bg-white">
                            <p class="text-sm text-gray-500">Devis</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">Voir les devis</p>
                            <p class="mt-2 text-sm text-gray-400">Accéder à tous tes devis</p>
                        </a>

                        <a href="{{ route('invoices.index') }}"
                           class="rounded-xl border border-gray-200 p-5 hover:shadow-md hover:border-blue-200 transition bg-white">
                            <p class="text-sm text-gray-500">Facturation</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">Voir les factures</p>
                            <p class="mt-2 text-sm text-gray-400">Suivre les paiements et les factures</p>
                        </a>

                        <a href="{{ route('company.edit') }}"
                           class="rounded-xl border border-gray-200 p-5 hover:shadow-md hover:border-blue-200 transition bg-white">
                            <p class="text-sm text-gray-500">Entreprise</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">Modifier mes infos</p>
                            <p class="mt-2 text-sm text-gray-400">Logo, coordonnées et informations légales</p>
                        </a>
                    </div>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        Vue rapide
                    </h3>

                    <div class="space-y-4">
                        <div class="rounded-xl bg-gray-50 p-4 border border-gray-100">
                            <p class="text-sm text-gray-500">Reste à encaisser</p>
                            <p class="mt-1 text-2xl font-bold text-gray-900">
                                {{ number_format($amountToCollect, 2, ',', ' ') }} €
                            </p>
                        </div>

                        <div class="rounded-xl bg-red-50 p-4 border border-red-100">
                            <p class="text-sm text-red-700">Factures à suivre</p>
                            <p class="mt-1 text-2xl font-bold text-red-800">
                                {{ $unpaidInvoicesCount }}
                            </p>
                            <p class="mt-1 text-sm text-red-600">
                                Non payées ou partiellement payées
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Recent documents --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Derniers devis</h3>
                        <a href="{{ route('quotes.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">
                            Voir tout
                        </a>
                    </div>

                    <div class="space-y-3">
                        @forelse($recentQuotes as $quote)
                            <div class="rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 transition">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-900">{{ $quote->quote_number }}</span>
                                    <span class="text-sm text-gray-500">{{ $quote->created_at->format('d/m/Y') }}</span>
                                </div>
                                <div class="mt-1 text-sm text-gray-500">
                                    {{ number_format($quote->total_ttc, 2, ',', ' ') }} €
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Aucun devis récent.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Dernières factures</h3>
                        <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">
                            Voir tout
                        </a>
                    </div>

                    <div class="space-y-3">
                        @forelse($recentInvoices as $invoice)
                            <div class="rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 transition">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-900">{{ $invoice->invoice_number }}</span>
                                    <span class="text-sm text-gray-500">{{ $invoice->created_at->format('d/m/Y') }}</span>
                                </div>
                                <div class="mt-1 text-sm text-gray-500">
                                    {{ number_format($invoice->total_ttc, 2, ',', ' ') }} €
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Aucune facture récente.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>