<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Détail du client
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="rounded-xl bg-white p-6 shadow space-y-4">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">{{ $client->full_name }}</h3>
                    <p class="text-sm text-gray-500">{{ $client->company_name ?: 'Aucune société renseignée' }}</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-sm font-medium text-gray-700">Email</p>
                        <p class="text-sm text-gray-900">{{ $client->email ?: '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700">Téléphone</p>
                        <p class="text-sm text-gray-900">{{ $client->phone ?: '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700">Adresse</p>
                        <p class="text-sm text-gray-900">{{ $client->address ?: '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700">Code postal</p>
                        <p class="text-sm text-gray-900">{{ $client->postal_code ?: '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700">Ville</p>
                        <p class="text-sm text-gray-900">{{ $client->city ?: '-' }}</p>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-700">Notes</p>
                    <p class="text-sm text-gray-900 whitespace-pre-line">{{ $client->notes ?: 'Aucune note.' }}</p>
                </div>

                <div class="flex items-center gap-3 pt-4">
                    <a href="{{ route('clients.edit', $client) }}"
                       class="rounded-lg bg-yellow-500 px-4 py-2 text-white hover:bg-yellow-600">
                        Modifier
                    </a>

                    <a href="{{ route('clients.index') }}"
                       class="rounded-lg bg-gray-200 px-4 py-2 text-gray-800 hover:bg-gray-300">
                        Retour
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>