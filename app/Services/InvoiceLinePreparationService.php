<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InvoiceLinePreparationService
{
    private const MAX_AMOUNT = '99999999.99';

    public function __construct(private readonly InvoiceAmountCalculator $calculator)
    {
    }

    /**
     * Prépare des lignes pour le stockage sans écriture ni qualification fiscale implicite.
     * Les montants soumis par le navigateur ne sont jamais utilisés.
     *
     * @return array{items: list<array<string, string|null>>, vat_breakdowns: array, totals: array}
     * @throws ValidationException
     */
    public function prepare(array $items): array
    {
        if ($items === [] || ! array_is_list($items)) {
            throw ValidationException::withMessages(['items' => 'Fournissez une liste non vide de lignes de facture.']);
        }

        foreach ($items as &$item) {
            if (! is_array($item)) {
                continue;
            }

            // Un entier est convertible exactement ; un float ne l'est pas.
            foreach (['quantity', 'unit_price_ht', 'tva_rate'] as $field) {
                if (isset($item[$field]) && is_int($item[$field])) {
                    $item[$field] = (string) $item[$field];
                }
            }

            foreach (['description', 'exemption_reason_code', 'exemption_reason'] as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $item[$field] = trim($item[$field]);
                }
            }
        }
        unset($item);

        $validator = Validator::make(['items' => $items], [
            'items.*' => ['bail', 'required', 'array'],
            'items.*.description' => ['bail', 'required', 'string', 'max:255'],
            'items.*.quantity' => ['bail', 'required', $this->decimalRule(true, true)],
            'items.*.unit_price_ht' => ['bail', 'required', $this->decimalRule(false, true)],
            'items.*.tva_rate' => ['bail', 'required', $this->decimalRule(false, false)],
            'items.*.vat_category' => ['bail', 'required', 'string', Rule::in(['S', 'E', 'Z'])],
            'items.*.unit_code' => ['bail', 'required', 'string', Rule::in(['C62', 'HUR', 'MTR'])],
            'items.*.exemption_reason_code' => ['bail', 'nullable', 'string', 'max:255'],
            'items.*.exemption_reason' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 65535) {
                    $fail('Le motif d’exonération dépasse la capacité de stockage de 65 535 octets.');
                }
            }],
        ], [
            'required' => 'Ce champ est obligatoire.',
            'array' => 'Chaque ligne doit être un tableau de données.',
            'string' => 'Ce champ doit être un texte.',
            'max.string' => 'Ce champ ne doit pas dépasser :max caractères.',
            'items.*.vat_category.in' => 'Choisissez explicitement une catégorie fiscale S, E ou Z.',
            'items.*.unit_code.in' => 'Choisissez une unité autorisée : C62, HUR ou MTR.',
        ]);

        $validator->validate();

        $calculatorLines = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $rate = BigDecimal::of($item['tva_rate']);
            $category = $item['vat_category'];
            $code = $item['exemption_reason_code'] ?? null;
            $reason = $item['exemption_reason'] ?? null;
            $code = $code === '' ? null : $code;
            $reason = $reason === '' ? null : $reason;

            if ($category === 'S' && ! in_array((string) $rate->stripTrailingZeros(), ['20', '10', '5.5', '2.1'], true)) {
                $errors["items.$index.tva_rate"] = 'Pour la catégorie S, choisissez un taux de 20, 10, 5.5 ou 2.1 %.';
            } elseif ($category !== 'S' && ! $rate->isZero()) {
                $errors["items.$index.tva_rate"] = 'Les catégories E et Z exigent un taux nul.';
            }

            if ($category === 'E' && $code === null && $reason === null) {
                $errors["items.$index.exemption_reason"] = 'Renseignez un code ou un motif justifiant cette exonération.';
            } elseif ($category !== 'E') {
                foreach (['exemption_reason_code' => $code, 'exemption_reason' => $reason] as $field => $value) {
                    if ($value !== null) {
                        $errors["items.$index.$field"] = 'Un motif d’exonération ne peut être associé qu’à la catégorie E.';
                    }
                }
            }

            $calculatorLines[] = [
                'quantity' => $item['quantity'],
                'unit_price_ht' => $item['unit_price_ht'],
                'vat_rate' => $item['tva_rate'],
                'vat_category' => $category,
                'exemption_reason_code' => $code,
                'exemption_reason' => $reason,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $result = $this->calculator->calculate($calculatorLines);
        } catch (InvalidArgumentException $exception) {
            // Les erreurs transversales restent attachées à la liste, sans parser leur texte.
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }

        $preparedItems = [];

        foreach ($result['lines'] as $index => $line) {
            if (BigDecimal::of($line['line_total_ht'])->isGreaterThan(self::MAX_AMOUNT)) {
                $errors["items.$index.line_total_ht"] = 'Le montant HT de cette ligne dépasse 99 999 999,99 €.';
            }

            $preparedItems[] = [
                'description' => $items[$index]['description'],
                'quantity' => $line['quantity'],
                'unit_price_ht' => $line['unit_price_ht'],
                'tva_rate' => $line['vat_rate'],
                'vat_category' => $line['vat_category'],
                'unit_code' => $items[$index]['unit_code'],
                'exemption_reason_code' => $line['exemption_reason_code'],
                'exemption_reason' => $line['exemption_reason'],
                'line_total_ht' => $line['line_total_ht'],
            ];
        }

        foreach ($result['totals'] as $amount) {
            if (BigDecimal::of($amount)->isGreaterThan(self::MAX_AMOUNT)) {
                $errors['items'] = 'Les totaux de la facture dépassent la capacité de stockage de 99 999 999,99 €.';
                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['items' => $preparedItems, 'vat_breakdowns' => $result['vat_breakdowns'], 'totals' => $result['totals']];
    }

    private function decimalRule(bool $strictlyPositive, bool $checkCapacity): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($strictlyPositive, $checkCapacity): void {
            if (! is_string($value) || ! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
                $fail('Utilisez une chaîne décimale avec un point et au maximum deux décimales, sans exposant ni float.');
                return;
            }

            $decimal = BigDecimal::of($value);

            if ($strictlyPositive && $decimal->isZero()) {
                $fail('La quantité doit être strictement positive.');
            }

            if ($checkCapacity && $decimal->isGreaterThan(self::MAX_AMOUNT)) {
                $fail('La valeur dépasse la capacité de stockage de 99 999 999,99.');
            }
        };
    }
}
