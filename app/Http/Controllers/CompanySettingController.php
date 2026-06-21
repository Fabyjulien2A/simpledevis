<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CompanySettingController extends Controller
{
    public function edit(): View
    {
        $company = auth()->user()->company;

        return view('company.edit', compact('company'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Informations générales
            'company_name' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'legal_status' => ['nullable', 'string'],

            // Informations légales
            'siret' => ['nullable', 'string'],
            'tva_number' => ['nullable', 'string'],
            'vat_number' => ['nullable', 'string'],
            // Paiement
            'iban' => ['nullable', 'string'],
            'bic' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string'],

            // Paramètres devis
            'quote_validity' => ['nullable', 'string'],

            // Logo
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->filled('tva_number')) {
            $data['tva_number'] = strtoupper(str_replace(' ', '', $request->tva_number));
            $data['vat_number'] = strtoupper(str_replace(' ', '', $request->tva_number));
        }

        $company = auth()->user()->company;

        if ($request->hasFile('logo')) {

            if ($company && $company->logo) {
                Storage::disk('public')->delete($company->logo);
            }

            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        auth()->user()->company()->updateOrCreate(
            ['user_id' => auth()->id()],
            $data
        );

        return back()->with(
            'success',
            'Informations mises à jour'
        );
    }
}
