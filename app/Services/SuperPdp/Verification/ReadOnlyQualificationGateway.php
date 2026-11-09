<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\FrenchDirectoryConsultationException;
use App\Services\SuperPdp\FrenchDirectoryConsultationService;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\FrenchDirectory\ListFrenchDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Throwable;

/** Quatre GET, aucun dépôt, conversion, refresh OAuth ou accès aux curseurs de réception. */
final class ReadOnlyQualificationGateway
{
    private SuperPdpConnector $public;

    private SuperPdpConnector $authenticated;

    private ?Response $lastResponse = null;

    private ?MockClient $mock = null;

    private function __construct(
        public readonly int $userId,
        public readonly int $invoiceId,
        public readonly ?int $expectedCompanyId,
        public readonly string $environment,
        public readonly string $expectedClientId,
        private readonly bool $liveEnabled,
        private readonly ?string $credentialError,
        #[\SensitiveParameter] ?string $token,
        ?array $responses,
    ) {
        $this->public = new SuperPdpConnector;
        $this->authenticated = $token === null ? new SuperPdpConnector : SuperPdpConnector::withToken($token);
        if ($responses !== null) {
            $allowed = [ListFrenchDirectoryEntriesRequest::class, GetCompanyMeRequest::class, GetSessionMeRequest::class, ListDirectoryEntriesRequest::class];
            foreach ($responses as $request => $response) {
                if (! in_array($request, $allowed, true) || ! $response instanceof MockResponse) {
                    throw new VerificationReadException('unavailable', 'invalid_mock_configuration');
                }
            }
            $this->mock = new MockClient($responses);
        }
        foreach ([$this->public, $this->authenticated] as $connector) {
            if ($this->mock !== null) {
                $connector->withMockClient($this->mock);
            }
            $connector->tries = 1;
            $connector->config()->merge(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]);
            $connector->middleware()->onRequest(function (PendingRequest $request) use ($connector) {
                if ($request->getMethod() !== Method::GET
                    || ! in_array($request->getRequest()::class, $connector === $this->public
                        ? [ListFrenchDirectoryEntriesRequest::class]
                        : [GetCompanyMeRequest::class, GetSessionMeRequest::class, ListDirectoryEntriesRequest::class], true)) {
                    throw new VerificationReadException('unavailable', 'operation_not_allowed');
                }
            });
            $connector->middleware()->onResponse(function (Response $response) {
                $this->lastResponse = $response;
                if ($response->status() === 200) {
                    try {
                        EmissionJson::object($response->body());
                    } catch (Throwable) {
                        throw new VerificationReadException('ambiguous', 'malformed_or_duplicate_json');
                    }
                }
            });
        }
    }

    public static function forInvoice(User $actor, Invoice $invoice, string $environment, ?array $responses = null): self
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new VerificationReadException('unavailable', 'explicit_environment_required');
        }
        if ($responses !== null && ! app()->environment('testing')) {
            throw new VerificationReadException('unavailable', 'simulations_testing_only');
        }
        // Origine officielle fixe, indépendante de l'URL /v1.beta du parcours de réception.
        if (rtrim((string) config('services.superpdp.directory_base_url'), '/') !== 'https://api.superpdp.tech') {
            throw new VerificationReadException('unavailable', 'official_origin_required');
        }
        $live = config('superpdp_verification.live_reads_enabled') === true;
        $clientId = config('services.superpdp.client_id');
        $clientId = is_string($clientId) ? $clientId : '';
        $company = Company::on($stored->getConnectionName())->where('user_id', $actor->id)->first();
        $connection = $company?->superPdpConnection()->first();
        $expectedId = $connection?->superpdp_company_id;
        $token = null;
        $error = null;
        if (! $connection || $connection->status !== 'connected') {
            $error = 'connection_unavailable';
        } elseif (! is_int($expectedId) || $expectedId <= 0) {
            $error = 'provider_company_id_missing';
        } elseif ($clientId === '') {
            $error = 'oauth_client_not_configured';
        } elseif ($connection->tokenIsExpired()) {
            $error = 'access_token_expired';
        } elseif ($live || $responses !== null) {
            try {
                $token = $connection->access_token;
                if (! is_string($token) || $token === '') {
                    $error = 'access_token_missing';
                    $token = null;
                }
            } catch (Throwable) {
                $error = 'credential_unreadable';
            }
        }

        return new self($actor->id, $stored->id, $expectedId, $environment, $clientId, $live, $error, $token, $responses);
    }

    public function directory(string $siren): VerificationObservation
    {
        return $this->observe(fn () => (new FrenchDirectoryConsultationService($this->public->frenchDirectory()))->listEntries($siren), false);
    }

    public function company(): VerificationObservation
    {
        return $this->observe(fn () => $this->authenticated->company()->me());
    }

    public function session(): VerificationObservation
    {
        return $this->observe(fn () => $this->authenticated->session()->me());
    }

    public function ownedEntries(): VerificationObservation
    {
        return $this->observe(function () {
            $response = $this->authenticated->directoryEntry()->index();
            if ($response->status() === 200 && ! is_array(json_decode($response->body())->data ?? null)) {
                throw new VerificationReadException('ambiguous', 'owned_directory_list_expected');
            }

            return $response;
        });
    }

    private function observe(callable $operation, bool $authenticated = true): VerificationObservation
    {
        if (! $this->liveEnabled && $this->mock === null) {
            throw new VerificationReadException('unavailable', 'live_reads_disabled');
        }
        if ($authenticated && $this->credentialError !== null) {
            throw new VerificationReadException('unavailable', $this->credentialError);
        }
        $this->lastResponse = null;
        try {
            $result = $operation();
            $response = $this->lastResponse;
            if ($response === null) {
                throw new VerificationReadException('unavailable', 'response_missing');
            }
            if ($response->status() !== 200) {
                throw new VerificationReadException(in_array($response->status(), [400, 401, 403], true) ? 'refused' : 'unavailable', 'api_http_'.$response->status(), $response->status());
            }
            $data = $result instanceof Response ? EmissionJson::object($response->body()) : $result;

            return VerificationObservation::fromResponse($data, $response);
        } catch (VerificationReadException $exception) {
            throw $exception;
        } catch (FrenchDirectoryConsultationException $exception) {
            throw new VerificationReadException(match ($exception->reason) {
                'authentication' => 'refused', 'malformed_response' => 'ambiguous', default => 'unavailable'
            }, 'directory_'.$exception->reason, $exception->httpStatus);
        } catch (Throwable) {
            throw new VerificationReadException('unavailable', 'transport_or_response_unavailable');
        }
    }

    public function requests(): array
    {
        return array_map(function ($response) {
            $request = $response->getPendingRequest();

            return ['method' => $request->getMethod()->value, 'url' => $request->getUrl(), 'query' => $request->query()->all(),
                'authenticated' => $request->headers()->get('Authorization') !== null];
        }, $this->mock?->getRecordedResponses() ?? []);
    }

    public function __debugInfo(): array
    {
        return ['user_id' => $this->userId, 'invoice_id' => $this->invoiceId, 'live_enabled' => $this->liveEnabled];
    }
}
