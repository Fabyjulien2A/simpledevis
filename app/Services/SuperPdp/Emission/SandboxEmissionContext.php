<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Approved sandbox declaration, separate from production invoice snapshots.
 * No request input, SQL, transport, timestamped API evidence or deposit permission.
 * Future consumers must recheck a freshly authorized Company with assertFor().
 */
final readonly class SandboxEmissionContext implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    public const PROFILE = 'superpdp_sandbox_burger_queen_tricatel';

    private function __construct(private array $snapshot) {}

    public static function forCompany(Company $company): self
    {
        self::assertAuthorizedCompany($company);

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'profile' => self::PROFILE,
            'environment' => 'sandbox',
            'owner_user_id' => auth()->id(),
            'seller' => [
                'local_company_id' => 3,
                'name' => 'Burger Queen',
                'superpdp_identity' => ['number_scheme' => 'sandbox', 'number' => '000000002'],
                // The API sandbox identifier is not a French SIREN/SIRET or a CII schemeID.
                'legal_registration_identity' => null,
                'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18172'],
            ],
            'buyer' => [
                'name' => 'Tricatel',
                'superpdp_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
                'legal_registration_identity' => null,
                'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18171'],
                'routing_confirmation' => [
                    'source' => 'superpdp_interface',
                    'status' => 'OK',
                    'verification' => 'operator_confirmed',
                    'api_verified' => false,
                ],
            ],
            'ready_for_emission' => false,
        ]);
    }

    public function assertFor(Company $company): void
    {
        self::assertAuthorizedCompany($company);
        $this->assertCurrentScope();
    }

    /** Returns a copy. This method never promotes the declaration to verified API evidence. */
    public function toArray(): array
    {
        $this->assertCurrentScope();

        return $this->snapshot;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Compare a future stored declaration; arbitrary data cannot construct this object. */
    public function assertMatches(array $candidate): void
    {
        $expected = $this->toArray();
        foreach (['seller', 'buyer'] as $party) {
            $declaration = $candidate[$party] ?? null;
            $electronicAddress = is_array($declaration) ? ($declaration['electronic_address'] ?? null) : null;
            $address = is_array($electronicAddress) ? ($electronicAddress['value'] ?? null) : null;
            if (! is_string($address) || str_contains(strtolower($address), '_replyto')) {
                throw new InvalidArgumentException('Adresse technique ou déclaration sandbox incohérente.');
            }
        }
        if (self::canonical($candidate) !== self::canonical($expected)) {
            throw new InvalidArgumentException('La déclaration diffère du contexte sandbox approuvé.');
        }
    }

    private function assertCurrentScope(): void
    {
        self::assertSandboxScope();
        if (! auth()->check() || auth()->id() !== $this->snapshot['owner_user_id']) {
            throw new RuntimeException('Le propriétaire du contexte sandbox doit être connecté.');
        }
    }

    private static function assertAuthorizedCompany(Company $company): void
    {
        self::assertSandboxScope();
        if (! auth()->check() || ! $company->exists || $company->getKey() !== 3
            || $company->user_id !== auth()->id() || $company->company_name !== 'Burger Queen'
            || $company->siret !== null
            || ! in_array($company->getConnectionName(), [null, 'superpdp_sandbox'], true)) {
            throw new RuntimeException('Entreprise locale ou propriétaire non autorisé pour ce contexte sandbox.');
        }
    }

    private static function assertSandboxScope(): void
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        $binding = config('services.superpdp.sandbox_identity');
        if (! app()->environment('sandbox') || config('app.env') !== 'sandbox'
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
            || ! is_array($binding) || ! in_array($binding['company_id'] ?? null, [3, '3'], true)
            || ($binding['number_scheme'] ?? null) !== 'sandbox' || ($binding['number'] ?? null) !== '000000002') {
            throw new RuntimeException('Contexte réservé au profil sandbox isolé et à son entreprise explicitement autorisée.');
        }
    }

    private static function canonical(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        unset($value);
        ksort($data);

        return $data;
    }
}
