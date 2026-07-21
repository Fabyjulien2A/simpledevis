<?php

namespace App\Http\Controllers;

use App\Services\SuperPdp\SuperPdpInvoiceService;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class SuperPdpController extends Controller
{
    public function __construct(
        private readonly SuperPdpOAuthService $oauthService,
        private readonly SuperPdpInvoiceService $invoiceService
    ) {
    }

    /**
     * Affiche la page de configuration SUPER PDP.
     */
    public function index(): View
    {
        $company = auth()->user()->company;

        abort_unless(
            $company,
            404,
            'Aucune entreprise associée à ce compte.'
        );

        $connection = $company->superPdpConnection;

        $receivedInvoicesCount = $company
            ->supplierInvoices()
            ->count();

        return view('electronic-invoicing.index', compact(
            'company',
            'connection',
            'receivedInvoicesCount'
        ));
    }

    /**
     * Redirige l'utilisateur vers l'autorisation SUPER PDP.
     */
    public function connect(): RedirectResponse
    {
        $user = auth()->user();
        $company = $user->company;

        abort_unless(
            $company,
            404,
            'Aucune entreprise associée à ce compte.'
        );

        if (!$company->siret) {
            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    'Renseignez d’abord le SIRET de votre entreprise.'
                );
        }

        try {
            $authorizationUrl = $this->oauthService
                ->getAuthorizationUrl(
                    $company,
                    $user->email
                );

            return redirect()->away($authorizationUrl);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    'Impossible de démarrer la connexion à SUPER PDP.'
                );
        }
    }

    /**
     * Traite le retour OAuth de SUPER PDP.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $error = $request
                ->string('error')
                ->toString();

            $description = $request
                ->string('error_description')
                ->toString();

            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    $description
                        ? "SUPER PDP : {$description}"
                        : "Erreur OAuth SUPER PDP : {$error}"
                );
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $this->oauthService->exchangeAuthorizationCode(
                $validated['code'],
                $validated['state']
            );

            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'success',
                    'Votre entreprise est maintenant connectée à SUPER PDP.'
                );
        } catch (RequestException $exception) {
            report($exception);

            $message = $exception->response?->json('error_description')
                ?? $exception->response?->json('error')
                ?? 'SUPER PDP a refusé l’échange du code OAuth.';

            return redirect()
                ->route('electronic-invoicing.index')
                ->with('error', $message);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('electronic-invoicing.index')
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    'Une erreur inattendue est survenue pendant la connexion.'
                );
        }
    }

    /**
     * Synchronise les nouvelles factures SUPER PDP.
     */
    public function syncInvoices(): RedirectResponse
    {
        $company = auth()->user()->company;

        abort_unless(
            $company,
            404,
            'Aucune entreprise associée à ce compte.'
        );

        $connection = $company->superPdpConnection;

        if (!$connection || $connection->status !== 'connected') {
            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    'Connectez d’abord votre entreprise à SUPER PDP.'
                );
        }

        try {
            $count = $this->invoiceService->synchronize($connection);

            $message = $count > 0
                ? "{$count} facture(s) synchronisée(s) avec succès."
                : 'Aucune nouvelle facture à synchroniser.';

            return redirect()
                ->route('supplier-invoices.index')
                ->with('success', $message);
        } catch (RequestException $exception) {
            report($exception);

            $message = $exception->response?->json('error_description')
                ?? $exception->response?->json('error')
                ?? 'SUPER PDP a refusé la récupération des factures.';

            return redirect()
                ->route('electronic-invoicing.index')
                ->with('error', $message);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('electronic-invoicing.index')
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('electronic-invoicing.index')
                ->with(
                    'error',
                    'Impossible de synchroniser les factures SUPER PDP.'
                );
        }
    }

    /**
     * Déconnecte SUPER PDP dans SimpleDevis.
     *
     * La révocation distante du token sera ajoutée plus tard.
     */
    public function disconnect(): RedirectResponse
    {
        $company = auth()->user()->company;

        abort_unless(
            $company,
            404,
            'Aucune entreprise associée à ce compte.'
        );

        $connection = $company->superPdpConnection;

        if ($connection) {
            $connection->update([
                'access_token' => null,
                'refresh_token' => null,
                'access_token_expires_at' => null,
                'reception_enabled' => false,
                'status' => 'disconnected',
            ]);
        }

        return redirect()
            ->route('electronic-invoicing.index')
            ->with(
                'success',
                'La connexion SUPER PDP a été désactivée dans SimpleDevis.'
            );
    }
}