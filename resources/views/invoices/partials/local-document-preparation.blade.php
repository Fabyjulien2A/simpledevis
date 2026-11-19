@php
    $prepared = $documentaryPreparation['prepared_data'];
    $document = $prepared['document'] ?? null;
@endphp
<section class="rounded-3xl border bg-white p-4 shadow-lg sm:p-6" aria-labelledby="local-document-title">
    <h3 id="local-document-title" class="text-lg font-semibold text-gray-900">Préparation documentaire locale</h3>
    <p class="mt-2 text-sm text-gray-600">Les données structurées ci-dessous serviront au futur CII. Elles sont calculées en mémoire à partir des informations figées de cette facture, sans modifier de donnée.</p>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        @foreach(['CII non produit', 'Validation non effectuée', 'Envoi bloqué'] as $state)
            <p class="rounded-xl bg-slate-100 p-3 text-sm font-semibold text-slate-700">{{ $state }}</p>
        @endforeach
    </div>

    @if($document)
        <p class="mt-4 text-sm font-semibold text-emerald-800">Contrôles documentaires locaux réussis</p>
        <p class="mt-1 text-sm text-gray-600">Ce résultat ne constitue pas une validation complète du CII ni une qualification de routage.</p>
        <details class="mt-4 rounded-xl border border-gray-200">
            <summary class="cursor-pointer rounded-xl p-4 font-semibold text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Données préparées pour le futur CII</summary>
            <div class="space-y-4 border-t p-4 text-sm text-gray-700">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div><dt class="font-medium">Émetteur</dt><dd class="break-words">{{ $document['seller']['name'] }}</dd></div>
                    <div><dt class="font-medium">Destinataire</dt><dd class="break-words">{{ $document['buyer']['name'] }}</dd></div>
                    <div><dt class="font-medium">Numéro de facture</dt><dd>{{ $document['number'] }}</dd></div>
                    <div><dt class="font-medium">Date de facture</dt><dd>{{ $document['issue_date'] }}</dd></div>
                    <div><dt class="font-medium">Nature et devise</dt><dd>Facture ordinaire — {{ $document['currency_code'] }}</dd></div>
                    <div><dt class="font-medium">Échéance</dt><dd>{{ $document['payment_due_date'] ?? 'Voir les conditions de paiement' }}</dd></div>
                    <div><dt class="font-medium">Total HT</dt><dd>{{ $document['totals']['total_without_vat'] }} {{ $document['currency_code'] }}</dd></div>
                    <div><dt class="font-medium">Total TVA</dt><dd>{{ $document['totals']['total_vat_amount']['value'] }} {{ $document['currency_code'] }}</dd></div>
                    <div><dt class="font-medium">Total TTC</dt><dd>{{ $document['totals']['total_with_vat'] }} {{ $document['currency_code'] }}</dd></div>
                    <div><dt class="font-medium">Nombre de lignes</dt><dd>{{ count($document['lines']) }}</dd></div>
                </dl>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="mb-2 text-left font-medium">Lignes préparées</caption>
                        <thead><tr><th scope="col" class="p-2">Désignation</th><th scope="col" class="p-2">Quantité</th><th scope="col" class="p-2">TVA</th><th scope="col" class="p-2">Montant HT ({{ $document['currency_code'] }})</th></tr></thead>
                        <tbody>
                            @foreach($document['lines'] as $line)
                                <tr class="border-t"><td class="break-words p-2">{{ $line['item_information']['name'] }}</td><td class="p-2">{{ $line['invoiced_quantity'] }}</td><td class="p-2">{{ $line['vat_information']['invoiced_item_vat_rate'] }} %</td><td class="p-2">{{ $line['net_amount'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
        <div class="mt-4 rounded-xl bg-gray-50 p-4 text-sm text-gray-700">
            <p class="font-medium">Empreinte SHA-256 des données préparées</p>
            <code class="mt-2 block break-all" data-document-sha256>{{ $documentaryPreparation['sha256'] }}</code>
            <p class="mt-2 text-xs text-gray-500">Elle inclut le rattachement au propriétaire et à cette facture. Elle identifie les données préparées, pas un fichier CII.</p>
        </div>
    @else
        <p class="mt-4 text-sm font-semibold text-amber-900">Contrôles documentaires locaux à compléter</p>
        <p class="mt-2 text-sm text-gray-700">Les informations manquantes, incohérentes ou hors du profil pris en charge empêchent la préparation structurée complète.</p>
        <a href="#emission-diagnostic-title" class="mt-2 inline-block text-sm text-indigo-700 underline">Consulter les motifs détaillés du diagnostic</a>
        <p class="mt-3 text-sm text-gray-600">Empreinte SHA-256 indisponible : aucune préparation complète n’a été construite.</p>
    @endif
</section>
