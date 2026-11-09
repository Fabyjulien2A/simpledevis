<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\SuperPdp\Emission\EmissionPrerequisites::class,
            \App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites::class);
        $this->app->bind(\App\Services\SuperPdp\Emission\EmissionTransport::class,
            \App\Services\SuperPdp\Emission\DisabledEmissionTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('client-directory', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->user()->id)
            ->response(fn (Request $request, array $headers) => response(
                'Trop de consultations de l’annuaire. Réessayez dans une minute.', 429, $headers
            )));
    }
}
