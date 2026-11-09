<?php

namespace App\Services;

use App\Services\SuperPdp\FrenchSellerIdentifiers;
use InvalidArgumentException;

/** Projection locale du contrat historique, sans accès aux fiches ni qualification de routage. */
final class InvoiceElectronicPartyData
{
    public static function resolve(array $data, bool $persisted = false): array
    {
        // Les scénarios explicites sans métadonnée de stockage gardent leur contrat.
        if (! $persisted && ! array_key_exists('party_snapshot', $data)) {
            return $data;
        }
        $snapshot = InvoicePartySnapshot::fromJson($data['party_snapshot'] ?? null)->toArray();
        $seller = $snapshot['seller'];
        $parties = ['seller' => [], 'buyer' => []];
        foreach (['name', 'address', 'postal_code', 'city', 'email', 'phone', 'siret', 'vat_number', 'country_code', 'legal_status', 'iban', 'bic', 'payment_terms'] as $field) {
            $source = $field === 'name' && array_key_exists('company_name', $seller) ? 'company_name' : $field;
            if (array_key_exists($source, $seller)) {
                $parties['seller'][$field] = $seller[$source];
            }
        }
        // Lecture historique uniquement : aucun accesseur des profils actuels.
        foreach (['client_type', 'full_name', 'company_name', 'siret', 'vat_number', 'country_code', 'address', 'postal_code', 'city', 'email', 'phone'] as $field) {
            if (array_key_exists($field, $snapshot['buyer'])) {
                $parties['buyer'][$field] = $snapshot['buyer'][$field];
            }
        }
        if (trim((string) ($parties['buyer']['full_name'] ?? '')) === '') {
            $historicalName = trim(implode(' ', array_filter([
                trim((string) ($snapshot['buyer']['first_name'] ?? '')),
                trim((string) ($snapshot['buyer']['last_name'] ?? '')),
            ], fn ($value) => $value !== '')));
            if ($historicalName !== '') {
                $parties['buyer']['full_name'] = $historicalName;
            }
        }
        $mentions = [];
        foreach (['PMT' => 'invoice_recovery_indemnity_text', 'PMD' => 'invoice_late_payment_penalties_text', 'AAB' => 'invoice_early_payment_discount_text'] as $code => $field) {
            if (array_key_exists($field, $seller)) {
                $mentions[$code] = $seller[$field];
            }
        }
        unset($data['seller'], $data['buyer'], $data['company_vat_regime'], $data['company_invoice_mentions']);
        $data = array_merge($data, $parties, ['company_invoice_mentions' => $mentions]);
        if (array_key_exists('vat_regime', $seller)) {
            $data['company_vat_regime'] = $seller['vat_regime'];
        }
        // Mapping BT-32 existant, seulement à partir d'absences de TVA explicitement capturées.
        if (($seller['vat_regime'] ?? null) === 'franchise'
            && array_key_exists('vat_number', $seller) && array_key_exists('tva_number', $seller)
            && trim((string) $seller['vat_number']) === '' && trim((string) $seller['tva_number']) === '') {
            try {
                $data['seller']['tax_registration_identifier'] = FrenchSellerIdentifiers::sirenFromSiret(trim((string) ($seller['siret'] ?? '')));
            } catch (InvalidArgumentException) {
                // Conserver l'erreur de données, sans consulter une autre source.
            }
        }

        return $data;
    }
}
