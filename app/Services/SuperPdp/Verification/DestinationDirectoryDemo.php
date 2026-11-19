<?php

namespace App\Services\SuperPdp\Verification;

use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Saloon\Http\Faking\MockResponse;

/** Réponses SDK explicitement simulées, jamais qualifiantes. Aucun accès réseau. */
final class DestinationDirectoryDemo
{
    public static function responses(string $scenario, string $siren): array
    {
        $entry = ['company' => ['number' => $siren, 'formal_name' => 'Entreprise de démonstration',
            'address' => 'Adresse de démonstration', 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR'],
            'identifier' => '0225:'.$siren, 'is_active' => true];
        $rows = match ($scenario) {
            'match' => [$entry], 'absent' => [],
            'inactive' => [array_replace($entry, ['is_active' => false])],
            'ambiguous' => [$entry, $entry],
            'incompatible' => [array_replace($entry, ['identifier' => '0225:'.$siren.'_SERVICE'])],
            default => [],
        };

        return [ListFrenchDirectoryEntriesRequest::class => MockResponse::make(
            ['data' => $rows], in_array($scenario, ['match', 'absent', 'inactive', 'ambiguous', 'incompatible'], true) ? 200 : 503,
        )];
    }
}
