@php
    $destinationLabels = [
        'qualified' => 'Destination qualifiée', 'absent' => 'Destination absente de la réponse de l’annuaire',
        'ambiguous' => 'Destination ambiguë', 'inactive' => 'Destination inactive',
        'incompatible' => 'Destination incompatible avec le périmètre pris en charge',
        'unavailable' => 'Vérification indisponible', 'expired' => 'Preuve expirée',
    ];
    $reasonLabels = [
        'live_reads_disabled' => 'Les consultations réelles sont désactivées. Aucun appel API n’a été effectué.',
        'unique_active_exact_match_simulated' => ($destinationReport['source'] ?? null) === 'live'
            ? 'Une destination active unique correspond exactement à l’adresse figée sur cette facture.'
            : 'La réponse simulée correspond à l’adresse figée. Elle ne constitue pas une qualification réelle.',
        'no_active_destination' => 'Aucune destination active n’a été trouvée. Cela ne prouve pas que l’entreprise n’existe pas.',
        'ambiguous_destinations' => 'Plusieurs lignes actives ont été retournées, éventuellement en doublon. Aucun choix automatique n’a été effectué.',
        'declared_bt49_not_matched' => 'L’adresse trouvée ne correspond pas à l’adresse figée de la facture. Cette dernière n’a pas été modifiée.',
        'unsupported_active_address' => 'Une adresse active utilise un format non pris en charge. Seules les adresses 0225 simples sont couvertes.',
        'invalid_or_foreign_entry' => 'La réponse contient une adresse invalide, technique ou ne correspondant pas au destinataire.',
        'directory_country_mismatch' => 'Le pays du destinataire dans l’annuaire ne correspond pas au périmètre France.',
        'partial_response' => 'La réponse de l’annuaire est incomplète. Une destination ne peut pas être qualifiée.',
        'frozen_data_or_address_not_supported' => 'Les données figées sont absentes, invalides ou utilisent une adresse autre que 0225 suivie du SIREN, sans suffixe.',
        'evidence_expired' => 'Le délai de validité est dépassé. Relancez explicitement la vérification.',
        'invoice_changed_during_verification' => 'Les données de la facture ont changé pendant le contrôle. Relancez la vérification.',
        'invoice_source_mismatch' => 'Le rapport ne correspond plus aux données de la facture. Relancez la vérification.',
        'report_unreadable' => 'Le dernier rapport privé est absent, illisible ou incohérent. La vérification reste non acquise.',
    ];
@endphp
<section class="rounded-3xl border bg-white p-6 shadow-lg" aria-labelledby="destination-verification-title">
    <h3 id="destination-verification-title" class="text-lg font-semibold text-gray-900">Vérification de la destination électronique</h3>
    @if(session('routing_error'))
        <p class="mt-3 text-sm text-red-800">{{ session('routing_error') }}</p>
    @endif
    @if($destinationReport)
        <p class="mt-3 font-semibold text-gray-900">{{ $destinationLabels[$destinationReport['status']] ?? 'Vérification indisponible' }}</p>
        @if(($destinationReport['source'] ?? null) === 'simulated')
            <p class="mt-2 text-sm font-semibold text-amber-900">Simulation — aucune qualification réelle et aucun appel réseau.</p>
        @elseif(($destinationReport['source'] ?? null) === 'cached')
            <p class="mt-2 text-sm text-amber-900">Réponse issue du cache — elle ne constitue pas une preuve réelle récente.</p>
        @endif
        <p class="mt-2 text-sm text-gray-700">{{ $reasonLabels[$destinationReport['reason']] ?? 'Le contrôle n’a pas permis de valider la destination. Consultez la configuration ou réessayez ultérieurement.' }}</p>
        <dl class="mt-3 space-y-1 text-sm text-gray-600">
            <div><dt class="inline font-medium">Dernière vérification :</dt> <dd class="inline">{{ $destinationReport['checked_at'] ?? 'Indisponible' }}</dd></div>
            <div><dt class="inline font-medium">SIREN recherché :</dt> <dd class="inline">{{ $destinationReport['buyer_siren'] ?? 'Indisponible' }}</dd></div>
            <div><dt class="inline font-medium">Provenance :</dt> <dd class="inline">{{ match($destinationReport['source'] ?? null) { 'live' => 'Consultation réelle de l’annuaire SUPER PDP', 'simulated' => 'Réponse de démonstration', 'cached' => 'Cache', default => 'Aucune consultation distante' } }}</dd></div>
        </dl>
    @else
        <p class="mt-3 text-sm text-gray-700">Aucune vérification de destination enregistrée pour cette facture.</p>
    @endif
    <p class="mt-3 text-sm text-gray-600">Validité locale : {{ $destinationValidity }} minutes. Ce délai est un choix de sécurité SimpleDevis, pas une exigence officielle. Les données figées restent inchangées.</p>
    <form method="POST" action="{{ route('invoices.verify-destination', $invoice) }}" class="mt-4">
        @csrf
        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white">Vérifier la destination électronique</button>
    </form>
</section>
