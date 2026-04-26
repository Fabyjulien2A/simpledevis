<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse|Checkout
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'plan' => ['nullable', 'in:pro'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        // Si l'utilisateur a choisi le plan Pro avant l'inscription,
        // on le redirige directement vers Stripe Checkout
        if ($request->plan === 'pro') {
            $priceId = env('STRIPE_PRICE_PRO');

            if ($priceId) {
                return $user->newSubscription('default', $priceId)
                    ->checkout([
                        'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => route('billing.cancel'),
                    ]);
            }
        }

        // Sinon inscription classique -> plan gratuit
        return redirect(route('dashboard', absolute: false));
    }
}