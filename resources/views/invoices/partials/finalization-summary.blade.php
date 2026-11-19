@php
    $data = $summary['data'];
    $rows = [
        'Entreprise' => [
            'Nom' => $data['seller']['name'] ?? null,
            'SIRET' => $data['seller']['siret'] ?? null,
            'Adresse' => $data['seller']['address'] ?? null,
            'Code postal' => $data['seller']['postal_code'] ?? null,
            'Ville' => $data['seller']['city'] ?? null,
            'Pays vendeur' => $data['seller']['country_code'] ?? null,
            'Régime de TVA déclaré' => match($data['company_vat_regime'] ?? null) {
                'subject_to_vat' => 'Entreprise redevable de la TVA', 'franchise' => 'Franchise en base', default => null,
            },
            'Numéro de TVA (selon régime)' => $data['seller']['vat_number'] ?? null,
            'Indemnité de recouvrement' => $data['company_invoice_mentions']['PMT'] ?? null,
            'Pénalités de retard' => $data['company_invoice_mentions']['PMD'] ?? null,
            'Escompte' => $data['company_invoice_mentions']['AAB'] ?? null,
            'Conditions de paiement (ou échéance sur la facture)' => $data['seller']['payment_terms'] ?? null,
            'Adresse électronique vendeur déclarée' => isset($summary['context']['seller_electronic_address'])
                ? implode(' / ', $summary['context']['seller_electronic_address']) : null,
        ],
        'Client' => [
            'Nom' => ($data['buyer']['company_name'] ?? null) ?: ($data['buyer']['full_name'] ?? null),
            'Type de client' => match($data['buyer']['client_type'] ?? null) {
                'professional' => 'Professionnel', 'individual' => 'Particulier', default => null,
            },
            'SIRET' => $data['buyer']['siret'] ?? null,
            'Numéro de TVA déclaré' => $data['buyer']['vat_number'] ?? null,
            'Adresse' => $data['buyer']['address'] ?? null,
            'Code postal' => $data['buyer']['postal_code'] ?? null,
            'Ville' => $data['buyer']['city'] ?? null,
            'Pays client' => $data['buyer']['country_code'] ?? null,
            'Adresse électronique client déclarée' => isset($summary['context']['buyer_electronic_address'])
                ? implode(' / ', $summary['context']['buyer_electronic_address']) : null,
        ],
        'Facture' => [
            'Profil étudié' => 'Facture entre professionnels en France, en euros',
            'Nature et devise' => 'Facture ordinaire en euros — informations produites à la finalisation',
            'Date' => $data['date'] ?? null, 'Échéance' => $data['due_date'] ?? null,
            'Cadre de facturation' => match($data['french_business_process_type'] ?? null) {
                'B1' => 'Biens', 'S1' => 'Services', 'M1' => 'Biens et services', default => null,
            },
            'Total HT calculé (EUR)' => $data['totals']['subtotal_ht'] ?? null,
            'Total TVA calculé (EUR)' => $data['totals']['total_tva'] ?? null,
            'Total TTC calculé (EUR)' => $data['totals']['total_ttc'] ?? null,
            'Numéro définitif' => 'Attribué automatiquement à la finalisation',
        ],
    ];
    // Présentation uniquement : les catégories et blocages restent ceux du diagnostic.
    $statusFor = static function (array $blockers): string {
        $categories = array_column($blockers, 'category');
        if (in_array('unsupported', $categories, true)) {
            return 'Non pris en charge';
        }
        if (array_diff($categories, ['verification_unavailable']) !== []) {
            return 'À compléter';
        }
        return $categories === [] ? 'Complet' : 'Vérification indisponible';
    };
    $statusClasses = [
        'Complet' => 'bg-emerald-50 text-emerald-800',
        'À compléter' => 'bg-amber-50 text-amber-900',
        'Non pris en charge' => 'bg-red-50 text-red-800',
        'Vérification indisponible' => 'bg-slate-100 text-slate-700',
    ];
    $readableMessage = static function (array $blocker): string {
        foreach (['seller' => 'de votre entreprise', 'buyer' => 'du client'] as $party => $label) {
            foreach (['address' => 'l’adresse', 'postal_code' => 'le code postal', 'city' => 'la ville', 'country_code' => 'le pays', 'siret' => 'le SIRET'] as $field => $name) {
                if ($blocker['code'] === $party.'.'.$field.'.missing') {
                    return 'Renseignez '.$name.' '.$label.'.';
                }
            }
            if (str_starts_with($blocker['code'], $party.'.electronic_address.') && $blocker['category'] === 'missing_data') {
                return 'Complétez l’adresse de facturation électronique '.$label.' (type et adresse). L’e-mail de contact ne la remplace pas.';
            }
        }
        $prefix = '';
        if (preg_match('/^items\.(\d+)\.([a-z_]+)$/D', $blocker['field'], $parts)) {
            $fieldLabel = match($parts[2]) {
                'description' => 'description', 'quantity' => 'quantité', 'unit_price_ht' => 'prix unitaire HT',
                'tva_rate' => 'taux de TVA', 'unit_code' => 'unité', 'tax_treatment', 'vat_category' => 'traitement de TVA',
                'exemption_reason_code', 'exemption_reason' => 'motif d’exonération', 'line_total_ht' => 'montant HT',
                default => 'information à vérifier',
            };
            $prefix = 'Ligne '.((int) $parts[1] + 1).' — '.$fieldLabel.' : ';
            if ($blocker['code'] === 'lines.invalid' && $parts[2] === 'tax_treatment') {
                return $prefix.'choisissez un traitement de TVA parmi les choix proposés.';
            }
        }
        return $prefix.strtr($blocker['message'], [
            'invoice_recovery_indemnity_text' => 'indemnité de recouvrement',
            'invoice_late_payment_penalties_text' => 'pénalités de retard',
            'invoice_early_payment_discount_text' => 'escompte',
            'french_business_process_type' => 'cadre de facturation',
            'business_process_type' => 'cadre de facturation',
            'tax_registration_identifier' => 'identifiant fiscal',
            'exemption_reason_code' => 'motif d’exonération', 'exemption_reason' => 'motif d’exonération',
            'seller_electronic_address' => 'adresse de facturation électronique de votre entreprise',
            'buyer_electronic_address' => 'adresse de facturation électronique du client',
            'billing_electronic_address' => 'adresse de facturation électronique',
            'electronic_address' => 'adresse de facturation électronique',
            'calculation_version' => 'méthode de calcul', 'decimal-v1' => 'calcul décimal actuel',
            'currency_code' => 'devise', 'document_kind' => 'nature du document', 'type_code' => 'type de document',
            'invoice_number' => 'numéro de facture', 'due_date' => 'date d’échéance',
            'payment_terms' => 'conditions de paiement', 'amount_paid' => 'montant payé',
            'subtotal_ht' => 'total HT', 'total_tva' => 'total TVA', 'total_ttc' => 'total TTC',
            'line_total_ht' => 'montant HT de la ligne', 'unit_price_ht' => 'prix unitaire HT',
            'postal_code' => 'code postal', 'country_code' => 'pays', 'client_type' => 'type de client',
            'vat_regime' => 'régime de TVA', 'vat_number' => 'numéro de TVA', 'tva_rate' => 'taux de TVA',
            'vat_category' => 'traitement de TVA', 'tax_treatment' => 'traitement de TVA',
            'unit_code' => 'unité', 'quantity' => 'quantité', 'address' => 'adresse', 'city' => 'ville',
            'scheme et value' => 'le type d’adresse et sa valeur', 'scheme' => 'type d’adresse', 'value' => 'adresse',
            'french_context' => 'mentions de facturation', 'payload' => 'données électroniques',
            'B1, S1 ou M1' => 'biens, services ou biens et services',
            'B1' => 'biens', 'S1' => 'services', 'M1' => 'biens et services',
            'Pour la catégorie S' => 'Pour une ligne soumise à la TVA',
            'E et VATEX-FR-FRANCHISE' => 'le traitement « Franchise en base »',
            'VATEX-FR-FRANCHISE' => 'franchise en base de TVA',
            'BT-32' => 'L’identifiant fiscal',
            'PMT' => 'd’indemnité de recouvrement', 'PMD' => 'de pénalités de retard', 'AAB' => 'd’escompte',
        ]);
    };
