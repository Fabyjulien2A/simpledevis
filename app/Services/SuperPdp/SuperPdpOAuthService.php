<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SuperPdpOAuthService
{
    public function getAuthorizationUrl(Company $company, string $userEmail): string
    {
        $company = $this->ownedCompany($company->id);
        $identity = $this->identity($company);
        $environment = $this->environment();
        $state = Str::random(64);
        $verifier = Str::random(96);
        session(['superpdp_oauth' => [
            'state' => $state, 'verifier' => $verifier, 'company_id' => $company->id,
            'user_id' => auth()->id(), 'identity' => $identity, 'environment' => $environment,
            'client_id' => $this->setting('client_id'), 'api_url' => $this->setting('api_url'),
            'redirect_uri' => $this->setting('redirect_uri'), 'created_at' => now()->timestamp,
        ]]);

        // The local official contract documents empty scopes. It does not document
        // superpdp_send_and_receive values: do not invent a combined value.
        return $this->setting('authorize_url').'?'.http_build_query([
            'response_type' => 'code', 'client_id' => $this->setting('client_id'),
            'redirect_uri' => $this->setting('redirect_uri'), 'state' => $state, 'scope' => '',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'login_hint' => $userEmail,
            'superpdp_company_number' => $identity['number'], 'superpdp_company_number_scheme' => $identity['number_scheme'],
        ]);
    }

    public function cancelAuthorization(): void
    {
        session()->forget('superpdp_oauth');
    }

    public function exchangeAuthorizationCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $returnedState): SuperPdpConnection
    {
        // Single-use even on errors; a failed exchange requires a fresh consent flow.
        $pending = session()->pull('superpdp_oauth');
        if (! is_array($pending) || ! is_string($pending['state'] ?? null)
            || ! hash_equals($pending['state'], $returnedState) || $code === ''
            || ($pending['user_id'] ?? null) !== auth()->id() || ! auth()->check()
            || ! is_int($pending['created_at'] ?? null)
            || $pending['created_at'] > now()->timestamp || $pending['created_at'] < now()->subMinutes(10)->timestamp) {
            throw new RuntimeException('Session OAuth invalide ou expirée. Recommencez la connexion.');
        }
        foreach (['environment', 'client_id', 'api_url', 'redirect_uri'] as $key) {
            $expected = $key === 'environment' ? $this->environment() : $this->setting($key);
            if (($pending[$key] ?? null) !== $expected) {
                throw new RuntimeException('La configuration OAuth a changé. Recommencez la connexion.');
            }
        }
        $company = $this->ownedCompany($pending['company_id']);
        if ($this->identity($company) !== ($pending['identity'] ?? null)) {
            throw new RuntimeException('L’entreprise a changé pendant la connexion OAuth.');
        }
        $tokens = $this->tokens([
            'grant_type' => 'authorization_code', 'redirect_uri' => $pending['redirect_uri'],
            'code' => $code, 'code_verifier' => $pending['verifier'],
        ]);
        $remote = $this->remoteCompany($tokens['access_token'], $company, $pending['environment']);
        if (isset($tokens['company_id']) && $tokens['company_id'] !== $remote['id']) {
            throw new RuntimeException('Identité entreprise OAuth incohérente.');
        }

        return $company->getConnection()->transaction(function () use ($company, $pending, $tokens, $remote) {
            $locked = Company::on($company->getConnectionName())->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (! auth()->check() || auth()->id() !== $pending['user_id']
                || $locked->user_id !== auth()->id() || $this->identity($locked) !== $pending['identity']) {
                throw new RuntimeException('Le propriétaire ou l’identité de l’entreprise a changé.');
            }

            $existing = SuperPdpConnection::on($company->getConnectionName())->where('company_id', $locked->id)->lockForUpdate()->first();
            // One connection per company: never replace another environment/client
            // or provider company while retaining reception cursors and history.
            if ($existing && (($existing->environment !== null && $existing->environment !== $pending['environment'])
                || ($existing->oauth_client_id !== null && $existing->oauth_client_id !== $pending['client_id'])
                || ($existing->api_url !== null && $existing->api_url !== $pending['api_url'])
                || ($existing->superpdp_company_id !== null && $existing->superpdp_company_id !== $remote['id']))) {
                throw new RuntimeException('La connexion existante appartient à un autre contexte OAuth. Elle est conservée.');
            }

            $values = $this->tokenValues($tokens) + [
                'superpdp_company_id' => $remote['id'], 'environment' => $pending['environment'],
                'oauth_client_id' => $pending['client_id'], 'api_url' => $pending['api_url'],
                'directory_identifier' => $pending['identity']['number'], 'reception_enabled' => true,
            ];
            if ($pending['environment'] === 'sandbox' && ! $existing) {
                $values += ['last_invoice_id' => 0, 'last_event_id' => 0, 'last_sync_at' => null];
            }

            return SuperPdpConnection::on($company->getConnectionName())->updateOrCreate(
                ['company_id' => $company->id], $values,
            );
        }, 1);
    }

    public function ensureValidAccessToken(SuperPdpConnection $connection): SuperPdpConnection
    {
        $connection = $connection->fresh();
        $connection->assertOAuthContext($this->environment(), $this->setting('client_id'), $this->setting('api_url'));
        if (! $connection->isConnected()) {
            throw new RuntimeException('La connexion SUPER PDP n’est pas active.');
        }

        return $connection->access_token && ! $connection->tokenIsExpired()
            ? $connection : $this->refreshAccessToken($connection);
    }

    /** Jeton existant uniquement : aucune requête OAuth, rotation ou écriture SQL. */
    public function existingAccessTokenForReadOnly(Company $company, SuperPdpConnection $connection): string
    {
        try {
            $ownership = [
                'authenticated' => auth()->check(), 'company_persisted' => $company->exists,
                'connection_persisted' => $connection->exists, 'actor_owns_company' => $company->user_id === auth()->id(),
                'connection_belongs_to_company' => $connection->company_id === $company->getKey(),
            ];
            if (in_array(false, $ownership, true)) {
                throw new ReadOnlyOAuthPrerequisiteException('oauth_ownership_invalid', $ownership);
            }
            try {
                $identity = $this->identity($company);
            } catch (Throwable) {
                $binding = config('services.superpdp.sandbox_identity');
                throw new ReadOnlyOAuthPrerequisiteException('oauth_identity_context_invalid', [
                    'app_environment_sandbox' => app()->environment('sandbox') && config('app.env') === 'sandbox',
                    'provider_environment_sandbox' => config('services.superpdp.environment') === 'sandbox',
                    'isolated_database_selected' => config('database.default') === SandboxDatabaseIsolation::CONNECTION,
                    'sandbox_binding_preserved' => is_array($binding) && in_array($binding['company_id'] ?? null, [3, '3'], true)
                        && ($binding['number_scheme'] ?? null) === 'sandbox' && ($binding['number'] ?? null) === '000000002',
                ]);
            }
            foreach (['client_id', 'api_url'] as $key) {
                if (! is_string(config('services.superpdp.'.$key)) || config('services.superpdp.'.$key) === '') {
                    throw new ReadOnlyOAuthPrerequisiteException('oauth_'.$key.'_not_configured');
                }
            }
            try {
                $connection->assertOAuthContext($this->environment(), $this->setting('client_id'), $this->setting('api_url'));
            } catch (Throwable) {
                throw new ReadOnlyOAuthPrerequisiteException('oauth_connection_context_mismatch', [
                    'environment_matches' => $connection->environment === config('services.superpdp.environment'),
                    'client_matches' => $connection->oauth_client_id === config('services.superpdp.client_id'),
                    'api_url_matches' => $connection->api_url === config('services.superpdp.api_url'),
                ]);
            }
            if ($connection->status !== 'connected') {
                throw new ReadOnlyOAuthPrerequisiteException('oauth_connection_not_connected');
            }
            try {
                $expiry = $connection->access_token_expires_at;
                $expired = $connection->tokenIsExpired();
            } catch (Throwable) {
                throw new ReadOnlyOAuthPrerequisiteException('access_token_expiry_unreadable');
            }
            if ($expired) {
                throw new ReadOnlyOAuthPrerequisiteException($expiry === null ? 'access_token_expiry_missing'
                    : ($expiry->lte(now()) ? 'access_token_expired' : 'access_token_expiring_soon'), [
                    'checked_at' => now()->toIso8601String(), 'expires_at' => $expiry?->toIso8601String(),
                    'safety_margin_seconds' => 300,
                ]);
            }
            if ($connection->directory_identifier !== $identity['number']) {
                throw new ReadOnlyOAuthPrerequisiteException('oauth_directory_identity_mismatch');
            }
            try {
                $token = $connection->access_token;
            } catch (Throwable) {
                throw new ReadOnlyOAuthPrerequisiteException('access_token_unreadable');
            }
            if (! is_string($token) || $token === '') {
                throw new ReadOnlyOAuthPrerequisiteException('access_token_missing');
            }

            return $token;
        } catch (ReadOnlyOAuthPrerequisiteException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ReadOnlyOAuthPrerequisiteException('oauth_read_only_check_failed');
        }
    }

    public function refreshAccessToken(SuperPdpConnection $connection): SuperPdpConnection
    {
        // Row lock spans the exchange and rotation. No transaction retry: a rotating
        // refresh token must never be replayed after an uncertain remote result.
        $result = $connection->getConnection()->transaction(function () use ($connection) {
            $locked = SuperPdpConnection::on($connection->getConnectionName())->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $locked->assertOAuthContext($this->environment(), $this->setting('client_id'), $this->setting('api_url'));
            if (! $locked->isConnected()) {
                throw new RuntimeException('La connexion SUPER PDP n’est pas active.');
            }
            // A competing worker may already have refreshed while this worker waited.
            if ($locked->access_token && ! $locked->tokenIsExpired()) {
                return $locked;
            }
            try {
                $identity = $this->identity($locked->company);
                if ($identity['number_scheme'] === 'sandbox' && $locked->directory_identifier !== $identity['number']) {
                    throw new RuntimeException('Identité sandbox de la connexion incohérente.');
                }
                $tokens = $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $locked->refresh_token]);
                $remote = $this->remoteCompany($tokens['access_token'], $locked->company, $locked->environment);
                if ($this->identity($locked->company) !== $identity || $remote['id'] !== $locked->superpdp_company_id
                    || (isset($tokens['company_id']) && $tokens['company_id'] !== $remote['id'])) {
                    throw new RuntimeException('Identité entreprise OAuth incohérente.');
                }
                $locked->update($this->tokenValues($tokens));
            } catch (Throwable) {
                // The remote refresh may have rotated before failing. Commit a
                // blocked status so waiting workers cannot replay the old token.
                $locked->update(['status' => 'error']);

                return new RuntimeException('Renouvellement OAuth incertain ou refusé. Reconnectez votre entreprise.');
            }

            return $locked->refresh();
        }, 1);
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result;
    }

    private function ownedCompany(int $companyId): Company
    {
        $company = Company::findOrFail($companyId);
        if (! auth()->check() || $company->user_id !== auth()->id()) {
            throw new RuntimeException('Cette entreprise n’appartient pas à l’utilisateur connecté.');
        }

        return $company;
    }

    private function environment(): string
    {
        $environment = config('services.superpdp.environment');
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('Configurez explicitement l’environnement OAuth SUPER PDP.');
        }

        return $environment;
    }

    /** Explicit local-company binding; a sandbox identity is never inferred from a name or SIRET. */
    private function identity(Company $company): array
    {
        if ($this->environment() === 'sandbox') {
            SandboxDatabaseIsolation::assertConfiguredScope();
            $binding = config('services.superpdp.sandbox_identity');
            // Preserve the existing Burger Queen opt-in used by emission. Never
            // repoint that binding to Tricatel or infer a sandbox identity from a SIRET.
            if (! is_array($binding) || ! in_array($binding['company_id'] ?? null, [3, '3'], true)
                || ($binding['number_scheme'] ?? null) !== 'sandbox'
                || ($binding['number'] ?? null) !== '000000002') {
                throw new RuntimeException('Configuration de l’identité sandbox SUPER PDP invalide.');
            }
            $identities = [
                3 => ['owner_user_id' => 3, 'number' => '000000002'],
                4 => ['owner_user_id' => 4, 'number' => '000000001'],
            ];
            $expected = $identities[$company->getKey()] ?? null;
            if ($expected === null || $company->user_id !== $expected['owner_user_id']) {
                throw new RuntimeException('Entreprise ou propriétaire non autorisé pour OAuth sandbox.');
            }

            return ['number_scheme' => 'sandbox', 'number' => $expected['number']];
        }

        return ['number_scheme' => 'fr_siren', 'number' => $this->siren($company)];
    }

    private function siren(Company $company): string
    {
        $siret = preg_replace('/\D/', '', $company->siret ?? '');
        if (strlen($siret) !== 14) {
            throw new RuntimeException('Le SIRET de l’entreprise doit contenir 14 chiffres.');
        }

        return substr($siret, 0, 9);
    }

    private function remoteCompany(#[\SensitiveParameter] string $token, Company $company, string $environment): array
    {
        $identity = $this->identity($company);
        $data = $this->request(fn () => Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(30)
            ->get(rtrim($this->setting('api_url'), '/').'/companies/me'));
        if (! is_int($data['id'] ?? null) || $data['id'] <= 0
            || ($data['number_scheme'] ?? null) !== $identity['number_scheme']
            || ($data['number'] ?? null) !== $identity['number']
            || ($data['country'] ?? null) !== 'FR' || ($data['env'] ?? null) !== $environment) {
            throw new RuntimeException('L’entreprise ou l’environnement SUPER PDP ne correspond pas à SimpleDevis.');
        }

        return $data;
    }

    private function tokens(#[\SensitiveParameter] array $grant): array
    {
        $data = $this->request(fn () => Http::asForm()->acceptJson()->withoutRedirecting()->timeout(30)->post($this->setting('token_url'),
            $grant + ['client_id' => $this->setting('client_id'), 'client_secret' => $this->setting('client_secret')]));
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
            || ! is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === ''
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0
            || (isset($data['token_type']) && (! is_string($data['token_type']) || strcasecmp($data['token_type'], 'Bearer') !== 0))) {
            throw new RuntimeException('Réponse de jetons OAuth SUPER PDP invalide.');
        }

        return $data;
    }

    private function request(#[\SensitiveParameter] callable $send): array
    {
        try {
            $response = $send();
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException;
            }

            return $response->json();
        } catch (Throwable) {
            // Do not attach the original exception: HTTP exceptions may expose bodies,
            // Authorization headers, authorization codes or client secrets in logs.
            throw new RuntimeException('La requête OAuth SUPER PDP a échoué. Recommencez la connexion si nécessaire.');
        }
    }

    private function tokenValues(#[\SensitiveParameter] array $tokens): array
    {
        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
            'access_token_expires_at' => now()->addSeconds($tokens['expires_in']), 'status' => 'connected'];
    }

    private function setting(string $key): string
    {
        $value = config('services.superpdp.'.$key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Configuration OAuth SUPER PDP incomplète.');
        }

        return $value;
    }
}
