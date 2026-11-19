<?php

namespace App\Providers;

use App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\SandboxWebEmissionActivation;
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
        $this->app->bind(EmissionPrerequisites::class,
            ClosedEmissionPrerequisites::class);
        $this->app->bind(EmissionTransport::class,
            DisabledEmissionTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(SandboxWebEmissionActivation::class)->activate();

        RateLimiter::for('electronic-emission-request', fn (Request $request) => Limit::perMinute(3)
            ->by((string) $request->user()->id)
            ->response(fn (Request $request, array $headers) => response(
                'Trop de demandes d’émission. Aucun dépôt effectué.', 429, $headers
            )));
        RateLimiter::for('cii-preparation-request', fn (Request $request) => Limit::perMinute(3)
            ->by((string) $request->user()->id)
            ->response(fn (Request $request, array $headers) => response(
                'Trop de demandes de préparation CII. Réessayez dans une minute. Aucun document généré.', 429, $headers
            )));
        RateLimiter::for('invoice-destination', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->user()->id)
            ->response(fn (Request $request, array $headers) => response(
                'Trop de vérifications de destination. Réessayez dans une minute.', 429, $headers
            )));
        RateLimiter::for('client-directory', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->user()->id)
            ->response(fn (Request $request, array $headers) => response(
                'Trop de consultations de l’annuaire. Réessayez dans une minute.', 429, $headers
            )));
    }
}
