<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
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

        $deleted = DB::transaction(function () use ($user, $request) {
            // Même verrou utilisateur que la création définitive et la finalisation.
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->id === $request->user()->id, 403);
            $invoices = $lockedUser->invoices()->orderBy('id')
                ->lockForUpdate()->get(['id', 'document_status']);

            if ($invoices->contains('document_status', 'issued')) {
                return false;
            }

            $lockedUser->delete();

            return true;
        });

        if (! $deleted) {
            return Redirect::route('profile.edit')->withErrors([
                'account' => 'Votre compte ne peut pas être supprimé car il possède au moins une facture définitive.',
            ], 'userDeletion');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
