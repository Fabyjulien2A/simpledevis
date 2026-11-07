<?php

namespace App\Services\SuperPdp\Sandbox;

use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Essai isolé : uniquement /invoices/convert, données fictives fixes, aucune persistance.
 * OpenAPI 1.39.0.beta : security: [] sur ConvertInvoice, donc aucun jeton OAuth nécessaire.
 */
class SandboxConversionProbe
{
    public const URL = 'https://api.superpdp.tech/v1.beta/invoices/convert?from=en16931&to=cii';

    public function __construct(
        private readonly SandboxInvoiceFixture $fixture,
        private readonly InvoiceFiscalDataPreparer $fiscalPreparation,
        private readonly SuperPdpInvoicePayloadValidator $payloadValidator,
    ) {}

    public function run(bool $execute = false, string $scenario = 'standard'): array
    {
        $fixture = match ($scenario) {
            'standard' => $this->fixture,
            'franchise' => new SandboxFranchiseInvoiceFixture,
            'multi-rate' => new SandboxMultiRateInvoiceFixture,
            'mixed' => new SandboxMixedInvoiceFixture,
            default => throw new RuntimeException('Scénario inconnu : choisir standard, franchise, multi-rate ou mixed.'),
        };
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Cet outil sandbox est réservé aux environnements local et testing.');
        }
        if ($execute && config('superpdp_sandbox.conversion_enabled') !== true) {
            throw new RuntimeException('Activer SUPERPDP_SANDBOX_CONVERSION_ENABLED=true avant un appel explicite.');
        }
        if (rtrim((string) config('services.superpdp.api_url'), '/') !== 'https://api.superpdp.tech/v1.beta') {
            throw new RuntimeException('L’outil accepte uniquement l’URL de conversion officielle SUPER PDP.');
        }

        $builder = new SuperPdpInvoicePayloadBuilder($fixture, $this->fiscalPreparation, $this->payloadValidator);
        $payload = $builder->build(
            $fixture->invoice(),
            ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
            ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'],
            frenchContext: $fixture->frenchContext(),
        );
        $result = ['mode' => 'simulation', 'status' => null, 'payload' => $payload, 'xml' => null, 'diagnostic' => null];
        if (! $execute) {
            return $result;
        }

        try {
            // Pas d'authentification, de renouvellement, de redirection ou de retry automatique.
            $response = Http::asJson()->accept('application/xml')->timeout(30)->connectTimeout(10)
                ->withoutRedirecting()->post(self::URL, $payload);
        } catch (ConnectionException) {
            return array_replace($result, ['mode' => 'transport_error', 'diagnostic' => 'Échec de connexion ou délai dépassé ; aucune nouvelle tentative automatique.']);
        }

        $result['status'] = $response->status();
        if ($response->status() !== 200) {
            return array_replace($result, ['mode' => 'http_error', 'diagnostic' => $this->diagnostic($response->body())]);
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($contentType !== 'application/xml' || ! $this->isCiiXml($response->body())) {
            return array_replace($result, [
                'mode' => 'invalid_response',
                'diagnostic' => 'Réponse 200 inattendue : CII XML attendu. '.$this->diagnostic($response->body()),
            ]);
        }

        return array_replace($result, ['mode' => 'converted', 'xml' => $this->redact($response->body())]);
    }

    private function isCiiXml(string $body): bool
    {
        if ($body === '' || stripos($body, '<!DOCTYPE') !== false) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;

            return $document->loadXML($body, LIBXML_NONET)
                && $document->documentElement?->localName === 'CrossIndustryInvoice';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function diagnostic(string $body): string
    {
        $body = substr($body, 0, 65536);
        $decoded = json_decode($body, true, 32);
        if (is_array($decoded)) {
            $clean = function (mixed $value, string $key = '') use (&$clean): mixed {
                if (preg_match('/token|authorization|secret|password|api[_-]?key/i', $key)) {
                    return '[masqué]';
                }
                if (is_array($value)) {
                    foreach ($value as $childKey => $childValue) {
                        $value[$childKey] = $clean($childValue, (string) $childKey);
                    }

                    return $value;
                }

                return is_string($value) ? $this->redact($value) : $value;
            };
            $body = json_encode($clean($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return mb_substr($this->redact($body), 0, 8000);
    }

    private function redact(string $text): string
    {
        $secret = config('services.superpdp.client_secret');
        if (is_string($secret) && $secret !== '') {
            $text = str_replace($secret, '[masqué]', $text);
        }
        $text = preg_replace('/\bBearer\s+[^\s"<>]+/i', 'Bearer [masqué]', $text);
        // Also mask quoted token fields in malformed/truncated JSON which could not be decoded.
        $text = preg_replace('/("(?:access_token|refresh_token|client_secret|authorization)"\s*:\s*)"[^"\r\n]*(?:"|$)/i', '$1"[masqué]"', $text);
        $text = preg_replace('/\b(access_token|refresh_token|client_secret|authorization)\s*[:=]\s*[^\s,;<>]+/i', '$1=[masqué]', $text);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }
}
