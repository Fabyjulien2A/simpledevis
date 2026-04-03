<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Mes clients
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Message de succès après création / modification / suppression --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg bg-green-100 px-4 py-3 text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="mb-6 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Liste de vos clients</h3>

                <a href="{{ route('clients.create') }}"
                   class="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
                    + Nouveau client
                </a>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow">
                @if ($clients->count())
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nom
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Société
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Email
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Téléphone
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($clients as $client)
                                    <tr>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            {{ $client->full_name }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            {{ $client->company_name ?: '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            {{ $client->email ?: '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            {{ $client->phone ?: '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-right text-sm">
                                            <a href="{{ route('clients.show', $client) }}"
                                               class="mr-3 text-blue-600 hover:underline">
                                                Voir
                                            </a>

                                            <a href="{{ route('clients.edit', $client) }}"
                                               class="mr-3 text-yellow-600 hover:underline">
                                                Modifier
                                            </a>

                                            <form action="{{ route('clients.destroy', $client) }}"
                                                  method="POST"
                                                  class="inline-block"
                                                  onsubmit="return confirm('Supprimer ce client ?');">
                                                @csrf
                                                @method('DELETE')

                                                <button type="submit"
                                                        class="text-red-600 hover:underline">
                                                    Supprimer
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-4">
                        {{ $clients->links() }}
                    </div>
                @else
                    <div class="p-6 text-gray-600">
                        Aucun client enregistré pour le moment.
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>