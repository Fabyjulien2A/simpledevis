<?php

namespace App\Services\SuperPdp;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Déclaration de forme seulement : aucune preuve d'inscription ni de routage. */
final class DeclaredBillingAddress
{
    public static function rules(): array
    {
        return [
            'billing_electronic_address' => ['nullable', 'array:scheme,value'],
            'billing_electronic_address.scheme' => ['nullable', 'required_with:billing_electronic_address.value', 'string', 'in:0225,EM'],
            'billing_electronic_address.value' => ['nullable', 'required_with:billing_electronic_address.scheme', 'string', 'max:254'],
        ];
    }

    public static function validate(mixed $address, ?string $siret): ?array
    {
        $validated = Validator::make(['billing_electronic_address' => $address], self::rules())->validate();
        $address = $validated['billing_electronic_address'] ?? null;
        if ($address === null || (($address['scheme'] ?? null) === null && ($address['value'] ?? null) === null)) {
            return null;
        }
        $value = $address['value'];
        if ($address['scheme'] === 'EM') {
            Validator::make(['billing_electronic_address' => ['value' => $value]], [
                'billing_electronic_address.value' => ['email:rfc'],
            ])->validate();
        } else {
            $valid = preg_match('/\A[0-9]{9}(?:_[A-Za-z0-9][A-Za-z0-9._-]{0,99})?\z/D', $value) === 1;
            try {
                $valid = $valid && substr($value, 0, 9) === FrenchSellerIdentifiers::sirenFromSiret(trim((string) $siret));
            } catch (\InvalidArgumentException) {
                $valid = false;
            }
            if (! $valid) {
                throw ValidationException::withMessages(['billing_electronic_address.value' =>
                    'Une adresse 0225 doit commencer par le SIREN issu du SIRET renseigné, éventuellement suivi de _ et d’un suffixe. Ce contrôle ne valide pas le routage.']);
            }
        }

        return ['scheme' => $address['scheme'], 'value' => $value];
    }
}
