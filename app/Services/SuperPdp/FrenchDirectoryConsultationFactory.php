<?php

namespace App\Services\SuperPdp;

use Bluerock\SuperPdp\SuperPdpConnector;
use InvalidArgumentException;

/** Transport dédié aux GET publics d'annuaire ; aucun accès aux jetons de réception. */
final class FrenchDirectoryConsultationFactory
{
    public function create(): FrenchDirectoryConsultationService
    {
        $url = config('services.superpdp.directory_base_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw new InvalidArgumentException('L’URL de consultation doit être une origine HTTPS sans chemin ni identifiants.');
        }

        // Les requêtes du SDK ajoutent déjà /v1.beta : ne pas reprendre api_url de réception.
        $connector = new SuperPdpConnector(baseUrl: rtrim($url, '/'));
        $connector->config()->merge([
            'timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false,
        ]);

        return new FrenchDirectoryConsultationService($connector->frenchDirectory());
    }
}
