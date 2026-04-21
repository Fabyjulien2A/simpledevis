<x-guest-layout>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 bg-white">
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
                        Connectez-vous à votre espace de gestion.
                    </h1>

                    <p class="mt-6 text-lg leading-relaxed text-white/85">
                        Créez vos devis, transformez-les en factures, suivez votre activité
                        et gardez une vue claire sur votre business au quotidien.
                    </p>

                    <div class="mt-10 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="rounded-2xl border border-white/10 bg-white/10 px-4 py-4 backdrop-blur">
                            <p class="text-sm text-white/70">Rapide</p>
                            <p class="mt-1 font-semibold">Devis en quelques clics</p>
                        </div>

                        <div class="rounded-2xl border border-white/10 bg-white/10 px-4 py-4 backdrop-blur">
                            <p class="text-sm text-white/70">Clair</p>
                            <p class="mt-1 font-semibold">Suivi simple des factures</p>
                        </div>

                        <div class="rounded-2xl border border-white/10 bg-white/10 px-4 py-4 backdrop-blur">
                            <p class="text-sm text-white/70">Pro</p>
                            <p class="mt-1 font-semibold">Interface moderne et fluide</p>
                        </div>
                    </div>
                </div>

                <div class="text-sm text-white/70">
                    Simple, efficace, pensé pour les indépendants et petites entreprises.
                </div>
            </div>
        </div>

        <!-- Colonne droite -->
        <div class="flex items-center justify-center bg-gradient-to-br from-slate-50 via-white to-indigo-50 px-6 py-10 sm:px-10">
            <div class="w-full max-w-md">
                <!-- Branding mobile -->
                <div class="lg:hidden text-center mb-8">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg">
                        <span class="text-lg font-bold">SD</span>
                    </div>
                    <h2 class="mt-4 text-2xl font-bold text-gray-900">SimpleDevis</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Connectez-vous à votre espace.
                    </p>
                </div>

                <div class="rounded-3xl border border-gray-100 bg-white p-8 shadow-2xl shadow-gray-200/50">
                    <div class="mb-8 text-center">
                        <h1 class="text-3xl font-bold text-gray-900">Connexion</h1>
                        <p class="mt-2 text-sm leading-relaxed text-gray-500">
                            Heureux de vous revoir. Connectez-vous à votre espace pour gérer
                            vos devis et factures simplement.
                        </p>
                    </div>

                    <!-- Session Status -->
                    <x-auth-session-status class="mb-4 text-sm text-green-600" :status="session('status')" />

                    <form method="POST" action="{{ route('login') }}" class="space-y-5">
                        @csrf

                        <!-- Email -->
                        <div>
                            <x-input-label
                                for="email"
                                :value="__('Adresse e-mail')"
                                class="text-sm font-semibold text-gray-700"
                            />

                            <x-text-input
                                id="email"
                                class="block mt-2 w-full rounded-2xl border-gray-200 bg-gray-50/60 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500"
                                type="email"
                                name="email"
                                :value="old('email')"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder="exemple@email.com"
                            />

                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <!-- Password -->
                        <div>
                            <x-input-label
                                for="password"
                                :value="__('Mot de passe')"
                                class="text-sm font-semibold text-gray-700"
                            />

                            <x-text-input
                                id="password"
                                class="block mt-2 w-full rounded-2xl border-gray-200 bg-gray-50/60 px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500"
                                type="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                            />

                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <!-- Remember / forgot -->
                        <div class="flex items-center justify-between gap-4 pt-1">
                            <label for="remember_me" class="inline-flex items-center text-sm text-gray-600">
                                <input
                                    id="remember_me"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    name="remember"
                                >
                                <span class="ms-2">{{ __('Se souvenir de moi') }}</span>
                            </label>

                            @if (Route::has('password.request'))
                                <a
                                    class="text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
                                    href="{{ route('password.request') }}"
                                >
                                    {{ __('Mot de passe oublié ?') }}
                                </a>
                            @endif
                        </div>

                        <!-- Submit -->
                        <div class="pt-2">
                            <button
                                type="submit"
                                class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition duration-200 hover:scale-[1.01] hover:from-indigo-700 hover:to-purple-700"
                            >
                                {{ __('Se connecter') }}
                            </button>
                        </div>
                    </form>

                    <div class="mt-8 border-t border-gray-100 pt-6">
                        <p class="text-center text-sm text-gray-500">
                            Un espace pensé pour vous faire gagner du temps sur votre gestion quotidienne.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>