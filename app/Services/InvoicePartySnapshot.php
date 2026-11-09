<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use stdClass;

/**
 * Capture PHP pure de valeurs fournies explicitement, sans lecture de modèles.
 * Ne prouve ni leur provenance, ni une finalisation, ni une conformité ou un routage.
 * Contrat : docs/invoice-party-snapshot.md.
 */
final readonly class InvoicePartySnapshot implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;
    public const PRIVATE_LOGO_SCHEMA_VERSION = 2;

    private const SELLER_FIELDS = [
        'company_name', 'name', 'user_name', 'legal_status',
        'siret', 'vat_number', 'tva_number', 'vat_regime',
        'address', 'postal_code', 'city', 'country_code', 'email', 'phone',
        'iban', 'bic', 'payment_terms',
        'invoice_recovery_indemnity_text', 'invoice_late_payment_penalties_text',
        'invoice_early_payment_discount_text', 'logo',
    ];

    private const BUYER_FIELDS = [
        'company_name', 'first_name', 'last_name', 'full_name', 'client_type',
        'siret', 'vat_number', 'address', 'postal_code', 'city',
        'country_code', 'email', 'phone',
    ];

    private function __construct(private array $seller, private array $buyer, private int $schemaVersion) {}

    /**
     * Chaque champ reconnu est une chaîne UTF-8 ou null, sans qualification métier.
     * Une clé absente n'est jamais ajoutée ; les chaînes vides et espaces restent intacts.
     * Les champs inconnus ou types incompatibles sont refusés, jamais ignorés ou convertis.
     *
     * @param  array<string, string|null>  $seller
     * @param  array<string, string|null>  $buyer
     */
    public static function capture(array $seller, array $buyer, int $schemaVersion = self::SCHEMA_VERSION): self
    {
        if (! in_array($schemaVersion, [self::SCHEMA_VERSION, self::PRIVATE_LOGO_SCHEMA_VERSION], true)) {
            throw new InvalidArgumentException('Version de capture non prise en charge.');
        }
        $seller = self::captureParty($seller, self::SELLER_FIELDS, 'vendeur');
        if ($schemaVersion === self::PRIVATE_LOGO_SCHEMA_VERSION && isset($seller['logo'])) {
            InvoiceLogoReference::parse($seller['logo']);
        }

        return new self(
            $seller,
            self::captureParty($buyer, self::BUYER_FIELDS, 'acheteur'),
            $schemaVersion,
        );
    }

    /** Relit le JSON brut : le cast array Eloquent perd la distinction entre {} et []. */
    public static function fromJson(mixed $json): self
    {
        if ($json === null) {
            throw new InvoicePartySnapshotException('missing');
        }
        if (! is_string($json)) {
            throw new InvoicePartySnapshotException('invalid');
        }
        try {
            $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvoicePartySnapshotException('invalid');
        }
        self::assertUniqueJsonKeys($json);
        if (! $data instanceof stdClass || ! is_int($data->schema_version ?? null)) {
            throw new InvoicePartySnapshotException('invalid');
        }
        if (! in_array($data->schema_version, [self::SCHEMA_VERSION, self::PRIVATE_LOGO_SCHEMA_VERSION], true)) {
            throw new InvoicePartySnapshotException('unsupported_version');
        }
        if (count(get_object_vars($data)) !== 4
            || ! ($data->seller ?? null) instanceof stdClass
            || ! ($data->buyer ?? null) instanceof stdClass
            || ! ($data->electronic_qualification ?? null) instanceof stdClass
            || get_object_vars($data->electronic_qualification) !== ['status' => 'not_assessed']) {
            throw new InvoicePartySnapshotException('invalid');
        }
        try {
            return self::capture(get_object_vars($data->seller), get_object_vars($data->buyer), $data->schema_version);
        } catch (InvalidArgumentException) {
            throw new InvoicePartySnapshotException('invalid');
        }
    }

    /** @return array{schema_version: int, seller: array, buyer: array, electronic_qualification: array{status: string}} */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'seller' => $this->seller,
            'buyer' => $this->buyer,
            'electronic_qualification' => ['status' => 'not_assessed'],
        ];
    }

    /** Les parties sont toujours des objets JSON, même lorsqu'aucun champ n'est fourni. */
    public function jsonSerialize(): array
    {
        return array_replace($this->toArray(), [
            'seller' => (object) $this->seller,
            'buyer' => (object) $this->buyer,
        ]);
    }

    private static function captureParty(array $values, array $fields, string $party): array
    {
        if (array_diff(array_keys($values), $fields) !== []) {
            throw new InvalidArgumentException('Champ de capture '.$party.' non pris en charge.');
        }

        $captured = [];
        // Ordre stable du contrat, indépendant de l'ordre des clés fourni par l'appelant.
        foreach ($fields as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if ($value !== null && (! is_string($value) || preg_match('//u', $value) !== 1)) {
                throw new InvalidArgumentException('Chaque valeur de capture '.$party.' doit être une chaîne UTF-8 ou null.');
            }
            $captured[$field] = $value;
        }

        return $captured;
    }

    private static function assertUniqueJsonKeys(string $json): void
    {
        // MariaDB et PHP peuvent interpréter différemment les clés JSON dupliquées.
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]~s', $json, $matches, PREG_OFFSET_CAPTURE);
        $stack = [];
        foreach ($matches[0] as [$token, $offset]) {
            if ($token === '{' || $token === '[') {
                $stack[] = $token === '{' ? [] : null;
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (preg_match('/\A\s*:/', substr($json, $offset + strlen($token)))) {
                $index = array_key_last($stack);
                $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                if (isset($stack[$index][$key])) {
                    throw new InvoicePartySnapshotException('invalid');
                }
                $stack[$index][$key] = true;
            }
        }
    }
}
