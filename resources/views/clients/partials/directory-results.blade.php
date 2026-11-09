@if(isset($directoryAnalysis) || isset($directoryError))
    <section aria-labelledby="directory-results-title" class="mt-6 space-y-4 border-t pt-6">
        <h3 id="directory-results-title" class="text-lg font-semibold text-gray-900">Consultation de l’annuaire</h3>
        <p class="text-sm text-gray-600">Une ligne active dans l’annuaire ne garantit pas la réception d’une facture. Aucune destination n’est sélectionnée ou enregistrée.</p>

        @if(isset($directoryError))
            <p role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $directoryError }}</p>
        @else
            <p class="text-sm text-gray-600">SIREN consulté : {{ $directorySiren }}. Résultats temporaires de cette consultation.</p>
            @if(($directoryAnalysis['pagination']['has_more'] ?? false) === true)
                <p role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Les résultats retournés sont incomplets. Toutes les lignes d’annuaire ne sont pas affichées.</p>
            @endif

            @if($directoryAnalysis['lines'] === [])
                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700">Aucune ligne retournée pour cette consultation. Cela ne prouve pas l’absence de destination.</p>
            @else
                @php
                    $states = [
                        'active_candidate' => ['Active — format simple compatible', 'bg-green-50 text-green-900'],
                        'inactive' => ['Inactive', 'bg-gray-100 text-gray-800'],
                        'unsupported_format' => ['Format non pris en charge', 'bg-amber-50 text-amber-900'],
                        'invalid_identifier' => ['Données invalides ou incohérentes', 'bg-red-50 text-red-900'],
                    ];
                    $assessments = [
                        'compatible' => 'Format simple compatible',
                        'unsupported' => 'Format non pris en charge actuellement ; cela ne signifie pas que la destination est inexistante.',
                        'invalid' => 'Identifiant invalide, incohérent ou réservé à un retour technique.',
                    ];
                @endphp
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Lignes retournées dans l’ordre de l’annuaire</caption>
                        <thead><tr class="border-b"><th scope="col" class="p-3">Identifiant retourné</th><th scope="col" class="p-3">Activité déclarée</th><th scope="col" class="p-3">Analyse</th></tr></thead>
                        <tbody>
                            @foreach($directoryAnalysis['lines'] as $row)
                                @php
                                    [$label, $color] = $states[$row['classification']];
                                @endphp
                                <tr class="border-b align-top">
                                    <td class="p-3"><code class="whitespace-pre-wrap break-all">{{ $row['line']['identifier'] }}</code></td>
                                    <td class="p-3">{{ $row['line']['is_active'] ? 'Active' : 'Inactive' }}</td>
                                    <td class="p-3"><span class="inline-block rounded px-2 py-1 {{ $color }}">{{ $label }}</span><p class="mt-2 text-gray-600">{{ $assessments[$row['identifier_status']] }}</p></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </section>
@endif
