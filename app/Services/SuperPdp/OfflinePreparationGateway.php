<?php

namespace App\Services\SuperPdp;

use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Http\PendingRequest;
use Saloon\Repositories\Body\MultipartBodyRepository;
use RuntimeException;

/** SDK réel, réponses obligatoirement en mémoire. Aucun jeton, sender réseau ou dépôt. */
final class OfflinePreparationGateway
{
    private readonly SuperPdpConnector $connector;
    private readonly MockClient $mock;

    public function __construct(array $responses)
    {
        foreach ($responses as $request => $response) {
            if (! in_array($request, [ListFrenchDirectoryEntriesRequest::class, GetCompanyMeRequest::class,
                GetSessionMeRequest::class, ConvertInvoiceRequest::class, CreateValidationReportRequest::class], true)
                || ! $response instanceof MockResponse) {
                // Refuser aussi les fixtures enregistrables et les callbacks pouvant émettre du réseau.
                throw new RuntimeException('Seules des réponses simulées en mémoire des cinq opérations de préparation sont autorisées.');
            }
        }
        $this->mock = new MockClient($responses);
        $this->connector = (new SuperPdpConnector)->withMockClient($this->mock);
        $this->connector->config()->merge(['allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
        $this->connector->middleware()->onRequest(function (PendingRequest $request) {
            if ($request->getRequest() instanceof ConvertInvoiceRequest) {
                $request->headers()->add('Accept', 'application/xml');
            }
            if ($request->getRequest() instanceof CreateValidationReportRequest && $request->body() instanceof MultipartBodyRepository) {
                // Ne pas laisser le Content-Type JSON par défaut du connecteur masquer le multipart.
                $request->headers()->add('Content-Type', 'multipart/form-data; boundary='.$request->body()->getBoundary());
            }
        });
    }

    public function directory(string $siren): array
    {
        return (new FrenchDirectoryConsultationService($this->connector->frenchDirectory()))->listEntries($siren);
    }

    public function company(): array
    {
        return $this->json($this->connector->company()->me());
    }

    public function session(): array
    {
        return $this->json($this->connector->session()->me());
    }

    public function convert(array $payload): string
    {
        $response = $this->connector->invoice()->convert(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'en16931', 'cii', 'application/json',
        );
        $this->assertSuccess($response);
        if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/xml') {
            throw new RuntimeException('La conversion n’a pas retourné un document application/xml.');
        }

        return $response->body();
    }

    public function validate(string $xml): array
    {
        return $this->json($this->connector->invoice()->validate(['invoice.xml' => $xml]));
    }

    /** Journal local de tests : jamais un journal des en-têtes d'authentification. */
    public function requests(): array
    {
        return array_map(function (Response $response) {
            $request = $response->getPendingRequest();

            return ['class' => $request->getRequest()::class, 'method' => $request->getMethod()->value,
                'url' => $request->getUrl(), 'query' => $request->query()->all(),
                'content_type' => $request->headers()->get('Content-Type'), 'accept' => $request->headers()->get('Accept'),
                'body' => $request->body()?->all()];
        }, $this->mock->getRecordedResponses());
    }

    private function json(Response $response): array
    {
        $this->assertSuccess($response);
        if (strlen($response->body()) > 1048576) {
            throw new RuntimeException('Réponse JSON trop volumineuse.');
        }
        try {
            $object = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                throw new RuntimeException;
            }

            return json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException|RuntimeException) {
            throw new RuntimeException('Réponse JSON de préparation invalide.');
        }
    }

    private function assertSuccess(Response $response): void
    {
        if ($response->status() !== 200) {
            // Ne jamais propager le corps distant, des secrets ou des données d'un autre compte.
            throw new RuntimeException('Opération de préparation refusée (HTTP '.$response->status().').');
        }
    }
}
