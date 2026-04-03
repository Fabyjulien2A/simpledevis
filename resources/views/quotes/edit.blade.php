<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold">Modifier le devis</h2>
    </x-slot>

    <div class="p-6 max-w-xl mx-auto">
        @if(session('success'))
            <div class="mb-4 rounded bg-green-100 p-3 text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded bg-white p-6 shadow">
            <form method="POST" action="{{ route('quotes.update', $quote) }}">
                @csrf
                @method('PUT')

                <div class="mb-4">
                    <label class="mb-1 block">Statut</label>

                    <select name="status" class="w-full rounded border p-2">
                        <option value="brouillon" {{ $quote->status === 'brouillon' ? 'selected' : '' }}>Brouillon</option>
                        <option value="envoye" {{ $quote->status === 'envoye' ? 'selected' : '' }}>Envoyé</option>
                        <option value="accepte" {{ $quote->status === 'accepte' ? 'selected' : '' }}>Accepté</option>
                        <option value="refuse" {{ $quote->status === 'refuse' ? 'selected' : '' }}>Refusé</option>
                    </select>

                    @error('status')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-3">
                    <button class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
                        Mettre à jour
                    </button>

                    <a href="{{ route('quotes.show', $quote) }}"
                       class="rounded bg-gray-200 px-4 py-2 text-gray-800 hover:bg-gray-300">
                        Annuler
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>