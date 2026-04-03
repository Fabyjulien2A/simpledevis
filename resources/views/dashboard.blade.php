<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-800 leading-tight">
            Tableau de bord
        </h2>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">

            <div class="rounded-2xl bg-gradient-to-r from-slate-900 to-slate-700 p-8 text-white shadow-lg">
                <p class="text-sm uppercase tracking-wider text-gray-200 mb-2">
                    Bienvenue
                </p>
                <h1 class="text-3xl font-bold mb-3">
                    Heureux de te revoir 👋
                </h1>
                <p class="text-sm text-gray-200 max-w-2xl">
                    Suis ton activité, tes devis, tes factures et tes encaissements depuis un espace clair et professionnel.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Clients</p>
                    <p class="mt-3 text-3xl font-bold text-gray-900">{{ $clientsCount }}</p>
                    <p class="mt-2 text-sm text-gray-400">Clients enregistrés</p>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Devis</p>
                    <p class="mt-3 text-3xl font-bold text-gray-900">{{ $quotesCount }}</p>
                    <p class="mt-2 text-sm text-gray-400">Devis créés</p>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Factures</p>
                    <p class="mt-3 text-3xl font-bold text-gray-900">{{ $invoicesCount }}</p>
                    <p class="mt-2 text-sm text-gray-400">Factures émises</p>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Montant encaissé</p>
                    <p class="mt-3 text-3xl font-bold text-gray-900">{{ number_format($amountCollected, 2) }} €</p>
                    <p class="mt-2 text-sm text-gray-400">Total des paiements reçus</p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-6">
                        Actions rapides
                    </h3>

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
                            <p class="mt-1 text-xl font-bold text-gray-900">
                                {{ number_format($amountToCollect, 2) }} €
                            </p>
                        </div>

                        <div class="rounded-xl bg-red-50 p-4 border border-red-100">
                            <p class="text-sm text-red-700">Factures à suivre</p>
                            <p class="mt-1 text-xl font-bold text-red-800">
                                {{ $unpaidInvoicesCount }}
                            </p>
                            <p class="mt-1 text-sm text-red-600">
                                Non payées ou partiellement payées
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Derniers devis</h3>

                    @forelse($recentQuotes as $quote)
                        <div class="py-3 border-b border-gray-100 last:border-b-0">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-gray-900">{{ $quote->quote_number }}</span>
                                <span class="text-sm text-gray-500">{{ $quote->created_at->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-sm text-gray-500 mt-1">
                                {{ number_format($quote->total_ttc, 2) }} €
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Aucun devis récent.</p>
                    @endforelse
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Dernières factures</h3>

                    @forelse($recentInvoices as $invoice)
                        <div class="py-3 border-b border-gray-100 last:border-b-0">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-gray-900">{{ $invoice->invoice_number }}</span>
                                <span class="text-sm text-gray-500">{{ $invoice->created_at->format('d/m/Y') }}</span>
                            </div>
                            <div class="text-sm text-gray-500 mt-1">
                                {{ number_format($invoice->total_ttc, 2) }} €
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Aucune facture récente.</p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>