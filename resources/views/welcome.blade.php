<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SimpleDevis</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">

    {{-- NAV --}}
    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/80 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <a href="{{ url('/') }}" class="flex items-center">
            <img
                src="{{ asset('images/logo-1.png') }}"
                alt="SimpleDevis"
                class="h-20 w-auto"
            >
        </a>

        <nav class="hidden items-center gap-8 md:flex">
            <a href="{{ route('pricing') }}" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">
                Tarifs
            </a>
            <a href="{{ route('login') }}" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">
                Connexion
            </a>
            <a
                href="{{ route('register') }}"
                class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
            >
                S’inscrire
            </a>
        </nav>

        <div class="md:hidden">
            <a
                href="{{ route('register') }}"
                class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
            >
                S’inscrire
            </a>
        </div>
    </div>
</header>

    {{-- HERO --}}
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(59,130,246,0.12),transparent_30%),radial-gradient(circle_at_bottom_left,rgba(15,23,42,0.08),transparent_35%)]"></div>

        <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-6 py-20 lg:grid-cols-2 lg:py-28">
            <div>
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 shadow-sm">
                    SaaS de devis & factures
                </span>

                <h1 class="mt-6 text-4xl font-bold leading-tight tracking-tight text-slate-950 md:text-6xl">
                    Gère tes devis et factures
                    <span class="text-slate-700">sans perdre de temps</span>
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-8 text-slate-600">
                    SimpleDevis t’aide à créer des devis professionnels, suivre tes paiements
                    et garder une vision claire de ton activité depuis un espace moderne et intuitif.
                </p>

                <div class="mt-8 flex flex-wrap gap-4">
                    <a
                        href="{{ route('register') }}"
                        class="rounded-2xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 transition hover:-translate-y-0.5 hover:bg-slate-800"
                    >
                        Commencer gratuitement
                    </a>

                    <a
                        href="{{ route('pricing') }}"
                        class="rounded-2xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                    >
                        Voir les tarifs
                    </a>
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-6 text-sm text-slate-500">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        Sans engagement
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                        Interface simple
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-violet-500"></span>
                        Pensé pour les indépendants
                    </div>
                </div>
            </div>

            <div class="relative">
                <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_25px_80px_-20px_rgba(15,23,42,0.18)]">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Aperçu</p>
                            <h3 class="text-lg font-semibold text-slate-900">Dashboard SimpleDevis</h3>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                            En ligne
                        </span>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-4">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-sm text-slate-500">Clients</p>
                            <p class="mt-2 text-2xl font-bold text-slate-900">24</p>
                        </div>

                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-sm text-slate-500">Devis</p>
                            <p class="mt-2 text-2xl font-bold text-slate-900">18</p>
                        </div>

                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-sm text-slate-500">Factures</p>
                            <p class="mt-2 text-2xl font-bold text-slate-900">12</p>
                        </div>

                        <div class="rounded-2xl bg-emerald-50 p-4">
                            <p class="text-sm text-emerald-700">CA du mois</p>
                            <p class="mt-2 text-2xl font-bold text-slate-900">2 450 €</p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-2xl border border-slate-100 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-700">Dernière activité</p>
                        <p class="mt-2 text-sm text-slate-500">
                            Facture <span class="font-semibold text-slate-800">FAC-2026-014</span> envoyée aujourd’hui à un client.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- FEATURES --}}
    <section class="mx-auto max-w-7xl px-6 py-20">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-slate-950 md:text-4xl">
                Tout ce qu’il faut pour gérer ton activité
            </h2>
            <p class="mt-4 text-lg text-slate-600">
                Un outil pensé pour aller à l’essentiel, sans complexité inutile.
            </p>
        </div>

        <div class="mt-14 grid gap-6 md:grid-cols-3">
            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
                <div class="mb-5 inline-flex rounded-2xl bg-slate-100 p-3 text-2xl">
                    📄
                </div>
                <h3 class="text-xl font-semibold text-slate-900">
                    Devis professionnels
                </h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    Crée rapidement des devis clairs et soignés, prêts à être envoyés à tes clients.
                </p>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
                <div class="mb-5 inline-flex rounded-2xl bg-slate-100 p-3 text-2xl">
                    💰
                </div>
                <h3 class="text-xl font-semibold text-slate-900">
                    Facturation simplifiée
                </h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    Transforme un devis en facture en quelques clics et garde le suivi de tes paiements.
                </p>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
                <div class="mb-5 inline-flex rounded-2xl bg-slate-100 p-3 text-2xl">
                    📊
                </div>
                <h3 class="text-xl font-semibold text-slate-900">
                    Suivi de l’activité
                </h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    Visualise ton chiffre d’affaires, tes factures en attente et l’évolution de ton activité.
                </p>
            </div>
        </div>
    </section>

    {{-- BENEFITS --}}
    <section class="bg-white py-20">
        <div class="mx-auto grid max-w-7xl gap-10 px-6 lg:grid-cols-2 lg:items-center">
            <div>
                <h2 class="text-3xl font-bold tracking-tight text-slate-950 md:text-4xl">
                    Conçu pour les indépendants et petites structures
                </h2>
                <p class="mt-4 text-lg leading-8 text-slate-600">
                    Pas besoin d’un logiciel complexe. SimpleDevis te permet d’aller vite, de rester organisé
                    et de présenter une image professionnelle à tes clients.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="font-semibold text-slate-900">Rapide à prendre en main</p>
                    <p class="mt-2 text-sm text-slate-600">Une interface claire, pensée pour être utilisée sans formation.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="font-semibold text-slate-900">Gain de temps</p>
                    <p class="mt-2 text-sm text-slate-600">Crée tes documents plus vite et concentre-toi sur ton activité.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="font-semibold text-slate-900">Présentation pro</p>
                    <p class="mt-2 text-sm text-slate-600">Des devis et factures propres pour rassurer tes clients.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="font-semibold text-slate-900">Vision claire</p>
                    <p class="mt-2 text-sm text-slate-600">Suis facilement ce qui est payé, en attente ou en retard.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="px-6 py-20">
        <div class="mx-auto max-w-4xl rounded-[2rem] bg-slate-900 px-8 py-14 text-center text-white shadow-[0_25px_80px_-20px_rgba(15,23,42,0.45)]">
            <h3 class="text-3xl font-bold tracking-tight md:text-4xl">
                Prêt à simplifier ta gestion ?
            </h3>

            <p class="mx-auto mt-4 max-w-2xl text-slate-300">
                Rejoins SimpleDevis et commence à gérer tes devis et factures dans un espace clair, moderne et efficace.
            </p>

            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a
                    href="{{ route('register') }}"
                    class="rounded-2xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100"
                >
                    Créer un compte
                </a>

                <a
                    href="{{ route('pricing') }}"
                    class="rounded-2xl border border-white/20 bg-white/10 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/20"
                >
                    Voir les tarifs
                </a>
            </div>
        </div>
    </section>

</body>
</html>