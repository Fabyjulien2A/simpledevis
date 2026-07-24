<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <h2 class="text-2xl font-bold text-gray-900">
                    Clients
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Gérez votre base clients et retrouvez rapidement leurs coordonnées.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">

                <span class="rounded-full bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700">
                    {{ $clients->total() }}
                    {{ $clients->total() > 1 ? 'clients' : 'client' }}
                </span>

                <a
                    href="{{ route('clients.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-700"
                >
                    + Nouveau client
                </a>

            </div>

        </div>
    </x-slot>

    <div class="py-8">

        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Message de succès --}}
            @if(session('success'))

                <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
                    {{ session('success') }}
                </div>

            @endif

            {{-- Recherche --}}
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">

                <form
                    method="GET"
                    action="{{ route('clients.index') }}"
                    class="flex flex-col gap-4 sm:flex-row sm:items-end"
                >

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
                                placeholder="Nom, société, email ou téléphone..."
                                class="w-full rounded-xl border-gray-300 pl-11 focus:border-blue-500 focus:ring-blue-500"
                            >

                        </div>

                    </div>

                    <div class="flex gap-3">

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-700"
                        >
                            Rechercher
                        </button>

                        @if(request()->filled('search'))

                            <a
                                href="{{ route('clients.index') }}"
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

                @if($clients->isEmpty())

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
                                    d="M18 18.72a9.094 9.094 0 0 0 3.742-.479 3 3 0 0 0-4.682-2.72m.94 3.198v.001c-.58.13-1.18.2-1.8.2a8.966 8.966 0 0 1-5.4-1.8m7.2 1.6a9.094 9.094 0 0 1-3.742.479m0 0a3 3 0 0 1-5.316-1.92m5.316 1.92a8.966 8.966 0 0 1-5.4-1.8m0 0a8.966 8.966 0 0 1-2.4-2.88m2.4 2.88a3 3 0 0 1-4.682-2.72 9.094 9.094 0 0 1 3.742-.479m.94 3.199A8.966 8.966 0 0 1 3 12c0-.945.146-1.856.416-2.712"
                                />
                            </svg>

                        </div>

                        <h3 class="mt-4 text-lg font-semibold text-gray-900">
                            Aucun client trouvé
                        </h3>

                        <p class="mt-2 text-sm text-gray-500">
                            @if(request()->filled('search'))
                                Aucun client ne correspond à votre recherche.
                            @else
                                Commencez par enregistrer votre premier client.
                            @endif
                        </p>

                        <a
                            href="{{ route('clients.create') }}"
                            class="mt-5 inline-flex items-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-700"
                        >
                            Ajouter un client
                        </a>

                    </div>

                @else

                    <div class="overflow-x-auto">

                        <table class="min-w-full divide-y divide-gray-200">

                            <thead class="bg-gray-50">

                                <tr>

                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Client
                                    </th>

                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Société
                                    </th>

                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Contact
                                    </th>

                                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Actions
                                    </th>

                                </tr>

                            </thead>

                            <tbody class="divide-y divide-gray-100 bg-white">

                                @foreach($clients as $client)

                                    <tr class="transition hover:bg-gray-50">

                                        <td class="px-6 py-5">

                                            <div class="font-semibold text-gray-900">
                                                {{ $client->full_name }}
                                            </div>

                                            @if($client->email)
                                                <div class="mt-1 text-sm text-gray-500">
                                                    {{ $client->email }}
                                                </div>
                                            @endif

                                        </td>

                                        <td class="px-6 py-5 text-sm text-gray-700">
                                            {{ $client->company_name ?: '—' }}
                                        </td>

                                        <td class="px-6 py-5">

                                            <div class="text-sm text-gray-700">
                                                {{ $client->phone ?: 'Téléphone non renseigné' }}
                                            </div>

                                            @if($client->city)
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $client->city }}
                                                </div>
                                            @endif

                                        </td>

                                        <td class="whitespace-nowrap px-6 py-5 text-right">

                                            <div class="inline-flex items-center gap-2">

                                                <a
                                                    href="{{ route('clients.show', $client) }}"
                                                    class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-gray-700"
                                                >
                                                    Voir
                                                </a>

                                                <a
                                                    href="{{ route('clients.edit', $client) }}"
                                                    class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100"
                                                >
                                                    Modifier
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="{{ route('clients.destroy', $client) }}"
                                                    onsubmit="return confirm('Supprimer ce client ?');"
                                                >
                                                    @csrf
                                                    @method('DELETE')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                                                    >
                                                        Supprimer
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                @endforeach

                            </tbody>

                        </table>

                    </div>

                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $clients->withQueryString()->links() }}
                    </div>

                @endif

            </div>

        </div>

    </div>

</x-app-layout>