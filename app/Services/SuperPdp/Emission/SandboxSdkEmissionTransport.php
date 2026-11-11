<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use Bluerock\SuperPdp\Requests\InvoiceEvents\ListInvoiceEventsRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\GetInvoiceRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Throwable;

/** Real SDK adapter, scoped to one sandbox document. No refresh, retry or redirect. */
final class SandboxSdkEmissionTransport implements EmissionTransport
{
    public function __construct(
        private readonly int $userId,
        private readonly int $invoiceId,
        private readonly string $documentReference,
        private readonly SandboxEmissionAuthorization $authorization,
        private readonly SandboxEmissionPrerequisites $prerequisites,
    ) {}

    public function mode(): string
    {
        return 'sandbox';
    }

    public function assertReady(): SuperPdpConnection
    {
        $this->authorization->approval();
        $invoice = Invoice::findOrFail($this->invoiceId);
        if ($invoice->user_id !== $this->userId || ! $invoice->isIssued()) {
            throw new RuntimeException('Sandbox invoice ownership or status mismatch.');
        }

        return $this->authorization->connection($this->userId, $this->invoiceId, $this->documentReference);
    }

    public function assertAttemptAuthorized(string $reference): void
    {
        $this->assertReady();
        $this->authorization->attempt($reference);
    }

    public function send(EmissionDocument $document, string $reference): array
    {
        $connection = $this->assertReady();
        $this->assertDeposit($document, $reference);
        $connector = $this->connector($connection, fn () => $this->assertDeposit($document, $reference));
        $response = $this->request(fn () => $connector->invoice()->send($document->xml, 'application/xml', $reference, false));

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    public function lookup(int $invoiceId): array
    {
        $connector = $this->readConnector($invoiceId);
        $response = $this->request(fn () => $connector->invoice()->show($invoiceId));
        $this->successfulRead($response);

        return EmissionJson::object($response->body());
    }

    public function original(int $invoiceId): string
    {
        $connector = $this->readConnector($invoiceId);
        $request = new GetInvoiceRequest($invoiceId);
        $request->query()->add('format', 'original');
        $request->headers()->add('Accept', 'application/xml');
        $response = $this->request(fn () => $connector->send($request));
        $this->successfulRead($response);
        if ($response->body() === '' || strlen($response->body()) > 5242880) {
            throw new RuntimeException('Original sandbox document unavailable.');
        }

        return $response->body();
    }

    public function events(int $invoiceId, ?int $cursor): array
    {
        $connector = $this->readConnector($invoiceId, true);
        $response = $this->request(fn () => $connector->invoiceEvent()->index($invoiceId, $cursor, 100));
        $this->successfulRead($response);

        return EmissionJson::object($response->body());
    }

    private function assertDeposit(EmissionDocument $document, string $reference): void
    {
        $this->assertReady();
        $this->authorization->attempt($reference);
        $this->authorization->document($document);
        $actual = $this->prerequisites->authorize(User::findOrFail($this->userId), Invoice::findOrFail($this->invoiceId), $this->documentReference);
        if ($document->userId !== $actual->userId || $document->invoiceId !== $actual->invoiceId
            || $document->reference !== $actual->reference || $document->companyId !== $actual->companyId
            || $document->environment !== 'sandbox' || ! hash_equals($actual->sha256, $document->sha256)
            || ! hash_equals($actual->xml, $document->xml)) {
            throw new RuntimeException('Exact validated sandbox archive required.');
        }
        $record = $this->record();
        $attempt = $record->attempts()->where('reference', $reference)->firstOrFail();
        if ($record->status !== 'pending' || $attempt->status !== 'pending' || $attempt->transport_started_at === null
            || $attempt->claim_token === null || $attempt->cii_sha256 !== $actual->sha256
            || $record->cii_sha256 !== $actual->sha256 || $record->getConnection()->transactionLevel() !== 0) {
            throw new RuntimeException('Committed claimed sandbox attempt required before deposit.');
        }
        if ((int) ElectronicInvoiceAttempt::where('electronic_invoice_id', $record->id)->max('number') !== $attempt->number) {
            throw new RuntimeException('Stale sandbox attempt refused.');
        }
    }

    private function record(): ElectronicInvoice
    {
        $record = ElectronicInvoice::where('invoice_id', $this->invoiceId)->where('dispatch_key', 'superpdp:'.$this->invoiceId)->firstOrFail();
        $this->assertRegistryScope($record);

        return $record;
    }

    public function assertRegistryScope(ElectronicInvoice $record): void
    {
        $connection = $this->assertReady();
        if ($record->invoice_id !== $this->invoiceId || $record->execution_mode !== 'sandbox' || $record->environment !== 'sandbox'
            || $record->provider_company_id !== $connection->superpdp_company_id || $record->document_reference !== $this->documentReference
            || $record->cii_sha256 !== ($this->authorization->approval()['cii_sha256'] ?? null)) {
            throw new RuntimeException('Sandbox registry context mismatch.');
        }
    }

    private function readConnector(int $remoteId, bool $acceptedOnly = false): SuperPdpConnector
    {
        $connection = $this->assertReady();
        $this->assertRead($remoteId, $acceptedOnly);

        return $this->connector($connection, fn () => $this->assertRead($remoteId, $acceptedOnly));
    }

    private function assertRead(int $remoteId, bool $acceptedOnly): void
    {
        $record = $this->record();
        if ($remoteId <= 0 || ($record->provider_invoice_id !== null && $record->provider_invoice_id !== $remoteId)
            || ! in_array($record->status, $acceptedOnly ? ['accepted'] : ['accepted', 'unknown'], true)
            || ($acceptedOnly && $record->provider_invoice_id !== $remoteId)) {
            throw new RuntimeException('Sandbox remote document not in the authorized registry.');
        }
    }

    private function connector(SuperPdpConnection $connection, callable $beforeRequest): SuperPdpConnector
    {
        $fingerprint = hash('sha256', $connection->access_token);
        // The SDK endpoints already include /v1.beta: pass the origin, never append it twice.
        $connector = SuperPdpConnector::withToken($connection->access_token, SandboxEmissionAuthorization::ORIGIN);
        $connector->tries = 1;
        $connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
        $connector->middleware()->onRequest(function (PendingRequest $request) use ($fingerprint, $beforeRequest) {
            $current = $this->assertReady();
            if (! hash_equals($fingerprint, hash('sha256', $current->access_token))) {
                throw new RuntimeException('OAuth credentials changed before sandbox request.');
            }
            $beforeRequest();
            if (! in_array($request->getRequest()::class, [CreateInvoiceRequest::class, GetInvoiceRequest::class, ListInvoiceEventsRequest::class], true)
                || ! str_starts_with($request->getUrl(), SandboxEmissionAuthorization::API_URL.'/')) {
                throw new RuntimeException('Sandbox SDK operation forbidden.');
            }
        });

        return $connector;
    }

    private function request(#[\SensitiveParameter] callable $operation): Response
    {
        try {
            return $operation();
        } catch (Throwable) {
            // No response body, XML, token or original HTTP exception in logs.
            throw new RuntimeException('Sandbox SDK request failed; deposit outcome may be unknown.');
        }
    }

    private function successfulRead(Response $response): void
    {
        if ($response->status() !== 200) {
            throw new RuntimeException('Sandbox read failed.');
        }
    }
}
