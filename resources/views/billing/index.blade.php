<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-slate-900">
            Mon abonnement
        </h2>
    </x-slot>

    @php
        $user = auth()->user();
    @endphp

    <div class="py-10">
        <div class="mx-auto max-w-3xl px-4">

            <div class="rounded-3xl bg-white p-8 shadow-sm border border-slate-200">

                <h3 class="text-xl font-semibold text-slate-900 mb-6">
                    Détails de ton abonnement
                </h3>

                {{-- PLAN --}}
                <div class="mb-6">
                    <p class="text-sm text-slate-500">Plan actuel</p>
                    <p class="text-2xl font-bold text-slate-900">
                        {{ $user->planLabel() }}
                    </p>
                </div>

                {{-- STATUT --}}
                <div class="mb-6">
                    <p class="text-sm text-slate-500">Statut</p>

                    @if($user->isSubscribed())
                        <span class="inline-block mt-1 rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">
                            Actif
                        </span>
                    @else
                        <span class="inline-block mt-1 rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-700">
                            Gratuit
                        </span>
                    @endif
                </div>

                {{-- LIMITES GRATUIT --}}
                @unless($user->isSubscribed())
                    <div class="mb-6">
                        <p class="text-sm text-slate-500 mb-2">Tes limites actuelles</p>

                        <ul class="space-y-1 text-sm text-slate-700">
                            <li>Clients restants : <strong>{{ $user->remainingFreeClients() }}</strong></li>
                            <li>Devis restants : <strong>{{ $user->remainingFreeQuotes() }}</strong></li>
                            <li>Factures restantes : <strong>{{ $user->remainingFreeInvoices() }}</strong></li>
                        </ul>
                    </div>
                @endunless

                {{-- ACTION --}}
                <div class="mt-8">
                    <a href="{{ route('pricing') }}"
                       class="inline-block rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">
                        Voir les offres
                    </a>
                </div>

                
                @if($user->isSubscribed())
    <form action="{{ route('billing.portal') }}" method="POST" class="mt-4">
        @csrf
        <button type="submit"
            class="inline-block rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 transition">
            Gérer mon abonnement
        </button>
    </form>
@endif

            </div>

        </div>
    </div>
</x-app-layout>