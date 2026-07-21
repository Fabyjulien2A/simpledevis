<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SuperPdpOAuthService
{
    /**
     * Génère l’URL d’autorisation OAuth SUPER PDP.
     */
    public function getAuthorizationUrl(
        Company $company,
        string $userEmail
    ): string {
        $clientId = config('services.superpdp.client_id');
        $redirectUri = config('services.superpdp.redirect_uri');
        $authorizeUrl = config('services.superpdp.authorize_url');

        if (!$clientId || !$redirectUri || !$authorizeUrl) {
            throw new RuntimeException(
                'La configuration OAuth SUPER PDP est incomplète.'
            );
        }

        /*
         * Protection CSRF du flux OAuth.
         */
        $state = Str::random(64);

        /*
         * PKCE.
         */
        $codeVerifier = Str::random(96);

        $codeChallenge = rtrim(
            strtr(
                base64_encode(
                    hash('sha256', $codeVerifier, true)
                ),
                '+/',
                '-_'
            ),
            '='
        );

        session([
            'superpdp_oauth_state' => $state,
            'superpdp_code_verifier' => $codeVerifier,
            'superpdp_company_id' => $company->id,
        ]);

        /*
         * Pour le premier test en bac à sable,
         * on ne transmet pas encore le SIREN réel de SimpleDevis.
         *
         * L’application OAuth SUPER PDP est actuellement liée
         * à l’entreprise fictive Burger Queen.
         */
        $parameters = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,

            /*
             * La documentation SUPER PDP indique de laisser
             * les scopes vides.
             */
            'scope' => '',

            /*
             * PKCE.
             */
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',

            /*
             * Préremplit l’identifiant utilisateur.
             */
            'login_hint' => $userEmail,

            /*
             * Parcours orienté réception.
             */
            'superpdp_send_and_receive' => 'receive',
        ];

        return $authorizeUrl . '?' . http_build_query($parameters);
    }

    /**
     * Échange le code d’autorisation contre les tokens OAuth.
     *
     * @throws RequestException
     */
    public function exchangeAuthorizationCode(
        string $code,
        string $returnedState
    ): SuperPdpConnection {
        $expectedState = session('superpdp_oauth_state');
        $codeVerifier = session('superpdp_code_verifier');
        $companyId = session('superpdp_company_id');

        if (
            !$expectedState
            || !hash_equals($expectedState, $returnedState)
        ) {
            throw new RuntimeException(
                'Le paramètre OAuth state est invalide ou expiré.'
            );
        }

        if (!$codeVerifier || !$companyId) {
            throw new RuntimeException(
                'La session OAuth SUPER PDP a expiré.'
            );
        }

        $company = Company::findOrFail($companyId);

        $response = Http::asForm()
            ->acceptJson()
            ->post(
                config('services.superpdp.token_url'),
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => config(
                        'services.superpdp.client_id'
                    ),
                    'client_secret' => config(
                        'services.superpdp.client_secret'
                    ),
                    'redirect_uri' => config(
                        'services.superpdp.redirect_uri'
                    ),
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                ]
            );

        $response->throw();

        $tokenData = $response->json();

        if (empty($tokenData['access_token'])) {
            throw new RuntimeException(
                'SUPER PDP n’a pas retourné d’access_token.'
            );
        }

        if (empty($tokenData['refresh_token'])) {
            throw new RuntimeException(
                'SUPER PDP n’a pas retourné de refresh_token.'
            );
        }

        $expiresIn = (int) (
            $tokenData['expires_in'] ?? 1800
        );

        /*
         * Le SIREN sera utilisé plus tard quand nous connecterons
         * une vraie entreprise française au lieu de Burger Queen.
         */
        $siren = $this->extractSiren($company->siret);

        $connection = SuperPdpConnection::updateOrCreate(
            [
                'company_id' => $company->id,
            ],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],

                'access_token_expires_at' => now()
                    ->addSeconds($expiresIn),

                'superpdp_company_id' =>
                    $tokenData['company_id'] ?? null,

                /*
                 * En bac à sable, cette valeur reste indicative.
                 */
                'directory_identifier' => $siren,

                'reception_enabled' => true,
                'status' => 'connected',
            ]
        );

        session()->forget([
            'superpdp_oauth_state',
            'superpdp_code_verifier',
            'superpdp_company_id',
        ]);

        return $connection;
    }

    /**
     * Extrait le SIREN, soit les neuf premiers chiffres du SIRET.
     */
    private function extractSiren(?string $siret): ?string
    {
        if (!$siret) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $siret);

        if (!$digits || strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 9);
    }
}