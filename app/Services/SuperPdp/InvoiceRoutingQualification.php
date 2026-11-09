<?php

namespace App\Services\SuperPdp;

/** Qualification de réponses d'annuaire ; une simulation n'est jamais une vérification réelle. */
final class InvoiceRoutingQualification
{
    public function assess(array $data, array $reply): array
    {
        $context = InvoiceEmissionContext::resolve($data, []);
        $siret = $data['buyer']['siret'] ?? '';
        $siren = FrenchSellerIdentifiers::sirenFromSiret($siret);
        $result = [
            'status' => 'not_verified', 'qualified_for_simulation' => false, 'reason' => null,
            'source' => 'superpdp_directory_simulation',
            'buyer_siret' => $siret, 'buyer_siren' => $siren,
            'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
            'response_sha256' => hash('sha256', json_encode($reply, JSON_THROW_ON_ERROR)),
            'destination' => null,
        ];
        $refuse = fn (string $reason) => array_replace($result, ['reason' => $reason]);
        if (($data['buyer']['country_code'] ?? null) !== 'FR' || ($data['buyer']['client_type'] ?? null) !== 'professional') {
            return $refuse('unsupported_buyer');
        }
        if (! isset($reply['data']) || ! is_array($reply['data']) || ! array_is_list($reply['data'])
            || array_diff(array_keys($reply), ['data', 'has_more']) !== []) {
            return $refuse('incomplete_or_unknown_envelope');
        }
        // Le contrat local dit "list all" et ne définit pas de pagination pour cet endpoint.
        // Toute indication explicite de résultat partiel prime sur cette garantie contractuelle.
        if (array_key_exists('has_more', $reply) && $reply['has_more'] !== false) {
            return $refuse('partial_response');
        }
        $analysis = (new FrenchDirectoryEntriesAnalysis)->analyze($siren, $reply['data']);
        $candidates = [];
        foreach ($analysis['lines'] as $line) {
            if ($line['identifier_status'] === 'invalid') {
                return $refuse('invalid_or_foreign_entry');
            }
            if ($line['classification'] === 'unsupported_format') {
                return $refuse('unsupported_active_address');
            }
            if ($line['classification'] === 'active_candidate') {
                if (($line['line']['company']['country'] ?? null) !== 'FR') {
                    return $refuse('directory_country_mismatch');
                }
                $candidates[] = $line['bt49_candidate'];
            }
        }
        if (count($candidates) !== 1) {
            return $refuse($candidates === [] ? 'no_active_destination' : 'ambiguous_destinations');
        }
        $candidate = $candidates[0];
        $declared = $context['buyer_electronic_address'] ?? null;
        if (! is_array($declared) || ($declared['scheme'] ?? null) !== $candidate->scheme
            || ($declared['value'] ?? null) !== $candidate->value) {
            return $refuse('declared_bt49_not_matched');
        }

        return array_replace($result, [
            'qualified_for_simulation' => true, 'reason' => 'unique_active_exact_match_simulated',
            'completeness' => array_key_exists('has_more', $reply) ? 'explicit_complete' : 'complete_by_local_contract',
            'destination' => ['identifier' => $candidate->originalIdentifier, 'scheme' => $candidate->scheme, 'value' => $candidate->value],
        ]);
    }
}
