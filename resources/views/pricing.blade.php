<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarifs - SimpleDevis</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-slate-50 text-slate-900 antialiased">

    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
<a href="/" class="flex items-center gap-2">
    <img
        src="{{ asset('images/logo-1.png') }}"
        class="h-12 w-auto"
        alt="SimpleDevis"
    >
</a>

            <nav class="hidden items-center gap-6 md:flex">
                <a href="{{ url('/') }}" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">
                    Accueil
                </a>
                <a href="{{ route('login') }}" class="text-sm font-medium text-slate-600 transition hover:text-slate-900">
                    Connexion
                </a>
                <a href="{{ route('register') }}" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">
                    S’inscrire
                </a>
            </nav>
        </div>
    </header>

    <section class="relative overflow-hidden px-6 py-20 text-center">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(59,130,246,0.10),transparent_30%),radial-gradient(circle_at_bottom_left,rgba(15,23,42,0.05),transparent_35%)]"></div>

        <div class="relative mx-auto max-w-4xl">
            <span class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-4 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-blue-700">
                Tarification simple
            </span>

            <h1 class="mt-6 text-4xl font-bold tracking-tight text-slate-950 md:text-6xl">
                Choisis l’offre adaptée à ton activité
            </h1>

            <p class="mx-auto mt-5 max-w-2xl text-lg leading-8 text-slate-600">
                Commence gratuitement, teste l’outil à ton rythme et passe à l’offre Pro quand tu veux aller plus vite et gérer ton activité sans limite.
            </p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-6 text-sm text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    Sans engagement
                </div>
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                    Activation immédiate
                </div>
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full bg-violet-500"></span>
                    Pensé pour les indépendants
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-6 pb-20">
        <div class="grid gap-8 lg:grid-cols-3">

            <div class="flex flex-col justify-between rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                <div>
                    <h2 class="text-center text-2xl font-semibold text-slate-900">
                        Découverte
                    </h2>
                    <p class="mt-2 text-center text-sm text-slate-500">
                        Pour tester l’outil tranquillement
                    </p>

                    <div class="mt-8 text-center">
                        <span class="text-5xl font-bold tracking-tight text-slate-950">0€</span>
                        <span class="text-lg text-slate-500">/mois</span>
                    </div>

                    <p class="mt-3 text-center text-sm font-medium text-slate-500">
                        Idéal pour découvrir SimpleDevis
                    </p>

                    <ul class="mt-8 space-y-4 text-sm text-slate-600">
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Jusqu’à 5 clients
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Jusqu’à 10 devis
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Jusqu’à 10 factures
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> PDF inclus
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Support basique
                        </li>
                    </ul>
                </div>

                <a
                    href="{{ route('register') }}"
                    class="mt-10 rounded-2xl border border-slate-200 py-3 text-center text-sm font-semibold text-slate-900 transition hover:bg-slate-100"
                >
                    Commencer gratuitement
                </a>
            </div>

            <div class="relative flex flex-col justify-between rounded-3xl border-2 border-blue-600 bg-gradient-to-b from-white to-blue-50 p-8 shadow-xl shadow-blue-600/10 transition duration-200 hover:-translate-y-1 hover:shadow-2xl">
                <div class="absolute -top-4 left-1/2 -translate-x-1/2">
                    <span class="rounded-full bg-blue-600 px-4 py-1 text-xs font-semibold text-white shadow-md">
                        Recommandé
                    </span>
                </div>

                <div>
                    <h2 class="text-center text-2xl font-semibold text-slate-900">
                        Pro
                    </h2>
                    <p class="mt-2 text-center text-sm text-slate-500">
                        Pour gérer ton activité sérieusement
                    </p>

                    <div class="mt-8 text-center">
                        <span class="text-5xl font-bold tracking-tight text-slate-950">12€</span>
                        <span class="text-lg text-slate-500">/mois</span>
                    </div>

                    <p class="mt-3 text-center text-sm font-semibold text-blue-700">
                        Le plus choisi par les indépendants 🚀
                    </p>

                    <ul class="mt-8 space-y-4 text-sm text-slate-700">
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Clients illimités
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Devis illimités
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Factures illimitées
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Suivi des paiements
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Dashboard complet
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> PDF professionnels
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-blue-600">✓</span> Support prioritaire
                        </li>
                    </ul>
                </div>

                @auth
                    <form action="{{ route('billing.subscribe', 'pro') }}" method="POST">
                        @csrf
                        <button
                            type="submit"
                            class="mt-10 w-full rounded-2xl bg-blue-600 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-600/30 transition hover:bg-blue-700"
                        >
                            Choisir Pro
                        </button>
                    </form>
                @else
                    <a
                        href="{{ route('register') }}"
                        class="mt-10 rounded-2xl bg-blue-600 py-3 text-center text-sm font-semibold text-white shadow-lg shadow-blue-600/30 transition hover:bg-blue-700"
                    >
                        Choisir Pro
                    </a>
                @endauth
            </div>

            <div class="flex flex-col justify-between rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                <div>
                    <h2 class="text-center text-2xl font-semibold text-slate-900">
                        Business
                    </h2>
                    <p class="mt-2 text-center text-sm text-slate-500">
                        Pour aller plus loin avec ton activité
                    </p>

                    <div class="mt-8 text-center">
                        <span class="text-5xl font-bold tracking-tight text-slate-950">29€</span>
                        <span class="text-lg text-slate-500">/mois</span>
                    </div>

                    <p class="mt-3 text-center text-sm font-medium text-slate-500">
                        Pour une activité en croissance
                    </p>

                    <ul class="mt-8 space-y-4 text-sm text-slate-600">
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Tout dans l’offre Pro
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Fonctionnalités avancées
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Priorité sur les nouveautés
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Assistance renforcée
                        </li>
                        <li class="flex items-center justify-center gap-2">
                            <span class="text-emerald-500">✓</span> Pensé pour une activité en croissance
                        </li>
                    </ul>
                </div>

                @auth
                    <form action="{{ route('billing.subscribe', 'business') }}" method="POST">
                        @csrf
                        <button
                            type="submit"
                            class="mt-10 w-full rounded-2xl border border-slate-200 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100"
                        >
                            Choisir Business
                        </button>
                    </form>
                @else
                    <a
                        href="{{ route('register') }}"
                        class="mt-10 rounded-2xl border border-slate-200 py-3 text-center text-sm font-semibold text-slate-900 transition hover:bg-slate-100"
                    >
                        Choisir Business
                    </a>
                @endauth
            </div>

        </div>
    </section>

    <section class="mx-auto max-w-4xl px-6 pb-24">
        <h2 class="mb-10 text-center text-3xl font-bold tracking-tight text-slate-950">
            Questions fréquentes
        </h2>

        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="font-semibold text-slate-900">Puis-je changer d’offre plus tard ?</h3>
                <p class="mt-2 text-slate-500">
                    Oui, tu pourras passer à une offre supérieure à tout moment selon l’évolution de ton activité.
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="font-semibold text-slate-900">Y a-t-il un engagement ?</h3>
                <p class="mt-2 text-slate-500">
                    Non, les offres sont simples, flexibles et sans engagement.
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="font-semibold text-slate-900">La formule gratuite suffit-elle pour tester ?</h3>
                <p class="mt-2 text-slate-500">
                    Oui, elle est idéale pour découvrir l’outil avant de passer à une formule plus complète.
                </p>
            </div>
        </div>
    </section>

</body>
</html>