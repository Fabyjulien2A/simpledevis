<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $deletionError = DB::transaction(function () use ($user, $request) {
            // Même verrou utilisateur que la création définitive et la finalisation.
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->id === $request->user()->id, 403);
            $invoices = $lockedUser->invoices()->orderBy('id')
                ->lockForUpdate()->get(['id', 'document_status']);

            if ($invoices->contains('document_status', 'issued')) {
                return 'Votre compte ne peut pas être supprimé car il possède au moins une facture définitive.';
            }

            // Même ordre que la finalisation : utilisateur, factures clients, entreprises.
            // Verrouiller les entreprises avant la recherche des factures reçues.
            $companyIds = Company::where('user_id', $lockedUser->id)->orderBy('id')
                ->lockForUpdate()->pluck('id');
            if ($companyIds->isNotEmpty() && SupplierInvoice::whereIn('company_id', $companyIds)
                ->orderBy('id')->lockForUpdate()->first(['id']) !== null) {
                return 'Votre compte ne peut pas être supprimé car il possède au moins une facture fournisseur reçue.';
            }

            $lockedUser->delete();

            return null;
        });

        if ($deletionError !== null) {
            return Redirect::route('profile.edit')->withErrors([
                'account' => $deletionError,
            ], 'userDeletion');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
