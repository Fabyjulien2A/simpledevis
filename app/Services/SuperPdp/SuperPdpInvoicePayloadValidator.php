<?php

namespace App\Services\SuperPdp;

use Illuminate\Validation\ValidationException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

/**
 * Validation structurelle locale, distincte des contrôles métier/fiscaux.
 * Ne valide ni EN 16931, ni un circuit français. Aucun accès réseau ou base.
 */
class SuperPdpInvoicePayloadValidator
{
    public const SCHEMA_VERSION = '1.39.0.beta';

    private readonly object $schema;

    private readonly Validator $validator;

    public function __construct(?string $schemaPath = null)
    {
        $this->schema = json_decode(
            file_get_contents($schemaPath ?? resource_path('schemas/superpdp/'.self::SCHEMA_VERSION.'/en-invoice.schema.json')),
            false, 512, JSON_THROW_ON_ERROR
        );
        // A null resolver means external references cannot be fetched, even accidentally.
        $this->validator = new Validator(new SchemaLoader, max_errors: 100, stop_at_first_error: false);
    }

    /**
     * Valide les types réellement sérialisés, sans coercition objet/tableau ni mutation.
     * Les erreurs Laravel sont indexées par chemin : payload.lines.0.net_amount, etc.
     */
    public function validate(mixed $payload): void
    {
        try {
            $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ValidationException::withMessages(['payload' => '[json] '.$exception->getMessage()]);
        }

        $result = $this->validator->validate($json, $this->schema);
        if ($result->isValid()) {
            return;
        }

        $formatter = new ErrorFormatter;
        $errors = [];
        foreach ($formatter->formatFlat($result->error(), fn (ValidationError $error) => $error) as $error) {
            if ($error->subErrors() !== []) {
                continue;
            }
            $path = array_merge(['payload'], $error->data()->fullPath());
            $properties = match ($error->keyword()) {
                'required' => $error->args()['missing'],
                'additionalProperties' => $error->args()['properties'],
                default => [null],
            };
            foreach ($properties as $property) {
                $key = implode('.', $property === null ? $path : [...$path, $property]);
                $errors[$key][] = '['.$error->keyword().'] '.$formatter->formatErrorMessage($error);
            }
        }

        throw ValidationException::withMessages($errors);
    }
}
