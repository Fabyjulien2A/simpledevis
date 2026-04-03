{{-- 
    Formulaire réutilisable pour la création et l'édition d'un client.
    On utilise old() pour conserver les valeurs en cas d'erreur de validation.
--}}

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">
    <div>
        <label for="company_name" class="mb-1 block text-sm font-medium text-gray-700">Société</label>
        <input type="text" name="company_name" id="company_name"
               value="{{ old('company_name', $client->company_name ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('company_name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="first_name" class="mb-1 block text-sm font-medium text-gray-700">Prénom</label>
        <input type="text" name="first_name" id="first_name"
               value="{{ old('first_name', $client->first_name ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('first_name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="last_name" class="mb-1 block text-sm font-medium text-gray-700">Nom</label>
        <input type="text" name="last_name" id="last_name"
               value="{{ old('last_name', $client->last_name ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('last_name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" id="email"
               value="{{ old('email', $client->email ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('email')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="phone" class="mb-1 block text-sm font-medium text-gray-700">Téléphone</label>
        <input type="text" name="phone" id="phone"
               value="{{ old('phone', $client->phone ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('phone')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="postal_code" class="mb-1 block text-sm font-medium text-gray-700">Code postal</label>
        <input type="text" name="postal_code" id="postal_code"
               value="{{ old('postal_code', $client->postal_code ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('postal_code')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="md:col-span-2">
        <label for="address" class="mb-1 block text-sm font-medium text-gray-700">Adresse</label>
        <input type="text" name="address" id="address"
               value="{{ old('address', $client->address ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('address')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="md:col-span-2">
        <label for="city" class="mb-1 block text-sm font-medium text-gray-700">Ville</label>
        <input type="text" name="city" id="city"
               value="{{ old('city', $client->city ?? '') }}"
               class="w-full rounded-lg border-gray-300 shadow-sm">
        @error('city')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="md:col-span-2">
        <label for="notes" class="mb-1 block text-sm font-medium text-gray-700">Notes</label>
        <textarea name="notes" id="notes" rows="4"
                  class="w-full rounded-lg border-gray-300 shadow-sm">{{ old('notes', $client->notes ?? '') }}</textarea>
        @error('notes')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit"
            class="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
        {{ $buttonText }}
    </button>

    <a href="{{ route('clients.index') }}"
       class="rounded-lg bg-gray-200 px-4 py-2 text-gray-800 hover:bg-gray-300">
        Annuler
    </a>
</div>