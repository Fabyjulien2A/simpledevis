<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Pure invoice form translation shared with isolated diagnostics. */
final class InvoiceFiscalItemTranslator
{
    public function translate(array $items): array
    {
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.tax_treatment' => ['required', 'string', 'in:taxable,franchise,exempt,zero_rated'],
        ])->validate();

        $fiscalErrors = [];
        foreach ($items as $index => &$item) {
            $category = match ($item['tax_treatment']) {
                'taxable' => 'S',
                'franchise', 'exempt' => 'E',
                'zero_rated' => 'Z',
            };
            if (array_key_exists('vat_category', $item) && $item['vat_category'] !== $category) {
                $fiscalErrors["items.$index.vat_category"] = 'La catégorie fiscale ne correspond pas au traitement choisi.';
            }
            if ($item['tax_treatment'] === 'franchise') {
                if (isset($item['exemption_reason_code']) && $item['exemption_reason_code'] !== ''
                    && $item['exemption_reason_code'] !== 'VATEX-FR-FRANCHISE') {
                    $fiscalErrors["items.$index.exemption_reason_code"] = 'La franchise en base exige le code VATEX-FR-FRANCHISE.';
                }
                $item['exemption_reason_code'] = 'VATEX-FR-FRANCHISE';
            } elseif ($item['tax_treatment'] === 'exempt'
                && ($item['exemption_reason_code'] ?? null) === 'VATEX-FR-FRANCHISE') {
                $fiscalErrors["items.$index.tax_treatment"] = 'Sélectionnez Franchise en base pour ce motif.';
            }
            $item['vat_category'] = $category;
        }
        unset($item);

        if ($fiscalErrors !== []) {
            throw ValidationException::withMessages($fiscalErrors);
        }

        return $items;
    }
}
