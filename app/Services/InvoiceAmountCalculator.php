<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Calcul des factures positives, sans remise ni frais, sans accès à la base.
 * Les catégories et justifications sont fournies par l'appelant : ce service
 * ne détermine ni l'éligibilité à la franchise ni la mention légale applicable.
 */
class InvoiceAmountCalculator
{
    /**
     * @param list<array{
     *     quantity: string,
     *     unit_price_ht: string,
     *     vat_rate: string,
     *     vat_category: string,
     *     exemption_reason_code?: string|null,
     *     exemption_reason?: string|null
     * }> $lines
     * @return array{
     *     lines: list<array<string, string|null>>,
     *     vat_breakdowns: list<array<string, string|null>>,
     *     totals: array{total_ht: string, total_vat: string, total_ttc: string}
     * }
     */
    public function calculate(array $lines): array
    {
        if ($lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Les lignes doivent former une liste non vide.');
        }

        $calculatedLines = [];
        $groups = [];
        $totalHt = BigDecimal::of('0.00');

        foreach ($lines as $index => $line) {
            $label = 'Ligne ' . ($index + 1);

            if (! is_array($line)) {
                throw new InvalidArgumentException($label . ' : une ligne doit être un tableau.');
            }

            $quantity = $this->decimal($line['quantity'] ?? null, $label . ' : quantité');
            $unitPrice = $this->decimal($line['unit_price_ht'] ?? null, $label . ' : prix unitaire HT');
            $rate = $this->decimal($line['vat_rate'] ?? null, $label . ' : taux de TVA');

            if ($quantity->isZero()) {
                throw new InvalidArgumentException($label . ' : la quantité doit être strictement positive.');
            }

            $category = $line['vat_category'] ?? null;

            if (! in_array($category, ['S', 'E', 'Z'], true)) {
                throw new InvalidArgumentException($label . ' : une catégorie fiscale explicite S, E ou Z est obligatoire.');
            }

            $reasonCode = $this->optionalText($line, 'exemption_reason_code', $label);
            $reason = $this->optionalText($line, 'exemption_reason', $label);

            if ($category === 'S') {
                $supportedRate = false;

                foreach (['20', '10', '5.5', '2.1'] as $allowedRate) {
                    $supportedRate = $supportedRate || $rate->isEqualTo($allowedRate);
                }

                if (! $supportedRate) {
                    throw new InvalidArgumentException($label . ' : la catégorie S exige un taux de 20, 10, 5.5 ou 2.1 %.');
                }
            } elseif (! $rate->isZero()) {
                throw new InvalidArgumentException($label . ' : les catégories E et Z exigent un taux nul.');
            }

            if ($category === 'E') {
                if ($reasonCode === null && $reason === null) {
                    throw new InvalidArgumentException($label . ' : une exonération exige un code ou un texte justificatif explicite.');
                }
            } elseif ($reasonCode !== null || $reason !== null) {
                throw new InvalidArgumentException($label . ' : un motif d’exonération ne peut être associé qu’à la catégorie E.');
            }

            $normalizedRate = (string) $rate->toScale(2);
            $lineTotal = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
            $groupKey = $category . ':' . $normalizedRate;

            if (isset($groups[$groupKey])) {
                // Un groupe catégorie/taux ne doit pas perdre ou mélanger ses motifs.
                if ($groups[$groupKey]['exemption_reason_code'] !== $reasonCode
                    || $groups[$groupKey]['exemption_reason'] !== $reason) {
                    throw new InvalidArgumentException($label . ' : justifications différentes dans une même ventilation TVA.');
                }
            } else {
                $groups[$groupKey] = [
                    'vat_category' => $category,
                    'vat_rate' => $normalizedRate,
                    'taxable_amount' => BigDecimal::of('0.00'),
                    'exemption_reason_code' => $reasonCode,
                    'exemption_reason' => $reason,
                ];
            }

            $groups[$groupKey]['taxable_amount'] = $groups[$groupKey]['taxable_amount']->plus($lineTotal);
            $totalHt = $totalHt->plus($lineTotal);
            $calculatedLines[] = [
                'quantity' => (string) $quantity,
                'unit_price_ht' => (string) $unitPrice,
                'vat_category' => $category,
                'vat_rate' => $normalizedRate,
                'exemption_reason_code' => $reasonCode,
                'exemption_reason' => $reason,
                'line_total_ht' => (string) $lineTotal,
            ];
        }

        $breakdowns = [];
        $totalVat = BigDecimal::of('0.00');

        foreach ($groups as $group) {
            // Division par 100 et arrondi une seule fois, au niveau de la ventilation.
            $vat = $group['taxable_amount']->multipliedBy($group['vat_rate'])
                ->dividedBy('100', 2, RoundingMode::HalfUp);
            $totalVat = $totalVat->plus($vat);
            $breakdowns[] = [
                'vat_category' => $group['vat_category'],
                'vat_rate' => $group['vat_rate'],
                'taxable_amount' => (string) $group['taxable_amount'],
                'vat_amount' => (string) $vat,
                'exemption_reason_code' => $group['exemption_reason_code'],
                'exemption_reason' => $group['exemption_reason'],
            ];
        }

        return [
            'lines' => $calculatedLines,
            'vat_breakdowns' => $breakdowns,
            'totals' => [
                'total_ht' => (string) $totalHt,
                'total_vat' => (string) $totalVat,
                'total_ttc' => (string) $totalHt->plus($totalVat),
            ],
        ];
    }

    private function decimal(mixed $value, string $label): BigDecimal
    {
        if (! is_string($value) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException($label . ' doit être une chaîne décimale positive ou nulle, avec un point et sans exposant.');
        }

        return BigDecimal::of($value);
    }

    private function optionalText(array $line, string $key, string $label): ?string
    {
        $value = $line[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException($label . ' : ' . $key . ' doit être une chaîne de caractères.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
