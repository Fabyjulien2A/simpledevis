<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use InvalidArgumentException;

/**
 * Explicit conventions observed in factures-test/reference-superpdp-tricatel.xml
 * and approved for the inverse scenario. Not legal identities or deposit evidence.
 * Keep the original SandboxEmissionContext declaration and production contracts intact.
 */
final readonly class SandboxCiiProfile
{
    public const PROFILE = 'superpdp_sandbox_burger_queen_tricatel_cii_v1';

    private const PARTIES = [
        'seller' => [
            'name' => 'Burger Queen',
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => '000000002'],
            'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18172'],
            'vat_identifier' => 'FR18000000002',
        ],
        'buyer' => [
            'name' => 'Tricatel',
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => '000000001'],
            'electronic_address' => ['scheme' => '0225', 'value' => '315143296_18171'],
            'vat_identifier' => 'FR15000000001',
        ],
    ];

    private function __construct(private array $parties) {}

    public static function forContext(SandboxEmissionContext $context, Company $company): self
    {
        $context->assertFor($company);
        $declaration = $context->toArray();
        foreach (self::PARTIES as $role => $party) {
            $declared = $declaration[$role];
            if ($declared['name'] !== $party['name']
                || $declared['superpdp_identity'] !== ['number_scheme' => 'sandbox', 'number' => $party['legal_registration_identifier']['value']]
                || $declared['electronic_address'] !== $party['electronic_address']
                || $declared['legal_registration_identity'] !== null
                || str_contains(strtolower($party['electronic_address']['value']), '_replyto')
                || $declaration['ready_for_emission'] !== false) {
                throw new InvalidArgumentException('Le profil CII sandbox ne correspond pas au contexte autorisé.');
            }
        }

        return new self(self::PARTIES);
    }

    public function toArray(): array
    {
        return $this->parties;
    }
}
