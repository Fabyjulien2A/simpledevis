<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Verification\TricatelPublicationScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** One explicit refresh, reusing OAuth rotation/identity checks and encrypted model casts. */
final class SandboxOAuthTokenRefresh
{
    public function __construct(private readonly SuperPdpOAuthService $oauth) {}

    public function run(User $actor, int $connectionId = 1): array
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        // Fixed allowlist: never infer a company or repoint the Burger Queen binding.
        [$ownerId, $providerId, $number] = match ($connectionId) {
            1 => [3, 18172, '000000002'],
            2 => [4, 18171, '000000001'],
            default => throw new RuntimeException('Unsupported sandbox OAuth connection.'),
        };
        if (PHP_SAPI !== 'cli' || ! $actor->exists || $actor->getKey() !== $ownerId
            || auth()->id() !== $ownerId || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
            || config('services.superpdp.token_url') !== SandboxEmissionAuthorization::ORIGIN.'/oauth2/token') {
            throw new RuntimeException('Explicit sandbox refresh scope required.');
        }
        $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
        $failed = false;
        $result = $db->transaction(function () use ($db, &$failed, $actor, $connectionId, $ownerId, $providerId, $number) {
            if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                throw new RuntimeException('Unexpected sandbox database.');
            }
            // Keep the owner stable throughout the existing connection-row lock,
            // remote identity check, and encrypted rotation. Never retry a transaction.
            $company = Company::on(SandboxDatabaseIsolation::CONNECTION)->whereKey($ownerId)->where('user_id', $ownerId)->lockForUpdate()->firstOrFail();
            $context = $connectionId === 1 ? SandboxEmissionContext::forCompany($company) : null;
            $connection = SuperPdpConnection::on(SandboxDatabaseIsolation::CONNECTION)->whereKey($connectionId)->where('company_id', $ownerId)->lockForUpdate()->firstOrFail();
            if ($connectionId === 2) {
                TricatelPublicationScope::assert($actor, $company, $connection);
            }
            $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), SandboxEmissionAuthorization::API_URL);
            if ($connection->superpdp_company_id !== $providerId || $connection->directory_identifier !== $number
                || $connection->status !== 'connected' || ! $connection->isConnected()) {
                throw new RuntimeException('Selected sandbox connection is not qualified for renewal.');
            }
            $needsRefresh = ! $connection->access_token || $connection->tokenIsExpired();
            try {
                $renewed = $this->oauth->refreshAccessToken($connection);
            } catch (Throwable) {
                // Commit the existing service's blocked/error status. Rolling back
                // here could let a later invocation replay an already rotated token.
                $failed = true;

                return null;
            }
            if ($context !== null) {
                $context->assertFor($company);
            } else {
                TricatelPublicationScope::assert($actor, $company, $renewed);
            }
            SandboxDatabaseIsolation::assertConfiguredScope();

            return ['status' => $needsRefresh ? 'refreshed' : 'already_valid',
                'connection_id' => $connectionId, 'local_company_id' => $ownerId, 'environment' => 'sandbox',
                'user_id' => $ownerId, 'provider_company_id' => $providerId,
                'identity' => ['number_scheme' => 'sandbox', 'number' => $number],
                'token_valid_for_documentary_test' => ! $renewed->tokenIsExpired(),
                'ready_for_emission' => false];
        }, 1);

        return $failed ? ['status' => 'refused_or_uncertain', 'manual_action_required' => true,
            'ready_for_emission' => false] : $result;
    }
}
