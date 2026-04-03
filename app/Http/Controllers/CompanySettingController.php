<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Storage;

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
            'company_name' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'siret' => ['nullable', 'string'],
            'tva_number' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

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

        return back()->with('success', 'Informations mises à jour');
    }
}