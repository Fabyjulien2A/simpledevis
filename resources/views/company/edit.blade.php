<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">Entreprise</p>
                <h2 class="text-2xl font-bold text-gray-900 leading-tight">
                    Paramètres entreprise
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Renseignez les informations utilisées sur vos devis et factures.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50 py-10">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-3xl border border-white/60 bg-white/95 shadow-2xl shadow-gray-200/40 backdrop-blur-sm">
                <div class="border-b border-gray-100 px-6 py-5 lg:px-8">
                    <h3 class="text-lg font-semibold text-gray-900">Informations de l’entreprise</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Ces informations apparaîtront sur vos documents professionnels.
                    </p>
                </div>

                <form method="POST" action="{{ route('company.update') }}" enctype="multipart/form-data" class="p-6 lg:p-8">
                    @csrf

                    {{-- Informations générales --}}
                    <div>
                        <h4 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Informations générales
                        </h4>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="company_name" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Nom de l’entreprise
                                </label>
                                <input
                                    id="company_name"
                                    type="text"
                                    name="company_name"
                                    value="{{ old('company_name', $company->company_name ?? '') }}"
                                    placeholder="Nom entreprise"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('company_name')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="email" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Adresse e-mail
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value="{{ old('email', $company->email ?? '') }}"
                                    placeholder="Email"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('email')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="phone" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Téléphone
                                </label>
                                <input
                                    id="phone"
                                    type="text"
                                    name="phone"
                                    value="{{ old('phone', $company->phone ?? '') }}"
                                    placeholder="Téléphone"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('phone')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="legal_status" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Statut juridique
                                </label>
                                <input
                                    id="legal_status"
                                    type="text"
                                    name="legal_status"
                                    value="{{ old('legal_status', $company->legal_status ?? '') }}"
                                    placeholder="EI, SASU, SAS, EURL..."
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('legal_status')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label for="address" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Adresse
                                </label>
                                <input
                                    id="address"
                                    type="text"
                                    name="address"
                                    value="{{ old('address', $company->address ?? '') }}"
                                    placeholder="Adresse"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('address')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="postal_code" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Code postal
                                </label>
                                <input
                                    id="postal_code"
                                    type="text"
                                    name="postal_code"
                                    value="{{ old('postal_code', $company->postal_code ?? '') }}"
                                    placeholder="Code postal"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('postal_code')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="city" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Ville
                                </label>
                                <input
                                    id="city"
                                    type="text"
                                    name="city"
                                    value="{{ old('city', $company->city ?? '') }}"
                                    placeholder="Ville"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('city')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Informations légales --}}
                    <div class="mt-10 border-t border-gray-100 pt-8">
                        <h4 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Informations légales
                        </h4>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="siret" class="mb-2 block text-sm font-semibold text-gray-700">
                                    SIRET
                                </label>
                                <input
                                    id="siret"
                                    type="text"
                                    name="siret"
                                    value="{{ old('siret', $company->siret ?? '') }}"
                                    placeholder="SIRET"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('siret')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="tva_number" class="mb-2 block text-sm font-semibold text-gray-700">
                                    TVA intracommunautaire
                                </label>
                                <input
                                    id="tva_number"
                                    type="text"
                                    name="tva_number"
                                    value="{{ old('tva_number', $company->tva_number ?? '') }}"
                                    placeholder="TVA intracom"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('tva_number')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Paiement --}}
                    <div class="mt-10 border-t border-gray-100 pt-8">
                        <h4 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Paiement
                        </h4>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="iban" class="mb-2 block text-sm font-semibold text-gray-700">
                                    IBAN
                                </label>
                                <input
                                    id="iban"
                                    type="text"
                                    name="iban"
                                    value="{{ old('iban', $company->iban ?? '') }}"
                                    placeholder="IBAN"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('iban')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="bic" class="mb-2 block text-sm font-semibold text-gray-700">
                                    BIC
                                </label>
                                <input
                                    id="bic"
                                    type="text"
                                    name="bic"
                                    value="{{ old('bic', $company->bic ?? '') }}"
                                    placeholder="BIC"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('bic')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label for="payment_terms" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Conditions de paiement
                                </label>
                                <textarea
                                    id="payment_terms"
                                    name="payment_terms"
                                    rows="4"
                                    placeholder="Ex : paiement à 30 jours, acompte de 30% à la commande..."
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >{{ old('payment_terms', $company->payment_terms ?? '') }}</textarea>
                                @error('payment_terms')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Paramètres devis --}}
                    <div class="mt-10 border-t border-gray-100 pt-8">
                        <h4 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Paramètres devis
                        </h4>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="quote_validity" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Validité du devis
                                </label>
                                <input
                                    id="quote_validity"
                                    type="text"
                                    name="quote_validity"
                                    value="{{ old('quote_validity', $company->quote_validity ?? '') }}"
                                    placeholder="Ex : 30 jours"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                >
                                @error('quote_validity')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Logo --}}
                    <div class="mt-10 border-t border-gray-100 pt-8">
                        <h4 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Identité visuelle
                        </h4>

                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_auto] lg:items-start">
                            <div>
                                <label for="logo" class="mb-2 block text-sm font-semibold text-gray-700">
                                    Logo
                                </label>
                                <input
                                    id="logo"
                                    type="file"
                                    name="logo"
                                    class="w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 file:mr-4 file:rounded-xl file:border-0 file:bg-indigo-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-200"
                                >

                                @error('logo')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror

                                <p class="mt-2 text-sm text-gray-500">
                                    Format conseillé : PNG ou JPG, fond transparent si possible.
                                </p>
                            </div>

                            @if(!empty($company?->logo))
                                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                    <p class="mb-3 text-sm font-medium text-gray-700">Logo actuel</p>
                                    <img
                                        src="{{ asset('storage/' . $company->logo) }}"
                                        alt="Logo entreprise"
                                        class="h-24 w-auto object-contain"
                                    >
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-10 flex flex-col gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-gray-500">
                            Ces informations seront reprises sur vos documents générés.
                        </p>

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition duration-200 hover:scale-[1.01] hover:from-indigo-700 hover:to-purple-700"
                        >
                            Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>