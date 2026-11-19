<section class="rounded-2xl border border-amber-200 bg-white p-4 sm:p-6 space-y-4" aria-label="Récapitulatif avant finalisation sandbox">
    <h3 class="text-lg font-semibold text-gray-900">Récapitulatif — Sandbox SUPER PDP</h3>
    <p class="text-sm text-amber-900">{{ \App\Services\SuperPdp\SandboxInvoiceProfile::NOTICE }}</p>
    <dl class="grid gap-2 text-sm sm:grid-cols-2">
        <dt>Entreprise</dt><dd>{{ $summary['data']['seller']['name'] ?? 'Non renseigné' }}</dd>
        <dt>Client</dt><dd>{{ $summary['data']['buyer']['company_name'] ?? 'Non renseigné' }}</dd>
        <dt>Date</dt><dd>{{ $summary['data']['date'] ?? 'Non renseigné' }}</dd>
        <dt>Échéance</dt><dd>{{ $summary['data']['due_date'] ?? 'Non renseigné' }}</dd>
        <dt>Total HT calculé (EUR)</dt><dd>{{ $summary['data']['totals']['subtotal_ht'] ?? 'Indisponible' }}</dd>
        <dt>Total TVA calculé (EUR)</dt><dd>{{ $summary['data']['totals']['total_tva'] ?? 'Indisponible' }}</dd>
        <dt>Total TTC calculé (EUR)</dt><dd>{{ $summary['data']['totals']['total_ttc'] ?? 'Indisponible' }}</dd>
    </dl>
    @if($summary['errors'] !== [])
        <p class="text-sm font-semibold text-amber-900">Informations à corriger pour cet aperçu :</p>
        <ul class="list-disc pl-5 text-sm text-amber-900">
            @foreach($summary['errors'] as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
    <p class="text-sm text-gray-600">Cet aperçu contrôle les parties sandbox et calcule les montants saisis. Les contrôles serveur restent appliqués à la finalisation. Il ne confirme aucune aptitude à l’émission. Aucun appel SUPER PDP ni envoi n’est déclenché.</p>
</section>
