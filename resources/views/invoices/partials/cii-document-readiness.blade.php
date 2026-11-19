<section class="rounded-3xl border bg-white p-4 shadow-lg sm:p-6" aria-labelledby="cii-document-title">
    <h3 id="cii-document-title" class="text-lg font-semibold text-gray-900">Document CII</h3>
    @if(session('cii_request_messages'))
        <div role="status" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
            <ul class="list-disc space-y-2 pl-5">
                @foreach(session('cii_request_messages') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="mt-4 space-y-3 rounded-xl border border-gray-200 bg-slate-50 p-4" aria-label="Résultat de préparation CII">
        <p class="font-semibold text-gray-900">{{ $ciiResult['label'] }}</p>
        <dl class="space-y-2 text-sm text-gray-700">
            @if($ciiResult['state'] !== 'none')
                <div><dt class="font-medium">Dernier état enregistré (heure de Paris)</dt><dd>{{ $ciiResult['date'] ?? 'Date indisponible' }}</dd></div>
            @endif
            <div><dt class="font-medium">Statut de validation du document</dt><dd>{{ $ciiResult['validation'] }}</dd></div>
        </dl>
        @foreach($ciiResult['messages'] as $message)
            <p class="text-sm text-gray-700">{{ $message }}</p>
        @endforeach
        @if($ciiResult['download_id'])
            <a href="{{ route('invoices.cii-download', ['invoice' => $invoice, 'reservation' => $ciiResult['download_id']]) }}" class="inline-block text-sm font-semibold text-indigo-700 underline">{{ $ciiResult['simulation'] ? 'Télécharger le document de simulation' : ($ciiResult['state'] === 'validated' ? 'Télécharger le CII validé' : 'Télécharger le CII non validé') }}</a>
        @endif
    </div>
    <p class="mt-2 text-sm text-gray-600">Ce bilan consulte uniquement les données figées, la connexion locale et les preuves déjà enregistrées. Aucun appel SUPER PDP, renouvellement OAuth ou document n’est déclenché.</p>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach($ciiReadiness['groups'] as $key => $group)
            <div class="rounded-xl border border-gray-200 p-4" data-cii-prerequisite="{{ $key }}">
                <h4 class="font-semibold text-gray-900">{{ $group['label'] }}</h4>
                <p class="mt-2 text-sm font-medium {{ $group['passed'] ? 'text-emerald-800' : 'text-amber-900' }}">{{ $group['passed'] ? 'Prérequis locaux réunis' : 'Préparation bloquée' }}</p>
                @if($key === 'documents')
                    <p class="mt-2 text-sm text-gray-600">La structure et les calculs locaux ne constituent pas une validation complète du CII.</p>
                @elseif($key === 'evidence')
                    <p class="mt-2 text-sm text-gray-600">Identité du vendeur, session vendeur et destination acheteur : trois preuves distinctes, réelles et récentes sont exigées. Une adresse déclarée ou une publication ne les remplace pas.</p>
                @elseif($key === 'oauth')
                    <p class="mt-2 text-sm text-gray-600">La connexion enregistrée doit correspondre au client et à l’environnement sandbox attendus. Elle ne prouve pas le routage.</p>
                @else
                    <p class="mt-2 text-sm text-gray-600">L’autorisation doit être explicite, limitée à cette facture et à ses empreintes, et valable au maximum quinze minutes.</p>
                @endif
                @if($group['messages'])
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-amber-900">
                        @foreach($group['messages'] as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
    @if($ciiReadiness['can_prepare'] && $ciiResult['state'] === 'none')
        <p class="mt-4 text-sm text-gray-700">La préparation sandbox demandera une conversion et une validation distantes du document. Elle ne transmet pas la facture à son destinataire.</p>
        <form method="POST" action="{{ route('invoices.request-cii-preparation', $invoice) }}" class="mt-4">
            @csrf
            <button type="submit" class="rounded-xl bg-indigo-700 px-5 py-3 text-sm font-semibold text-white">Préparer le document CII</button>
        </form>
    @else
        <p id="cii-preparation-unavailable" class="mt-4 text-sm font-medium text-amber-900">{{ !$ciiReadiness['web_preparation_enabled'] ? 'Le parcours web de préparation CII n’est pas activé. Le bouton reste désactivé, même si les prérequis locaux sont réunis.' : 'La préparation reste bloquée par les prérequis ci-dessus ou par un résultat déjà enregistré. Aucune relance automatique.' }}</p>
        <button type="button" disabled aria-describedby="cii-preparation-unavailable" class="mt-4 cursor-not-allowed rounded-xl bg-gray-200 px-5 py-3 text-sm font-semibold text-gray-600">Préparer le document CII</button>
    @endif
    <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-gray-700">
        <li>Document CII non produit par cette consultation.</li>
        <li>Validation du CII non effectuée par cette consultation.</li>
        <li>Une validation CII ne prouve pas le routage et n’autorise pas l’émission.</li>
        <li>Envoi électronique interdit depuis cette page.</li>
    </ul>
</section>
