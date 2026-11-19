<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-900">Aperçu électronique (test)</h2>
    </x-slot>
    @php
        $data = $preview['data'];
        $display = static fn ($value) => $value === null || $value === '' ? 'Non renseigné'
            : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value);
        $partyFields = [
            'name' => 'Nom du vendeur', 'company_name' => 'Entreprise', 'full_name' => 'Nom du client',
            'client_type' => 'Type de client', 'siret' => 'SIRET', 'vat_number' => 'Numéro de TVA',
            'address' => 'Adresse', 'postal_code' => 'Code postal', 'city' => 'Ville', 'country_code' => 'Pays',
            'email' => 'Email de contact', 'phone' => 'Téléphone', 'legal_status' => 'Forme juridique',
            'payment_terms' => 'Conditions de paiement',
        ];
        $contextLabels = [
            'emission_profile' => 'Profil électronique déclaré',
            'french_business_process_type' => 'Cadre déclaré (B1 : biens, S1 : services, M1 : mixte)',
            'seller_electronic_address' => 'Adresse vendeur déclarée (BT-34)',
            'buyer_electronic_address' => 'Adresse client déclarée (BT-49)',
        ];
        $calculated = $data['totals'] !== [];
        $items = $calculated ? $data['items'] : $preview['stored_items'];
    @endphp
    <div class="min-h-screen bg-slate-50 py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('invoices.show', $preview['invoice_id']) }}" class="text-indigo-700 underline">Retour au brouillon #{{ $preview['invoice_id'] }}</a>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 space-y-3">
                <h3 class="text-lg font-semibold text-amber-950">Simulation documentaire du brouillon</h3>
                <p class="text-sm text-amber-950">Projection en lecture seule des fiches actuelles et des lignes enregistrées. Ces informations ne sont pas figées et ne remplacent aucun instantané. Aucun numéro définitif n’est attribué.</p>
                <ul class="list-disc pl-5 font-semibold text-amber-950">
                    <li>Document CII non produit.</li>
                    <li>Conformité électronique non établie.</li>
                    <li>Envoi électronique interdit.</li>
                </ul>
            </section>
            <section class="rounded-2xl border bg-white p-6 space-y-3" aria-label="Identité sandbox distincte">
                <h3 class="text-lg font-semibold text-gray-900">Identité SUPER PDP — sandbox uniquement</h3>
                <p class="font-mono">{{ $preview['sandbox']['superpdp_identity']['number_scheme'] }} / {{ $preview['sandbox']['superpdp_identity']['number'] }}</p>
                <p class="text-sm text-gray-700">Entreprise locale 4 · propriétaire 4. Identité vérifiée dans la connexion OAuth locale enregistrée ; aucune vérification distante effectuée. Cet identifiant n’est ni un SIRET, ni un SIREN légal, ni un numéro de TVA. Il ne prouve ni routage ni publication qualifiée.</p>
            </section>
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach(['seller' => 'Vendeur', 'buyer' => 'Client'] as $party => $title)
                    <section class="rounded-2xl border bg-white p-6">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
                        <dl class="mt-4 space-y-3 text-sm text-gray-700">
                            @foreach($partyFields as $field => $label)
                                @continue(($party === 'seller' && in_array($field, ['company_name', 'full_name', 'client_type'])) || ($party === 'buyer' && in_array($field, ['name', 'legal_status', 'payment_terms'])))
                                <div><dt class="font-medium">{{ $label }}</dt><dd class="break-words">{{ $display($data[$party][$field] ?? null) }}</dd></div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
            <section class="rounded-2xl border bg-white p-6">
                <h3 class="text-lg font-semibold text-gray-900">Contexte électronique déclaré</h3>
                <p class="mt-2 text-sm text-gray-600">Les adresses restent affichées telles que déclarées, même lorsqu’elles sont refusées par les contrôles de production.</p>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="font-medium">Date du brouillon</dt><dd>{{ $display($data['date']) }}</dd></div>
                    <div><dt class="font-medium">Échéance</dt><dd>{{ $display($data['due_date']) }}</dd></div>
                    <div><dt class="font-medium">Régime de TVA déclaré</dt><dd>{{ $display($data['company_vat_regime'] ?? null) }}</dd></div>
                    @foreach($contextLabels as $field => $label)
                        <div><dt class="font-medium">{{ $label }}</dt><dd class="break-words">{{ $display($preview['declared_context'][$field]) }}</dd></div>
                    @endforeach
                    @foreach(['PMT' => 'Indemnité de recouvrement', 'PMD' => 'Pénalités de retard', 'AAB' => 'Escompte'] as $code => $label)
                        <div><dt class="font-medium">{{ $label }}</dt><dd class="break-words">{{ $display($data['company_invoice_mentions'][$code] ?? null) }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="overflow-hidden rounded-2xl border bg-white">
                <h3 class="p-6 text-lg font-semibold text-gray-900">Lignes et montants {{ $calculated ? 'calculés' : 'enregistrés — calcul indisponible' }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-100"><tr>
                            @foreach(['Description', 'Quantité', 'Prix unitaire HT', 'TVA (%)', 'Catégorie TVA', 'Unité', 'Montant HT (EUR)'] as $label)
                                <th scope="col" class="p-3">{{ $label }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @forelse($items as $item)
                                <tr class="border-t">
                                    @foreach(['description', 'quantity', 'unit_price_ht', 'tva_rate', 'vat_category', 'unit_code', 'line_total_ht'] as $field)
                                        <td class="p-3">{{ $display($item[$field] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-4">Aucune ligne enregistrée.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <dl class="grid gap-4 p-6 sm:grid-cols-3">
                    @foreach(['subtotal_ht' => 'Total HT calculé', 'total_tva' => 'TVA calculée', 'total_ttc' => 'Total TTC calculé'] as $field => $label)
                        <div><dt class="text-sm text-gray-600">{{ $label }}</dt><dd class="font-semibold">{{ $calculated ? $data['totals'][$field].' EUR' : 'Indisponible' }}</dd></div>
                    @endforeach
                </dl>
                <p class="px-6 pb-6 text-sm text-gray-600">Calcul décimal à partir des lignes, sans correction des montants enregistrés. Les écarts éventuels sont signalés ci-dessous.</p>
            </section>
            <section class="rounded-2xl border border-amber-200 bg-white p-6">
                <h3 class="text-lg font-semibold text-gray-900">Données manquantes et contrôles bloquants</h3>
                <p class="mt-2 text-sm text-gray-600">Les exigences du parcours électronique de production restent applicables et inchangées. Cet aperçu permet de consulter les données malgré ces blocages.</p>
                <ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-amber-950">
                    @foreach($preview['blockers'] as $blocker)
                        <li>{{ $blocker['message'] }}</li>
                    @endforeach
                </ul>
                <p class="mt-4 font-semibold text-amber-950">Routage non vérifié. Aucun envoi n’est autorisé par cet aperçu.</p>
            </section>
        </div>
    </div>
</x-app-layout>