@endphp
<section data-profile-compatible="{{ $summary['profileCompatible'] ? 'true' : 'false' }}" class="rounded-2xl border border-indigo-200 bg-white p-4 sm:p-6 space-y-4" aria-label="Récapitulatif avant finalisation électronique">
    <h3 class="text-lg font-semibold text-gray-900">Récapitulatif avant finalisation électronique</h3>
    <p class="text-sm text-gray-600">Ces prérequis concernent le profil électronique uniquement. Une facture PDF classique reste possible avec « Sans qualification électronique ».</p>
    <p class="text-sm text-gray-600">Consultez les détails si nécessaire. « Complet » concerne les informations contrôlées ici et ne signifie pas que la facture est prête à être envoyée.</p>
    <div class="grid items-start gap-3 sm:grid-cols-2">
        @foreach($rows as $group => $fields)
            @php($status = $statusFor($summary['groups'][$group]))
            <details data-summary-group="{{ $group }}" class="min-w-0 rounded-xl border border-gray-200">
                <summary class="cursor-pointer rounded-xl p-4 text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                    <span class="inline-flex max-w-full flex-wrap items-center gap-2 align-middle">
                        <span class="font-semibold">{{ $group }}</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses[$status] }}">{{ $status }}</span>
                    </span>
                </summary>
                <div class="space-y-3 border-t border-gray-100 p-4">
                @if($summary['groups'][$group] === [])
                    <p class="text-sm text-green-800">Aucun manque signalé par les contrôles de cet aperçu.</p>
                @else
                    @foreach(collect($summary['groups'][$group])->groupBy('category') as $category => $blockers)
                        <h5 class="text-sm font-semibold text-amber-900">{{ match($category) {
                            'missing_data' => 'Informations manquantes', 'unsupported' => 'Cas non pris en charge',
                            'verification_unavailable' => 'Contrôles non disponibles', default => 'Informations à corriger',
                        } }}</h5>
                        <ul class="list-disc break-words pl-5 text-sm text-amber-900">
                            @foreach(collect($blockers)->map($readableMessage)->unique() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endforeach
                    @if($group === 'Entreprise')
                        <a href="{{ route('company.edit') }}" target="_blank" rel="noopener" class="inline-block text-sm text-indigo-700 underline">Compléter les paramètres entreprise (nouvel onglet)</a>
                    @elseif($group === 'Client' && $client)
                        <a href="{{ route('clients.edit', $client) }}" target="_blank" rel="noopener" class="inline-block text-sm text-indigo-700 underline">Compléter la fiche client (nouvel onglet)</a>
                    @endif
                @endif
                <dl class="space-y-2 text-sm text-gray-700">
                    @foreach($fields as $label => $value)
                        <div><dt class="font-medium">{{ $label }}</dt><dd class="break-words">{{ filled($value) ? $value : 'Non renseigné' }}</dd></div>
                    @endforeach
                </dl>
                </div>
            </details>
        @endforeach
        <details data-summary-group="Routage" class="min-w-0 rounded-xl border border-gray-200">
            <summary class="cursor-pointer rounded-xl p-4 text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                <span class="inline-flex max-w-full flex-wrap items-center gap-2 align-middle">
                    <span class="font-semibold">Routage</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Vérification indisponible</span>
                </span>
            </summary>
            <div class="space-y-2 border-t border-gray-100 p-4 text-sm text-gray-700">
                @foreach($summary['unavailable'] as $blocker)
                    <p>{{ $readableMessage($blocker) }}</p>
                @endforeach
                <p>La destination électronique reste à vérifier, même si les autres rubriques sont complètes. Aucun envoi n’est activé.</p>
            </div>
        </details>
    </div>
</section>
