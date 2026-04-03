<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold">Paramètres entreprise</h2>
    </x-slot>

    <div class="p-6 max-w-3xl mx-auto">

        @if(session('success'))
            <div class="mb-4 bg-green-100 text-green-800 p-3 rounded">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('company.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="grid grid-cols-1 gap-4">
                <input type="text" name="company_name" placeholder="Nom entreprise"
                    value="{{ old('company_name', $company->company_name ?? '') }}"
                    class="border p-2 rounded">

                <input type="email" name="email" placeholder="Email"
                    value="{{ old('email', $company->email ?? '') }}"
                    class="border p-2 rounded">

                <input type="text" name="phone" placeholder="Téléphone"
                    value="{{ old('phone', $company->phone ?? '') }}"
                    class="border p-2 rounded">

                <input type="text" name="address" placeholder="Adresse"
                    value="{{ old('address', $company->address ?? '') }}"
                    class="border p-2 rounded">

                <input type="text" name="siret" placeholder="SIRET"
                    value="{{ old('siret', $company->siret ?? '') }}"
                    class="border p-2 rounded">

                <input type="text" name="tva_number" placeholder="TVA intracom"
                    value="{{ old('tva_number', $company->tva_number ?? '') }}"
                    class="border p-2 rounded">

                <div>
                    <label class="block mb-2 font-medium text-gray-700">Logo</label>
                    <input type="file" name="logo" class="border p-2 rounded w-full">

                    @error('logo')
                        <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                @if(!empty($company?->logo))
                    <div>
                        <p class="mb-2 text-sm text-gray-600">Logo actuel :</p>
                        <img src="{{ asset('storage/' . $company->logo) }}" alt="Logo entreprise" class="h-20 object-contain">
                    </div>
                @endif
            </div>

            <button class="mt-4 bg-blue-600 text-white px-4 py-2 rounded">
                Enregistrer
            </button>
        </form>
    </div>
</x-app-layout>