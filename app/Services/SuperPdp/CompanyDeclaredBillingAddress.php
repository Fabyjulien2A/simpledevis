<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Settings storage only. Never use this exception to qualify an invoice or routing. */
final class CompanyDeclaredBillingAddress
{
    public static function validate(User $actor, ?Company $company, mixed $address, ?string $siret): ?array
    {
        $validated = Validator::make(['billing_electronic_address' => $address], DeclaredBillingAddress::rules())->validate();
        $address = $validated['billing_electronic_address'] ?? null;

        if (is_array($address) && ($address['scheme'] ?? null) === '0225' && ($address['value'] ?? null) === '315143296_18171'
            && ($siret === null || $siret === '') && self::allowsTricatel($actor, $company)) {
            return $address;
        }

        return DeclaredBillingAddress::validate($address, $siret);
    }

    private static function allowsTricatel(User $actor, ?Company $company): bool
    {
        if (! $actor->exists || $actor->id !== 4 || ! auth()->check() || auth()->id() !== 4
            || $company === null || ! $company->exists || $company->id !== 4 || $company->user_id !== 4
            || $company->company_name !== 'Tricatel' || ! in_array($company->siret, [null, ''], true)
            || ! in_array($actor->getConnectionName(), [null, SandboxDatabaseIsolation::CONNECTION], true)
            || ! in_array($company->getConnectionName(), [null, SandboxDatabaseIsolation::CONNECTION], true)
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL) {
            return false;
        }

        try {
            SandboxDatabaseIsolation::assertConfiguredScope();
        } catch (RuntimeException) {
            return false;
        }

        // Read the owned, persisted OAuth binding, never identity fields from the form.
        // OAuth maps company 4 / owner 4 to sandbox / 000000001 and verifies it
        // before storing directory_identifier. This is no publication evidence.
        $connection = $company->superPdpConnection()->lockForUpdate()->first();
        if ($connection === null || $connection->company_id !== 4
            || $connection->directory_identifier !== '000000001' || $connection->status !== 'connected'
            || ! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0) {
            return false;
        }
        $client = config('services.superpdp.client_id');
        if (! is_string($client) || $client === '') {
            return false;
        }
        try {
            $connection->assertOAuthContext('sandbox', $client, SandboxEmissionAuthorization::API_URL);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
