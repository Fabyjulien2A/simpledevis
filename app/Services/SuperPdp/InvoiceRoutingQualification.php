<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use InvalidArgumentException;

/** Qualification de réponses d'annuaire ; une simulation n'est jamais une vérification réelle. */
final class InvoiceRoutingQualification
{
    /**
     * Matches a buyer declaration against the approved sandbox context only.
     * The Company must already be loaded by an authorized caller; no SQL or API.
     * A manual interface confirmation is never converted to a live routing proof.
     */
    public function assessSandbox(SandboxEmissionContext $context, Company $localCompany, array $buyer): array
    {
        $context->assertFor($localCompany);
        $declaration = $context->toArray();
        $expected = $declaration['buyer'];
        $identity = $buyer['superpdp_identity'] ?? null;
        $address = $buyer['electronic_address'] ?? null;
        $confirmation = $buyer['routing_confirmation'] ?? null;
        $value = is_array($address) ? ($address['value'] ?? null) : null;
        $checks = [
            'buyer_identity' => ($buyer['name'] ?? null) === $expected['name'] && is_array($identity)
                && count($identity) === 2
                && ($identity['number_scheme'] ?? null) === $expected['superpdp_identity']['number_scheme']
                && ($identity['number'] ?? null) === $expected['superpdp_identity']['number'],
            'buyer_peppol_address' => is_array($address) && count($address) === 2
                && ($address['scheme'] ?? null) === $expected['electronic_address']['scheme']
                && is_string($value) && ! str_contains(strtolower($value), '_replyto')
                && $value === $expected['electronic_address']['value'],
            'manual_confirmation' => is_array($confirmation) && count($confirmation) === 4
                && ($confirmation['source'] ?? null) === $expected['routing_confirmation']['source']
                && ($confirmation['status'] ?? null) === $expected['routing_confirmation']['status']
                && ($confirmation['verification'] ?? null) === $expected['routing_confirmation']['verification']
                && array_key_exists('api_verified', $confirmation) && $confirmation['api_verified'] === false,
        ];
        // Also reject missing/extra fields, invented legal IDs and any provenance override.
        $candidate = $declaration;
        $candidate['buyer'] = $buyer;
        try {
            $context->assertMatches($candidate);
            $checks['approved_declaration'] = true;
        } catch (InvalidArgumentException) {
            $checks['approved_declaration'] = false;
        }
        $matched = ! in_array(false, $checks, true);
        $reasons = array_keys(array_filter($checks, static fn (bool $check): bool => ! $check));

        return [
            'status' => $matched ? 'not_verified' : 'refused',
            'routing_status' => $matched ? 'operator_confirmed' : 'refused',
            'manual_confirmation_matched' => $matched,
            'reason' => $matched ? 'approved_interface_confirmation_without_live_api_evidence' : $reasons[0],
            'checks' => $checks, 'reasons' => $reasons,
            'source' => $expected['routing_confirmation']['source'],
            'confirmation_status' => $matched ? $expected['routing_confirmation']['status'] : null,
            'api_verified' => false, 'verified' => false,
            // No API response was observed by this pure qualification.
            'response_sha256' => null, 'observed_at' => null,
            'local_company_id' => $localCompany->getKey(), 'environment' => 'sandbox',
            'buyer_identity' => $expected['superpdp_identity'],
            'destination' => $matched ? [
                // Peppol is part of this specific approved profile, not inferred from an identity number.
                'directory' => 'peppol',
                'identifier' => $expected['electronic_address']['scheme'].':'.$expected['electronic_address']['value'],
                'scheme' => $expected['electronic_address']['scheme'], 'value' => $expected['electronic_address']['value'],
            ] : null,
            'qualified_for_emission' => false, 'ready_for_emission' => false,
            'pending_evidence' => ['orchestrator_live_buyer_destination_evidence'],
        ];
    }

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
