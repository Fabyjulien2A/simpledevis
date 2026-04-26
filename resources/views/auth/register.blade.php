<x-guest-layout>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-[1.15fr_0.85fr] bg-white">

        <!-- Colonne gauche -->
        <div class="hidden lg:flex relative overflow-hidden bg-gradient-to-br from-indigo-600 via-indigo-700 to-purple-700 text-white">
            <div class="absolute inset-0 opacity-20">
                <div class="absolute top-12 left-12 h-32 w-32 rounded-full bg-white/20 blur-2xl"></div>
                <div class="absolute bottom-16 right-12 h-40 w-40 rounded-full bg-purple-300/30 blur-3xl"></div>
                <div class="absolute top-1/2 left-1/3 h-24 w-24 rounded-full bg-indigo-200/20 blur-2xl"></div>
            </div>

            <div class="relative z-10 flex h-full w-full flex-col justify-between p-12 xl:p-16">
                <div>
                    <div class="inline-flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 backdrop-blur">
                            <span class="text-xl font-bold">SD</span>
                        </div>
                        <div>
                            <p class="text-2xl font-bold">SimpleDevis</p>
                            <p class="text-sm text-white/80">Devis & factures simplement</p>
                        </div>
                    </div>
                </div>

                <div class="max-w-xl">
                    <h1 class="text-4xl xl:text-5xl font-bold leading-tight">
                        Créez votre espace de gestion.
                    </h1>

                    <p class="mt-6 text-lg leading-relaxed text-white/85">
                        Rejoignez SimpleDevis pour créer vos devis, suivre vos factures
                        et gérer votre activité avec une interface claire et moderne.
                    </p>
                </div>

                <div class="text-sm text-white/70">
                    Pensé pour les indépendants et petites entreprises.
                </div>
            </div>
        </div>

        <!-- Colonne droite -->
        <div class="flex items-center justify-center bg-gradient-to-br from-slate-50 via-white to-indigo-50 px-6 py-10 sm:px-10">
            <div class="w-full max-w-md">

                <div class="rounded-3xl border border-white/60 bg-white/95 p-8 shadow-2xl shadow-gray-300/40 backdrop-blur-sm">

                    <div class="mb-8 text-center">
                        <h1 class="text-3xl font-bold text-gray-900">Inscription</h1>

                        <p class="mt-2 text-sm leading-relaxed text-gray-600">
                            Créez votre compte pour commencer à gérer vos devis et factures simplement.
                        </p>

                        {{-- 👇 AFFICHAGE DU PLAN --}}
                        @if(request('plan') === 'pro')
                            <p class="mt-3 inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                                Offre sélectionnée : Pro
                            </p>
                        @elseif(request('plan') === 'business')
                            <p class="mt-3 inline-flex rounded-full bg-purple-50 px-3 py-1 text-xs font-semibold text-purple-700">
                                Offre sélectionnée : Business
                            </p>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('register') }}" class="space-y-5">
                        @csrf

                        {{-- 👇 IMPORTANT : on garde le plan --}}
                        @if(request('plan'))
                            <input type="hidden" name="plan" value="{{ request('plan') }}">
                        @endif

                        <!-- Nom -->
                        <div>
                            <x-input-label for="name" :value="__('Nom')" />
                            <x-text-input id="name" class="block mt-2 w-full" type="text" name="name" required />
                        </div>

                        <!-- Email -->
                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" class="block mt-2 w-full" type="email" name="email" required />
                        </div>

                        <!-- Password -->
                        <div>
                            <x-input-label for="password" :value="__('Mot de passe')" />
                            <x-text-input id="password" class="block mt-2 w-full" type="password" name="password" required />
                        </div>

                        <!-- Confirm -->
                        <div>
                            <x-input-label for="password_confirmation" :value="__('Confirmation')" />
                            <x-text-input id="password_confirmation" class="block mt-2 w-full" type="password" name="password_confirmation" required />
                        </div>

                        <button type="submit"
                                class="w-full rounded-xl bg-indigo-600 py-3 text-white font-semibold">
                            S’inscrire
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </div>
</x-guest-layout>