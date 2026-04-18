<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;

class SubscriptionController extends Controller
{
    public function index(): View
    {
        return view('billing.index');
    }

    public function subscribe(Request $request, string $plan): RedirectResponse|Checkout
    {
        $user = $request->user();

        $priceId = match ($plan) {
            'pro' => env('STRIPE_PRICE_PRO'),
            'business' => env('STRIPE_PRICE_BUSINESS'),
            default => abort(404),
        };

        if (!$priceId) {
            abort(500, 'Le prix Stripe n’est pas configuré.');
        }

        return $user->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('billing.cancel'),
            ]);
    }

    public function success(Request $request): View
    {
        return view('billing.success');
    }

    public function cancel(Request $request): View
    {
        return view('billing.cancel');
    }
}