<?php

namespace App\Services\SuperPdp\Documentary;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferenceScope
{
    public const ORIGIN = 'https://api.superpdp.tech';

    public const API = self::ORIGIN.'/v1.beta';

    public function configuration(string $run, string $operation): array
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        $scope = config('superpdp_documentary.scope');
        $expected = is_array($scope) ? match ($scope['company_id'] ?? null) {
            3 => [3, 1, 18172, '000000002'],
            4 => [4, 2, 18171, '000000001'],
            default => null,
        } : null;
        if (PHP_SAPI !== 'cli' || config('superpdp_documentary.enabled') !== true
            || ! in_array($operation, ['generation', 'validation'], true)
            || ! preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $run)
            || ($scope['run'] ?? null) !== $run || $expected === null
            || [$scope['user_id'] ?? null, $scope['connection_id'] ?? null,
                $scope['provider_company_id'] ?? null, $scope['number'] ?? null] !== $expected
            || ($scope['environment'] ?? null) !== 'sandbox' || ($scope['number_scheme'] ?? null) !== 'sandbox'
            || ($scope['api_url'] ?? null) !== self::API || config('services.superpdp.api_url') !== self::API
            || ! is_string($scope['private_root'] ?? null) || $scope['private_root'] === ''
            || config('superpdp_documentary.private_root') !== $scope['private_root']
            || config('services.superpdp.environment') !== 'sandbox'
            || ! is_string($scope['client_id'] ?? null) || $scope['client_id'] === ''
            || config('services.superpdp.client_id') !== $scope['client_id']) {
            throw new RuntimeException('Périmètre documentaire sandbox fermé ou incohérent.');
        }
        $this->authorization($scope, $operation);

        return $scope;
    }

    public function authorization(array $scope, string $operation, ?string $ciiHash = null): void
    {
        $approval = config('superpdp_documentary.'.$operation.'_authorization');
        if (! is_array($approval) || ($approval['scope'] ?? null) !== $scope
            || ($approval['purpose'] ?? null) !== $operation || ! is_string($approval['approved_by'] ?? null)
            || trim($approval['approved_by']) === '' || ! is_int($approval['approved_at'] ?? null)
            || ! is_int($approval['expires_at'] ?? null) || $approval['approved_at'] > now()->timestamp
            || $approval['expires_at'] <= now()->timestamp || $approval['expires_at'] <= $approval['approved_at']
            || $approval['expires_at'] - $approval['approved_at'] > 900
            || ($operation === 'validation' && (! preg_match('/\A[a-f0-9]{64}\z/D', $approval['cii_sha256'] ?? '')
                || ($ciiHash !== null && ! hash_equals($approval['cii_sha256'], $ciiHash))))) {
            throw new RuntimeException('Autorisation serveur documentaire absente, expirée ou hors périmètre.');
        }
    }

    /** SELECTs only, in an explicitly read-only transaction, closed before network. */
    public function load(array $scope): array
    {
        $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('Transaction préexistante refusée.');
        }
        $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                throw new RuntimeException('Base sandbox inattendue.');
            }

            return [User::on(SandboxDatabaseIsolation::CONNECTION)->findOrFail($scope['user_id']),
                Company::on(SandboxDatabaseIsolation::CONNECTION)->where('user_id', $scope['user_id'])->findOrFail($scope['company_id']),
                SuperPdpConnection::on(SandboxDatabaseIsolation::CONNECTION)->where('company_id', $scope['company_id'])->findOrFail($scope['connection_id'])];
        } finally {
            $db->rollBack();
        }
    }

    public function models(array $scope, User $user, Company $company, SuperPdpConnection $connection): void
    {
        $connection->assertOAuthContext('sandbox', $scope['client_id'], self::API);
        if (! $user->exists || ! $company->exists || ! $connection->exists
            || $user->id !== $scope['user_id'] || $company->id !== $scope['company_id']
            || $company->user_id !== $user->id || $connection->id !== $scope['connection_id']
            || $company->company_name !== ($scope['company_id'] === 3 ? 'Burger Queen' : 'Tricatel')
            || $connection->company_id !== $company->id || $connection->superpdp_company_id !== $scope['provider_company_id']
            || $connection->directory_identifier !== $scope['number'] || ! $connection->isConnected()
            || $connection->tokenIsExpired() || ! is_string($connection->access_token) || $connection->access_token === '') {
            throw new RuntimeException('Propriétaire, entreprise ou jeton OAuth sandbox invalide. Aucun renouvellement.');
        }
        foreach ([$user, $company, $connection] as $model) {
            if ($model->getConnectionName() !== SandboxDatabaseIsolation::CONNECTION) {
                throw new RuntimeException('Modèle extérieur à la base sandbox.');
            }
        }
    }
}
