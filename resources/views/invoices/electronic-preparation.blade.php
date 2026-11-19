<x-app-layout>
    @include('invoices.partials.sandbox-notice')
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-900">Préparation de l’envoi électronique</h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <a href="{{ route('invoices.show', $invoice) }}" class="text-indigo-700 underline">Retour à la facture {{ $invoice->invoice_number }}</a>
        <section class="rounded-3xl border bg-white p-6 shadow-lg" aria-labelledby="preparation-title">
            <h3 id="preparation-title" class="text-xl font-semibold">Facture {{ $invoice->invoice_number }}</h3>
            <p class="mt-2 text-sm text-gray-700">La consultation ne déclenche aucun appel SUPER PDP. La vérification de destination nécessite une action explicite. Aucune modification de facture, génération de document distant ou émission.</p>
            <p class="mt-2 text-sm text-gray-700">Le XML interne SimpleDevis n’est pas le CII électronique. Cette consultation ne génère aucun de ces documents.</p>
            <dl class="mt-4 space-y-3">
                <div>
                    <dt class="font-semibold">Éligible au profil pris en charge</dt>
                    <dd>{{ $emissionDiagnostic['local_checks_passed'] ? (\App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice) ? 'Contrôles locaux du document Sandbox SUPER PDP réussis.' : 'Oui — contrôles locaux réussis pour le profil France B2B, facture ordinaire 380 en EUR.') : 'Non — contrôles locaux à compléter ; consulter les motifs ci-dessous.' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Prête à être envoyée</dt>
                    <dd>Non — ce contrôle de destination ne valide pas l’ensemble des conditions d’envoi. Aucun envoi n’est activé.</dd>
                </div>
            </dl>
        </section>

        @if($dataError)
            <p class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900">{{ $dataError }}</p>
        @endif

        @if(\App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice))
            <p class="rounded-xl border bg-white p-4">Qualification sandbox distincte : des observations SDK récentes du vendeur, de sa session et de la publication Tricatel doivent être sélectionnées par l’autorisation serveur de préparation. Une déclaration manuelle ou l’annuaire français SIREN ne les remplace pas.</p>
        @else
            @include('invoices.partials.destination-verification')
        @endif

        @include('invoices.partials.local-document-preparation')

        @include('invoices.partials.cii-document-readiness')

        <section class="rounded-3xl border bg-white p-6 shadow-lg" aria-labelledby="frozen-parties-title">
            <h3 id="frozen-parties-title" class="text-lg font-semibold">Informations figées à la finalisation</h3>
            <p class="mt-2 text-sm text-gray-600">Les fiches actuelles de l’entreprise et du client ne remplacent pas les données figées absentes ou invalides.</p>
            @if($parties)
                <div class="mt-4 grid gap-6 md:grid-cols-2">
                    @foreach(['seller' => 'Vendeur', 'buyer' => 'Destinataire'] as $key => $label)
                        @php($party = $parties[$key])
                        <div>
                            <h4 class="font-semibold">{{ $label }}</h4>
                            <dl class="mt-2 space-y-2 text-sm">
                                <div><dt>Nom</dt><dd>{{ ($party['name'] ?? null) ?: (($party['company_name'] ?? null) ?: ($party['full_name'] ?? 'Non renseigné')) }}</dd></div>
                                @foreach(['siret' => 'SIRET', 'vat_number' => 'Numéro de TVA', 'address' => 'Adresse', 'postal_code' => 'Code postal', 'city' => 'Ville', 'country_code' => 'Pays', 'email' => 'E-mail de contact (distinct de l’adresse électronique)'] as $field => $fieldLabel)
                                    <div><dt>{{ $fieldLabel }}</dt><dd>{{ $party[$field] ?? 'Non renseigné' }}</dd></div>
                                @endforeach
                                <div>
                                    <dt>Adresse de facturation électronique déclarée</dt>
                                    <dd>{{ $context[$key.'_electronic_address']['scheme'] ?? 'Non renseignée' }} / {{ $context[$key.'_electronic_address']['value'] ?? 'Non renseignée' }}</dd>
                                </div>
                                <div><dt>Qualification de l’adresse</dt><dd>{{ $key === 'buyer' && ($destinationReport['verified'] ?? false) ? 'Destination qualifiée par une preuve récente de l’annuaire.' : 'Non vérifiée — une adresse déclarée ne prouve pas le routage.' }}</dd></div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-amber-900">Données figées des parties indisponibles. La préparation reste bloquée.</p>
            @endif
        </section>

        <section class="rounded-3xl border bg-white p-6 shadow-lg" aria-labelledby="amounts-title">
            <h3 id="amounts-title" class="text-lg font-semibold">Montants enregistrés sur la facture</h3>
            <p class="mt-2 text-sm text-gray-600">Devise électronique figée : {{ $context['currency_code'] ?? 'Non renseignée' }}</p>
            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                @foreach(['subtotal_ht' => 'Total HT', 'total_tva' => 'Total TVA', 'total_ttc' => 'Total TTC'] as $field => $label)
                    <div><dt>{{ $label }}</dt><dd class="font-semibold">{{ $invoice->$field }}</dd></div>
                @endforeach
            </dl>
        </section>

        @include('invoices.partials.emission-diagnostic', ['diagnostic' => $emissionDiagnostic])
    </div>
</x-app-layout>
