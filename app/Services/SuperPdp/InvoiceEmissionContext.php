<?php

namespace App\Services\SuperPdp;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Contrat séparé des instantanés v1/v2 : déclaration figée, jamais une autorisation d'envoi. */
final class InvoiceEmissionContext
{
    public const PROFILE = 'fr_b2b_380_eur';

    public static function capture(?string $profile, mixed $sellerAddress, mixed $buyerAddress, array $parties): ?array
    {
        if ($profile === null) {
            return null;
        }
        if ($profile !== self::PROFILE) {
            throw new InvalidArgumentException('Périmètre d’émission non pris en charge.');
        }

        return [
            'schema_version' => 1,
            'profile' => $profile,
            'document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR',
            'seller_electronic_address' => DeclaredBillingAddress::validate($sellerAddress, $parties['seller']['siret'] ?? null),
            'buyer_electronic_address' => DeclaredBillingAddress::validate($buyerAddress, $parties['buyer']['siret'] ?? null),
            'routing_status' => 'not_verified',
        ];
    }

    public static function fromJson(mixed $json, array $parties): array
    {
        try {
            if (! is_string($json)) {
                throw new InvalidArgumentException;
            }
            $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            if (! $object instanceof stdClass) {
                throw new InvalidArgumentException;
            }
            $data = get_object_vars($object);
            if (count($data) !== 8 || ($data['schema_version'] ?? null) !== 1
                || ($data['profile'] ?? null) !== self::PROFILE
                || ($data['document_kind'] ?? null) !== 'ordinary' || ($data['type_code'] ?? null) !== 380
                || ($data['currency_code'] ?? null) !== 'EUR' || ($data['routing_status'] ?? null) !== 'not_verified') {
                throw new InvalidArgumentException;
            }
            foreach (['seller', 'buyer'] as $party) {
                $key = $party.'_electronic_address';
                if (! array_key_exists($key, $data)) {
                    throw new InvalidArgumentException;
                }
                if ($data[$key] !== null) {
                    if (! $data[$key] instanceof stdClass || count(get_object_vars($data[$key])) !== 2) {
                        throw new InvalidArgumentException;
                    }
                    $data[$key] = get_object_vars($data[$key]);
                }
            }
            $expected = self::capture($data['profile'], $data['seller_electronic_address'], $data['buyer_electronic_address'], $parties);
            if ($data != $expected) {
                throw new InvalidArgumentException;
            }
            // Refuser les clés JSON dupliquées, y compris après décodage des échappements.
            preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]~s', $json, $matches, PREG_OFFSET_CAPTURE);
            $stack = [];
            foreach ($matches[0] as [$token, $offset]) {
                if ($token === '{' || $token === '[') {
                    $stack[] = $token === '{' ? [] : null;
                } elseif ($token === '}' || $token === ']') {
                    array_pop($stack);
                } elseif (preg_match('/\A\s*:/', substr($json, $offset + strlen($token)))) {
                    $index = array_key_last($stack);
                    $key = json_decode($token, true, 16, JSON_THROW_ON_ERROR);
                    if (isset($stack[$index][$key])) {
                        throw new InvalidArgumentException;
                    }
                    $stack[$index][$key] = true;
                }
            }

            return $expected;
        } catch (JsonException|InvalidArgumentException|ValidationException) {
            throw new InvalidArgumentException('Contexte d’émission invalide ou version non prise en charge. Aucun profil actuel ne peut le remplacer.');
        }
    }

    public static function resolve(array $data, array $supplied): array
    {
        if (($data['emission_context'] ?? null) === null) {
            if (($data['emission_profile'] ?? null) !== null) {
                throw new InvalidArgumentException('Le contexte d’émission figé est absent.');
            }
            return $supplied; // Compatibilité des scénarios et instantanés existants.
        }
        $stored = self::fromJson($data['emission_context'], $data);
        if (array_key_exists('emission_profile', $data) && $data['emission_profile'] !== $stored['profile']) {
            throw new InvalidArgumentException('Le périmètre enregistré contredit le contexte d’émission figé.');
        }
        foreach (['document_kind', 'type_code', 'currency_code', 'seller_electronic_address', 'buyer_electronic_address'] as $key) {
            if (array_key_exists($key, $supplied) && $supplied[$key] !== $stored[$key]) {
                throw new InvalidArgumentException('Le contexte fourni contredit les données d’émission figées : '.$key.'.');
            }
            $supplied[$key] = $stored[$key];
        }

        return $supplied;
    }
}
