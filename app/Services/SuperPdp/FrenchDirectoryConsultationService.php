<?php

namespace App\Services\SuperPdp;

use Bluerock\SuperPdp\Resources\FrenchDirectoryResource;
use InvalidArgumentException;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use stdClass;

/**
 * Consultation explicite uniquement, sans modèle, OAuth, écriture ou émission.
 * Injecter une ressource SDK dédiée ; aucune activation dans les parcours métier.
 * Les lignes, même actives, ne constituent ni une sélection ni une preuve de routage.
 */
final class FrenchDirectoryConsultationService
{
    public function __construct(private readonly FrenchDirectoryResource $directory) {}

    /**
     * @return array{data: list<array>, has_more: bool}
     */
    public function searchCompanies(mixed $siren): array
    {
        return $this->consult($this->validateSiren($siren), false);
    }

    /**
     * @return array{data: list<array>, has_more?: bool}
     */
    public function listEntries(mixed $siren): array
    {
        return $this->consult($this->validateSiren($siren), true);
    }

    private function validateSiren(mixed $siren): string
    {
        if (! is_string($siren) || ! preg_match('/\A[0-9]{9}\z/', $siren)) {
            throw new InvalidArgumentException('Le SIREN doit être une chaîne de neuf chiffres, sans espaces.');
        }

        return $siren;
    }

    private function consult(string $siren, bool $entries): array
    {
        try {
            $response = $entries
                ? $this->directory->entries($siren)
                : $this->directory->companies(number: $siren);
        } catch (RequestException $exception) {
            throw $this->httpError($exception->getStatus());
        } catch (FatalRequestException) {
            throw new FrenchDirectoryConsultationException('unavailable');
        }

        if ($response->status() !== 200) {
            throw $this->httpError($response->status());
        }

        // Ne pas appeler dto() : EntityCollection ne conserve que data et perd has_more.
        // Décoder en objets distingue notamment data: {} de la liste vide data: [].
        try {
            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FrenchDirectoryConsultationException('malformed_response');
        }
        if (! $body instanceof stdClass || ! isset($body->data) || ! is_array($body->data)) {
            throw new FrenchDirectoryConsultationException('malformed_response');
        }
        if ((! $entries || property_exists($body, 'has_more')) && ! is_bool($body->has_more ?? null)) {
            throw new FrenchDirectoryConsultationException('malformed_response');
        }
        if ($entries && array_diff(array_keys(get_object_vars($body)), ['data', 'has_more']) !== []) {
            // Ne pas perdre silencieusement un nouveau marqueur de pagination du fournisseur.
            throw new FrenchDirectoryConsultationException('malformed_response');
        }

        $data = [];
        foreach ($body->data as $item) {
            $data[] = $entries ? $this->entry($item, $siren) : $this->company($item, $siren);
        }
        $result = ['data' => $data];
        if (property_exists($body, 'has_more')) {
            $result['has_more'] = $body->has_more;
        }

        // Aucun suivi automatique de pagination ni construction d'adresse EN16931.
        return $result;
    }

    private function company(mixed $company, string $siren): array
    {
        $result = [];
        foreach (['number', 'formal_name', 'address', 'postcode', 'city', 'country'] as $field) {
            if (! $company instanceof stdClass || ! is_string($company->{$field} ?? null)) {
                throw new FrenchDirectoryConsultationException('malformed_response');
            }
            $result[$field] = $company->{$field};
        }
        if ($result['number'] !== $siren) {
            throw new FrenchDirectoryConsultationException('malformed_response');
        }

        return $result;
    }

    private function entry(mixed $entry, string $siren): array
    {
        if (! $entry instanceof stdClass || ! is_string($entry->identifier ?? null)
            || preg_match('/[^\s\p{Z}]/u', $entry->identifier) !== 1
            || ! is_bool($entry->is_active ?? null)) {
            throw new FrenchDirectoryConsultationException('malformed_response');
        }

        return [
            'company' => $this->company($entry->company ?? null, $siren),
            'identifier' => $entry->identifier,
            'is_active' => $entry->is_active,
        ];
    }

    private function httpError(int $status): FrenchDirectoryConsultationException
    {
        return new FrenchDirectoryConsultationException(
            in_array($status, [401, 403], true) ? 'authentication' : 'api_error', $status
        );
    }
}
