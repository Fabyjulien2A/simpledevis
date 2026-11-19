<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use RuntimeException;

/** Déclaration explicitement autorisée ; ni SIREN déduit, ni droit sur une autre entreprise. */
final class TricatelPublicationScope
{
    public const ADDRESS = '0225:315143296_18171';

    public static function assert(User $actor, Company $company, ?SuperPdpConnection $connection = null): void
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        if (PHP_SAPI !== 'cli' || ! auth()->check() || auth()->id() !== 4 || ! $actor->exists || $actor->id !== 4
            || ! in_array($actor->getConnectionName(), [null, 'superpdp_sandbox'], true)
            || ! $company->exists || $company->id !== 4 || $company->user_id !== 4 || $company->company_name !== 'Tricatel'
            || ! in_array($company->getConnectionName(), [null, 'superpdp_sandbox'], true)
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL) {
            throw new RuntimeException('Contrôle réservé au propriétaire 4 et à Tricatel 4 dans le sandbox isolé.');
        }
        if ($connection !== null && (! $connection->exists || $connection->company_id !== 4
            || ! in_array($connection->getConnectionName(), [null, 'superpdp_sandbox'], true)
            || ! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0)) {
            throw new RuntimeException('Connexion propre à Tricatel requise.');
        }
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
