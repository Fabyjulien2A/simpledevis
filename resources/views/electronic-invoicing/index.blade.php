<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <h2 class="text-2xl font-semibold text-gray-900">
                    Facturation électronique
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Configurez la réception de vos factures électroniques.
                </p>
            </div>

            @if($connection?->status === 'connected')
                <span class="inline-flex items-center rounded-full bg-green-100 px-4 py-2 text-sm font-semibold text-green-700">
                    ● Connecté
                </span>
            @else
                <span class="inline-flex items-center rounded-full bg-red-100 px-4 py-2 text-sm font-semibold text-red-700">
                    ● Non connecté
                </span>
            @endif

        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-medium text-red-700">
                    {{ session('error') }}
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-2">

                {{-- SUPER PDP --}}
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200">

                    <div class="flex items-start justify-between gap-4">

                        <div>
                            <h3 class="text-xl font-semibold text-gray-900">
                                SUPER PDP
                            </h3>

                            <p class="mt-2 text-sm leading-6 text-gray-500">
                                Connectez votre entreprise afin de recevoir automatiquement
                                vos factures électroniques dans SimpleDevis.
                            </p>
                        </div>

                        <div class="rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">
                            Bac à sable
                        </div>

                    </div>

                    <div class="mt-6 divide-y divide-gray-100">

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Statut
                            </span>

                            <span class="text-sm font-semibold {{ $connection?->status === 'connected' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $connection?->status === 'connected' ? 'Connecté' : 'Non connecté' }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Entreprise
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $company->company_name ?? 'Non renseignée' }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                SIRET
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $company->siret ?? 'Non renseigné' }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Réception
                            </span>

                            @if($connection?->reception_enabled)
                                <span class="text-sm font-semibold text-green-600">
                                    Activée
                                </span>
                            @else
                                <span class="text-sm font-semibold text-gray-700">
                                    Désactivée
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Adresse électronique
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $connection?->directory_identifier ?? 'Non définie' }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Dernière synchronisation
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $connection?->last_sync_at?->format('d/m/Y à H:i') ?? 'Jamais' }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-4">
                            <span class="text-sm text-gray-500">
                                Factures reçues
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $receivedInvoicesCount }}
                            </span>
                        </div>

                    </div>

                    <div class="mt-8">

                        @if($connection?->status === 'connected')

                            <form
                                method="POST"
                                action="{{ route('electronic-invoicing.sync') }}"
                                class="mb-3"
                            >
                                @csrf

                                <button
                                    type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-gray-900 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                                >
                                    Synchroniser les factures
                                </button>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('electronic-invoicing.disconnect') }}"
                            >
                                @csrf

                                <button
                                    type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-red-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                                >
                                    Déconnecter SUPER PDP
                                </button>
                            </form>

                        @else

                            <a
                                href="{{ route('electronic-invoicing.connect') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            >
                                Connecter SUPER PDP
                            </a>

                        @endif

                    </div>

                </div>


                                {{-- Informations --}}
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200">

                    <h3 class="text-xl font-semibold text-gray-900">
                        Réception des factures
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        SimpleDevis utilisera SUPER PDP comme plateforme technique
                        pour recevoir les factures électroniques de votre entreprise.
                    </p>

                    <div class="mt-6 space-y-5">

                        <div class="rounded-xl bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">
                                1. Connexion sécurisée
                            </h4>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                Vous serez redirigé vers SUPER PDP afin d'autoriser
                                SimpleDevis à accéder à votre compte.
                            </p>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">
                                2. Activation de la réception
                            </h4>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                Une adresse électronique de facturation sera associée
                                à votre entreprise dans l'annuaire.
                            </p>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">
                                3. Synchronisation automatique
                            </h4>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                Les factures reçues seront récupérées depuis SUPER PDP
                                puis enregistrées automatiquement dans SimpleDevis.
                            </p>
                        </div>

                    </div>

                    <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <p class="text-sm leading-6 text-blue-700">
                            Vous utilisez actuellement l'environnement de test (bac à sable).
                            Aucune donnée de production n'est concernée.
                        </p>
                    </div>

                    <div class="mt-6">
                        <a
                            href="{{ route('supplier-invoices.index') }}"
                            class="inline-flex items-center text-sm font-semibold text-blue-600 transition hover:text-blue-800"
                        >
                            Consulter les factures reçues
                            <span class="ml-2">→</span>
                        </a>
                    </div>

                </div>

            </div>

        </div>
    </div>

</x-app-layout>