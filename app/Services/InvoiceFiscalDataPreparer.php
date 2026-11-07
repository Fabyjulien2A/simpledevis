<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Contrôle fiscal d'un instantané de données enregistrées, sans accès à la base,
 * rendu, réseau, calcul de règlement ni autorisation d'émission.
 * L'appelant fournit des lignes et montants décimaux issus de son stockage.
 */
class InvoiceFiscalDataPreparer
{
    public function __construct(private readonly InvoiceLinePreparationService $linePreparation)
    {
    }

    /**
     * Les historiques ne sont jamais qualifiés implicitement pour un export.
     * Les paiements, avoirs et montants dus relèvent d'un traitement distinct.
     *
     * @return array{items: array, vat_breakdowns: array, totals: array{subtotal_ht: string, total_tva: string, total_ttc: string}}
     */
    public function prepare(array $items, array $storedTotals, ?string $calculationVersion): array
    {
        if ($calculationVersion === null) {
            throw ValidationException::withMessages([
                'calculation_version' => 'Les données fiscales de cette facture historique ne sont pas vérifiées pour un export électronique. Aucune conversion automatique n’est effectuée.',
            ]);
        }
        if ($calculationVersion !== 'decimal-v1') {
            throw new InvalidArgumentException('Version de calcul inconnue : ' . $calculationVersion . '.');
        }

        // Déléguer les règles fiscales, les capacités et les arrondis existants.
        $prepared = $this->linePreparation->prepare($items);
        $errors = [];
        foreach ($items as $index => $item) {
            foreach (['quantity', 'unit_price_ht', 'tva_rate'] as $field) {
                $this->storedDecimal($item[$field], "items.$index.$field", $errors);
            }
            $amount = $this->storedDecimal($item['line_total_ht'] ?? null, "items.$index.line_total_ht", $errors);
            if ($amount !== null && ! $amount->isEqualTo($prepared['items'][$index]['line_total_ht'])) {
                $errors["items.$index.line_total_ht"] = 'Le montant HT enregistré ne correspond pas au calcul de cette ligne.';
            }
        }
        foreach (['subtotal_ht' => 'total_ht', 'total_tva' => 'total_vat', 'total_ttc' => 'total_ttc'] as $stored => $calculated) {
            $amount = $this->storedDecimal($storedTotals[$stored] ?? null, $stored, $errors);
            if ($amount !== null && ! $amount->isEqualTo($prepared['totals'][$calculated])) {
                $errors[$stored] = 'Le montant enregistré ne correspond pas aux lignes et à leur ventilation TVA.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Restituer les valeurs contrôlées du stockage, sans réécrire les textes
        // ni remplacer silencieusement des montants par leur recalcul.
        $fields = array_flip([
            'id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht',
            'vat_category', 'unit_code', 'exemption_reason_code', 'exemption_reason',
        ]);

        return [
            'items' => array_map(fn ($item) => array_intersect_key($item, $fields), $items),
            'vat_breakdowns' => $prepared['vat_breakdowns'],
            'totals' => array_intersect_key($storedTotals, array_flip(['subtotal_ht', 'total_tva', 'total_ttc'])),
        ];
    }

    private function storedDecimal(mixed $value, string $key, array &$errors): ?BigDecimal
    {
        if (! is_string($value) || ! preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            $errors[$key] = 'La valeur enregistrée doit être une chaîne décimale avec au maximum deux décimales, sans float.';
            return null;
        }

        return BigDecimal::of($value);
    }
}
