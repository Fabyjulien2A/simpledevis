@php
    $usesPartySnapshot = $invoice->getRawOriginal('party_snapshot') !== null;
    // Présentation seulement : les catégories, codes et résultats viennent du service.
    $categories = [
        'missing_data' => 'Informations manquantes',
        'invalid_data' => 'Informations à corriger',
        'unsupported' => 'Cas non pris en charge',
        'verification_unavailable' => 'Vérifications indisponibles',
    ];
    $messages = [
        'context.document_kind.missing' => 'La nature de la facture (facture ordinaire ou acompte) n’est pas renseignée pour la facturation électronique.',
        'context.type_code.missing' => 'Le type de document électronique n’est pas renseigné.',
        'context.currency_code.missing' => 'La devise n’est pas enregistrée pour la facturation électronique. Les montants affichés en euros ne suffisent pas à la confirmer.',
        'context.vat_regime.missing' => 'Régime de TVA non renseigné dans les paramètres de votre entreprise.',
        'invoice.invoice_number.missing' => 'La facture doit avoir un numéro définitif.',
        'invoice.date.missing' => 'La date de la facture doit être renseignée.',
        'invoice.calculation_version.missing' => 'Cette facture utilise un ancien calcul qui n’a pas été vérifié pour la facturation électronique.',
        'invoice.calculation_version.unsupported' => 'La méthode de calcul de cette facture n’est pas prise en charge pour la facturation électronique.',
        'invoice.payments.unsupported' => 'Un règlement ou un acompte est enregistré. Ce cas n’est pas encore pris en charge.',
        'invoice.credit_notes.unsupported' => 'Un avoir définitif est associé à cette facture. Ce cas n’est pas encore pris en charge.',
        'invoice.settlement.unsupported' => 'Cette facture est payée, partiellement payée ou présente un montant déjà réglé. Ce cas n’est pas encore pris en charge.',
        'routing.verification_unavailable' => 'La destination électronique du client ne peut pas encore être vérifiée dans SimpleDevis. Son SIRET, son adresse e-mail ou une connexion SUPER PDP ne prouvent pas que la facture peut lui être acheminée.',
        'seller.tax_registration_identifier.missing' => 'L’identifiant fiscal de votre entreprise ne peut pas être préparé. Vérifiez le SIRET et les informations de TVA dans les paramètres de votre entreprise.',
    ];
    $fields = [
        'address' => 'l’adresse postale',
        'postal_code' => 'le code postal',
        'city' => 'la ville',
        'country_code' => 'le pays',
        'siret' => 'le SIRET',
    ];
@endphp

<section aria-labelledby="emission-diagnostic-title" class="rounded-3xl border bg-white p-6 shadow-lg">
    <h4 id="emission-diagnostic-title" class="text-lg font-semibold text-gray-900">Diagnostic de facturation électronique</h4>
    <p class="mt-2 text-sm text-gray-600">Consultation uniquement pour une facture entre professionnels en France via SUPER PDP.</p>
    <p class="mt-2 text-sm text-gray-600">La présence des mentions enregistrées dans votre entreprise ne valide pas leur contenu juridique.</p>
    @if($usesPartySnapshot)
        <p class="mt-2 text-sm text-gray-600">Les données vendeur et acheteur proviennent de l’instantané de la facture. Modifier les fiches actuelles ne complète pas cet instantané.</p>
    @endif

    <div class="mt-4 rounded-2xl bg-gray-50 p-4">
        <p class="font-semibold text-gray-900">{{ $diagnostic['local_checks_passed'] ? 'Contrôles locaux réussis' : 'Contrôles locaux à compléter' }}</p>
        <p class="mt-1 text-sm text-gray-700">{{ $diagnostic['eligible'] ? 'Aucun blocage signalé par le diagnostic.' : 'Des blocages empêchent actuellement la préparation électronique.' }}</p>
        <p class="mt-2 text-sm text-gray-700">Ce diagnostic ne vaut ni autorisation d’émettre, ni validation réglementaire complète. La génération d’un XML ne prouve pas que la facture peut être transmise.</p>
    </div>

    <p class="mt-4 text-sm text-gray-600">Les informations absentes ne sont pas déduites automatiquement. Certaines données de facturation électronique ne peuvent pas encore être renseignées dans SimpleDevis.</p>

    @foreach(collect($diagnostic['blockers'])->groupBy('category') as $category => $blockers)
        <div class="mt-5">
            <h5 class="font-semibold text-gray-900">{{ $categories[$category] ?? 'Autres blocages' }}</h5>
            <ul class="mt-2 list-disc space-y-2 pl-5 text-sm text-gray-700">
                @php
                    $displayedMessages = [];
                @endphp
                @foreach($blockers as $blocker)
                    @php
                        $message = $usesPartySnapshot && in_array($blocker['code'], ['context.vat_regime.missing', 'seller.tax_registration_identifier.missing'], true)
                            ? $blocker['message'] : ($messages[$blocker['code']] ?? $blocker['message']);
                        foreach (['seller' => 'de votre entreprise', 'buyer' => 'du client'] as $party => $label) {
                            foreach ($fields as $field => $fieldLabel) {
                                if ($blocker['code'] === $party.'.'.$field.'.missing') {
                                    $message = $usesPartySnapshot
                                        ? 'L’instantané de la facture ne renseigne pas '.$fieldLabel.' '.$label.'.'
                                        : 'Renseignez '.$fieldLabel.' '.$label.'.';
                                }
                            }
                            if (str_starts_with($blocker['code'], $party.'.electronic_address.') && $blocker['category'] === 'missing_data') {
                                $message = 'L’adresse de facturation électronique '.$label.' est incomplète ou absente. Il s’agit d’une adresse de facturation dédiée, distincte de l’e-mail de contact.';
                            }
                        }
                        if ($blocker['code'] === 'french_context.invalid') {
                            $message = match ($blocker['field']) {
                                'french_context.business_process_type' => 'Le cadre de facturation (biens, services ou activité mixte) est manquant ou invalide.',
                                default => 'Les mentions de facturation (pénalités de retard, indemnité de recouvrement et escompte) sont manquantes ou invalides.',
                            };
                            if ($blocker['field'] === 'french_context') {
                                $message = 'Le cadre de facturation (biens, services ou activité mixte) et les mentions de paiement ne sont pas disponibles pour ce diagnostic.';
                            }
                        }
                    @endphp
                    @continue(in_array($message, $displayedMessages, true))
                    @php
                        $displayedMessages[] = $message;
                    @endphp
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach
</section>
