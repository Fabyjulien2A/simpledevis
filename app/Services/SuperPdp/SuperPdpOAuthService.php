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
     * Échange le code d’autorisation contre les jetons OAuth.
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
            ->timeout(30)
            ->post(
                $this->tokenUrl(),
                [
                    'grant_type' => 'authorization_code',

                    'client_id' => $this->clientId(),

                    'client_secret' => $this->clientSecret(),

                    'redirect_uri' => config(
                        'services.superpdp.redirect_uri'
                    ),

                    'code' => $code,

                    'code_verifier' => $codeVerifier,
                ]
            );

        $response->throw();

        $tokenData = $response->json();

        $this->validateTokenResponse($tokenData);

        $expiresIn = (int) (
            $tokenData['expires_in'] ?? 1800
        );

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
     * Retourne une connexion possédant un jeton d’accès valide.
     *
     * Si le jeton est expiré ou proche de l’expiration,
     * il est automatiquement renouvelé.
     */
    public function ensureValidAccessToken(
        SuperPdpConnection $connection
    ): SuperPdpConnection {
        if (!$connection->isConnected()) {
            throw new RuntimeException(
                'La connexion SUPER PDP n’est pas active.'
            );
        }

        if (
            $connection->access_token
            && !$connection->tokenIsExpired()
        ) {
            return $connection;
        }

        return $this->refreshAccessToken($connection);
    }

    /**
     * Renouvelle les jetons OAuth grâce au refresh token.
     *
     * SUPER PDP applique une rotation du refresh token :
     * le nouveau refresh token doit immédiatement remplacer l’ancien.
     *
     * @throws RequestException
     */
    public function refreshAccessToken(
        SuperPdpConnection $connection
    ): SuperPdpConnection {
        if (!$connection->refresh_token) {
            throw new RuntimeException(
                'Aucun refresh token SUPER PDP n’est disponible.'
            );
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post(
                $this->tokenUrl(),
                [
                    'grant_type' => 'refresh_token',

                    'client_id' => $this->clientId(),

                    'client_secret' => $this->clientSecret(),

                    'refresh_token' =>
                        $connection->refresh_token,
                ]
            );

        if ($response->failed()) {
            logger()->warning(
                'Échec du renouvellement OAuth SUPER PDP.',
                [
                    'connection_id' => $connection->id,
                    'company_id' => $connection->company_id,
                    'status' => $response->status(),

                    /*
                     * On ne journalise jamais les jetons.
                     */
                    'response' => $response->json(),
                ]
            );
        }

        $response->throw();

        $tokenData = $response->json();

        $this->validateTokenResponse($tokenData);

        $expiresIn = (int) (
            $tokenData['expires_in'] ?? 1800
        );

        /*
         * Important :
         * le nouveau refresh token remplace l’ancien.
         */
        $connection->update([
            'access_token' => $tokenData['access_token'],

            'refresh_token' => $tokenData['refresh_token'],

            'access_token_expires_at' => now()
                ->addSeconds($expiresIn),

            'superpdp_company_id' =>
                $tokenData['company_id']
                ?? $connection->superpdp_company_id,

            'status' => 'connected',
        ]);

        /*
         * Recharge le modèle avec les valeurs enregistrées.
         */
        return $connection->refresh();
    }

    /**
     * Vérifie que SUPER PDP a retourné les deux jetons requis.
     */
    private function validateTokenResponse(
        mixed $tokenData
    ): void {
        if (!is_array($tokenData)) {
            throw new RuntimeException(
                'La réponse OAuth SUPER PDP est invalide.'
            );
        }

        if (empty($tokenData['access_token'])) {
            throw new RuntimeException(
                'SUPER PDP n’a pas retourné d’access_token.'
            );
        }

        if (empty($tokenData['refresh_token'])) {
            throw new RuntimeException(
                'SUPER PDP n’a pas retourné de nouveau refresh_token.'
            );
        }
    }

    /**
     * Retourne l’URL de l’endpoint OAuth token.
     */
    private function tokenUrl(): string
    {
        $tokenUrl = config('services.superpdp.token_url');

        if (!$tokenUrl) {
            throw new RuntimeException(
                'L’URL OAuth token de SUPER PDP n’est pas configurée.'
            );
        }

        return $tokenUrl;
    }

    /**
     * Retourne l’identifiant OAuth.
     */
    private function clientId(): string
    {
        $clientId = config('services.superpdp.client_id');

        if (!$clientId) {
            throw new RuntimeException(
                'Le client_id SUPER PDP n’est pas configuré.'
            );
        }

        return $clientId;
    }

    /**
     * Retourne le secret OAuth.
     */
    private function clientSecret(): string
    {
        $clientSecret = config(
            'services.superpdp.client_secret'
        );

        if (!$clientSecret) {
            throw new RuntimeException(
                'Le client_secret SUPER PDP n’est pas configuré.'
            );
        }

        return $clientSecret;
    }

    /**
     * Extrait le SIREN, soit les neuf premiers chiffres du SIRET.
     */
    private function extractSiren(
        ?string $siret
    ): ?string {
        if (!$siret) {
            return null;
        }

        $digits = preg_replace(
            '/\D/',
            '',
            $siret
        );

        if (!$digits || strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 9);
    }
}