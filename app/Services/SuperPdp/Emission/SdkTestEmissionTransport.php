<?php

namespace App\Services\SuperPdp\Emission;

use Bluerock\SuperPdp\Requests\InvoiceEvents\ListInvoiceEventsRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\GetInvoiceRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** Adaptateur SDK strictement réservé à testing et à des réponses en mémoire. */
final class SdkTestEmissionTransport implements EmissionTransport
{
    private SuperPdpConnector $connector;

    private MockClient $mock;

    public function __construct(array $responses)
    {
        $this->assertTesting();
        foreach ($responses as $key => $response) {
            if (! $response instanceof MockResponse || (! is_int($key)
                && ! in_array($key, [GetInvoiceRequest::class, CreateInvoiceRequest::class, ListInvoiceEventsRequest::class], true))) {
                throw new RuntimeException('Réponses en mémoire uniquement, sans fixture enregistrable ni callback.');
            }
        }
        $this->mock = new MockClient($responses);
        $this->connector = (new SuperPdpConnector)->withMockClient($this->mock);
        $this->connector->config()->merge(['allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
    }

    public function mode(): string
    {
        return 'test';
    }

    public function send(EmissionDocument $document, string $reference): array
    {
        $this->assertTesting();
        EmissionDocument::assertReference($document->reference, $document->userId, $document->invoiceId);
        $response = $this->connector->invoice()->send($document->xml, 'application/xml', $reference, false);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    public function lookup(int $invoiceId): array
    {
        $this->assertTesting();
        $response = $this->connector->invoice()->show($invoiceId);
        if ($response->status() !== 200) {
            throw new RuntimeException('Lecture distante incertaine.');
        }

        return EmissionJson::object($response->body());
    }

    public function original(int $invoiceId): string
    {
        $this->assertTesting();
        // /download du SDK n'est pas dans le contrat local ; GET format=original est documenté.
        $request = new GetInvoiceRequest($invoiceId);
        $request->query()->add('format', 'original');
        $request->headers()->add('Accept', 'application/xml');
        $response = $this->connector->send($request);
        if ($response->status() !== 200 || strlen($response->body()) > 5242880) {
            throw new RuntimeException('Original non vérifiable.');
        }

        return $response->body();
    }

    public function events(int $invoiceId, ?int $cursor): array
    {
        $this->assertTesting();
        $response = $this->connector->invoiceEvent()->index($invoiceId, $cursor, 100);
        if ($response->status() !== 200) {
            throw new RuntimeException('Événements indisponibles.');
        }

        // Ne pas passer par dto() : le SDK perd le marqueur has_after.
        return EmissionJson::object($response->body());
    }

    public function requests(): array
    {
        return array_map(function ($response) {
            $request = $response->getPendingRequest();

            return ['method' => $request->getMethod()->value, 'url' => $request->getUrl(),
                'query' => $request->query()->all(), 'body' => $request->body()?->all()];
        }, $this->mock->getRecordedResponses());
    }

    private function assertTesting(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Adaptateur interdit hors tests.');
        }
    }
}
