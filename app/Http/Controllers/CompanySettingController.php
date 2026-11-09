<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\IsoCountryCode;
use App\Services\SuperPdp\DeclaredBillingAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CompanySettingController extends Controller
{
    public function edit(): View
    {
        $company = auth()->user()->company()->first();

        return view('company.edit', compact('company'));
    }

    public function update(Request $request): RedirectResponse
    {
        // Le formulaire fournit tva_number ; vat_number reste son miroir interne.
        // Une clé absente ne signifie pas un effacement volontaire.
        if ($request->has('tva_number') && is_string($request->input('tva_number'))) {
            $number = strtoupper(preg_replace('/[\s\p{Z}]+/u', '', $request->input('tva_number')) ?? $request->input('tva_number'));
            $request->merge(['tva_number' => $number === '' ? null : $number]);
        }

        $data = $request->validate([
            // Informations générales
            'company_name' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'country_code' => ['nullable', new IsoCountryCode],
            ...DeclaredBillingAddress::rules(),
            'legal_status' => ['nullable', 'string'],

            // Informations légales
            'siret' => ['nullable', 'string'],
            // Contrôle de forme français seulement, sans contrôle de clé ou d'existence fiscale.
            'tva_number' => ['bail', 'nullable', 'string', 'size:13', 'regex:/\AFR[A-Z0-9]{2}[0-9]{9}\z/'],
            'vat_regime' => ['bail', 'nullable', 'string', 'in:subject_to_vat,franchise'],
            // Paiement
            'iban' => ['nullable', 'string'],
            'bic' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string'],
            // Mentions explicites, sans reprise des textes PDF ou des fixtures.
            'invoice_recovery_indemnity_text' => ['nullable', 'string', 'max:2000'],
            'invoice_late_payment_penalties_text' => ['nullable', 'string', 'max:2000'],
            'invoice_early_payment_discount_text' => ['nullable', 'string', 'max:2000'],

            // Paramètres devis
            'quote_validity' => ['nullable', 'string'],

            // Logo
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'invoice_recovery_indemnity_text.string' => 'La mention PMT doit être un texte ou être laissée vide.',
            'invoice_recovery_indemnity_text.max' => 'La mention PMT ne doit pas dépasser 2 000 caractères.',
            'invoice_late_payment_penalties_text.string' => 'La mention PMD doit être un texte ou être laissée vide.',
            'invoice_late_payment_penalties_text.max' => 'La mention PMD ne doit pas dépasser 2 000 caractères.',
            'invoice_early_payment_discount_text.string' => 'La mention AAB doit être un texte ou être laissée vide.',
            'invoice_early_payment_discount_text.max' => 'La mention AAB ne doit pas dépasser 2 000 caractères.',
            'tva_number.string' => 'Le numéro de TVA doit être un texte ou être laissé vide.',
            'tva_number.size' => 'Le numéro de TVA français doit contenir 13 caractères après suppression des espaces.',
            'tva_number.regex' => 'Utilisez le format français : FR, deux lettres ou chiffres, puis neuf chiffres.',
            'vat_regime.string' => 'Sélectionnez un régime de TVA dans la liste ou laissez-le non renseigné.',
            'vat_regime.in' => 'Sélectionnez Entreprise redevable de la TVA ou Franchise en base, ou laissez le régime non renseigné.',
        ]);

        if (array_key_exists('tva_number', $data)) {
            // Inclut null : vider le champ efface aussi le numéro utilisé par l'export.
            $data['vat_number'] = $data['tva_number'];
        }

        DB::transaction(function () use ($request, $data) {
            // Même premier verrou que la finalisation : utilisateur, puis entreprise.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $company = $user->company()->lockForUpdate()->first();
            if ($company !== null) {
                abort_unless($company->user_id === $user->id, 403);
            }
            if (array_key_exists('billing_electronic_address', $data) || array_key_exists('siret', $data)) {
                $data['billing_electronic_address'] = DeclaredBillingAddress::validate(
                    array_key_exists('billing_electronic_address', $data) ? $data['billing_electronic_address'] : $company?->billing_electronic_address,
                    array_key_exists('siret', $data) ? $data['siret'] : $company?->siret,
                );
            }
            $previousLogo = $company?->logo;

            if ($request->hasFile('logo')) {
                try {
                    $path = $request->file('logo')->store('logos', 'public');
                    if (! is_string($path) || $path === '') {
                        throw new \RuntimeException('Écriture du logo impossible.');
                    }
                    $data['logo'] = $path;
                } catch (Throwable $exception) {
                    throw ValidationException::withMessages(['logo' => 'Le nouveau logo n’a pas pu être enregistré. Le logo précédent est conservé.']);
                }
            }

            if ($company !== null) {
                $company->update($data);
            } else {
                $user->company()->create($data);
            }

            if (array_key_exists('logo', $data) && $previousLogo !== null && $previousLogo !== $data['logo']
                && preg_match('~\Alogos/[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+\z~D', $previousLogo)) {
                // Ne nettoyer que les anciens uploads publics, après le commit SQL.
                // Les archives privées ne passent jamais par ce chemin.
                DB::afterCommit(fn () => Storage::disk('public')->delete($previousLogo));
            }
        });

        return back()->with(
            'success',
            'Informations mises à jour'
        );
    }
}
