<?php

namespace App\Services\SuperPdp;

use InvalidArgumentException;

/** Contrôles fiscaux partagés du diagnostic et du builder pour la source historisée. */
final class FrenchInvoiceTaxRegime
{
    public function check(array &$data, array $context, array &$blockers): void
    {
        $start = count($blockers);
        $this->checkSource($data, $context, $blockers);
        if (isset($data['party_snapshot'])) {
            for ($index = $start; $index < count($blockers); $index++) {
                $blockers[$index]['message'] = str_replace(
                    ['dans les paramètres de votre entreprise', 'dans le profil de votre entreprise', 'le profil', 'du profil', 'de votre entreprise'],
                    ['dans l’instantané de la facture', 'dans l’instantané de la facture', 'l’instantané', 'de l’instantané', 'de l’instantané'],
                    $blockers[$index]['message'],
                );
            }
        }
    }

    private function checkSource(array &$data, array $context, array &$blockers): void
    {
        $regime = $data['company_vat_regime'] ?? null;
        if (array_key_exists('vat_regime', $context) && $context['vat_regime'] !== $regime) {
            $this->add($blockers, 'tax.regime.context_conflict', 'invalid_data', 'vat_regime',
                'Le régime de TVA fourni dans le contexte ne correspond pas à celui enregistré dans les paramètres de votre entreprise. Vérifiez ces informations ; le contexte ne remplace pas le profil.');
        }
        if ($this->missing($regime)) {
            $this->add($blockers, 'context.vat_regime.missing', 'missing_data', 'vat_regime', 'Régime de TVA non renseigné dans les paramètres de votre entreprise.');

            return;
        }
        if (! in_array($regime, ['subject_to_vat', 'franchise'], true)) {
            $this->add($blockers, 'tax.regime.unsupported', 'unsupported', 'vat_regime', 'Seuls un régime avec numéro de TVA ou la franchise explicitement qualifiée sont pris en charge.');

            return;
        }
        $vat = $data['seller']['vat_number'] ?? null;
        if ($regime === 'subject_to_vat') {
            foreach ($data['items'] ?? [] as $index => $item) {
                if (($item['exemption_reason_code'] ?? null) === 'VATEX-FR-FRANCHISE') {
                    $this->add($blockers, 'tax.regime.inconsistent', 'invalid_data', 'items.'.$index, 'Le code de franchise est incohérent avec le régime enregistré dans le profil de votre entreprise.');
                }
            }
        }
        if ($regime === 'subject_to_vat' || ! $this->missing($vat)) {
            if ($this->missing($vat)) {
                $this->add($blockers, 'seller.vat_number.missing', 'missing_data', 'seller.vat_number', 'Le numéro de TVA vendeur est requis pour ce régime.');
            } elseif (! is_string($vat) || ! preg_match('/^FR[A-Z0-9]+$/D', trim($vat))) {
                $this->add($blockers, 'seller.vat_number.invalid', 'invalid_data', 'seller.vat_number', 'Le numéro de TVA du vendeur français doit être exploitable et préfixé FR.');
            }
        }
        if ($regime === 'franchise') {
            foreach ($data['items'] ?? [] as $index => $item) {
                if (($item['vat_category'] ?? null) !== 'E' || ($item['exemption_reason_code'] ?? null) !== 'VATEX-FR-FRANCHISE') {
                    $this->add($blockers, 'tax.franchise.line_invalid', 'invalid_data', 'items.'.$index, 'La franchise explicitement qualifiée exige E et VATEX-FR-FRANCHISE ; aucune correction automatique n’est effectuée.');
                }
            }
            if ($this->missing($vat)) {
                $preparedIdentifier = $data['seller']['tax_registration_identifier'] ?? null;
                $identifier = $preparedIdentifier ?? (isset($data['party_snapshot']) ? null : ($context['tax_registration_identifier'] ?? null));
                if ($preparedIdentifier !== null && array_key_exists('tax_registration_identifier', $context)
                    && $context['tax_registration_identifier'] !== $preparedIdentifier) {
                    $this->add($blockers, 'seller.tax_registration_identifier.invalid', 'invalid_data', 'seller.tax_registration_identifier',
                        'Le BT-32 fourni dans le contexte contredit l’identifiant fiscal préparé depuis le SIRET de votre entreprise.');
                }
                $siren = null;
                $storedSiret = $data['seller']['siret'] ?? null;
                try {
                    $siren = FrenchSellerIdentifiers::sirenFromSiret(is_string($storedSiret) ? trim($storedSiret) : '');
                } catch (InvalidArgumentException $exception) {
                    if (! $this->missing($data['seller']['siret'] ?? null)) {
                        $this->add($blockers, 'seller.siret.invalid', 'invalid_data', 'seller.siret', $exception->getMessage());
                    }
                }
                if ($this->missing($identifier)) {
                    $this->add($blockers, 'seller.tax_registration_identifier.missing', 'missing_data', 'seller.tax_registration_identifier', 'BT-32 n’a pas pu être préparé pour la franchise sans numéro de TVA. Vérifiez le SIRET et les informations fiscales du profil.');
                } elseif (! is_string($identifier) || ! preg_match('/^[0-9]{9}$/D', $identifier)
                    || $siren === null || $identifier !== $siren) {
                    $this->add($blockers, 'seller.tax_registration_identifier.invalid', 'invalid_data', 'seller.tax_registration_identifier', 'BT-32 doit correspondre au SIREN vendeur pour cette franchise sans numéro de TVA.');
                } else {
                    // Copie locale seulement : conserver la source préparée, ou le contexte explicite cohérent.
                    $data['seller']['tax_registration_identifier'] = $identifier;
                }
            }
        }
    }

    private function missing(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/[^\s\p{Z}]/u', $value) !== 1);
    }

    private function add(array &$blockers, string $code, string $category, string $field, string $message): void
    {
        $blockers[] = compact('code', 'category', 'field', 'message');
    }
}
