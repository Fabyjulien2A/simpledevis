<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\ReadOnlyOAuthPrerequisiteException;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Throwable;

/** Une invocation CLI, au plus deux GET authentifiés, aucun retry ni renouvellement OAuth. */
final class TricatelPublicationGateway
{
    private SuperPdpConnector $connector;

    private array $attempted = [];

    private ?Response $lastResponse = null;

    private readonly string $tokenHash;

    private readonly int $expiresAt;

    public function __construct(
        private readonly User $actor,
        private readonly Company $company,
        private readonly SuperPdpConnection $connection,
        bool $authorizeNetwork = false,
        ?array $responses = null,
    ) {
        TricatelPublicationScope::assert($actor, $company, $connection);
        if (($responses === null && ! $authorizeNetwork) || ($responses !== null && $authorizeNetwork)) {
            throw new RuntimeException('Autorisation CLI explicite ou réponses simulées exclusivement.');
        }
        $token = app(SuperPdpOAuthService::class)->existingAccessTokenForReadOnly($company, $connection);
        $this->tokenHash = hash('sha256', $token);
        $this->expiresAt = now()->addMinutes(5)->timestamp;
        $this->connector = SuperPdpConnector::withToken($token, SandboxEmissionAuthorization::ORIGIN);
        if ($responses !== null) {
            foreach ($responses as $request => $response) {
                if (! in_array($request, [GetCompanyMeRequest::class, ListDirectoryEntriesRequest::class], true)
                    || ! $response instanceof MockResponse) {
                    throw new RuntimeException('Seules les deux lectures de publication sont simulables.');
                }
            }
            $this->connector->withMockClient(new MockClient($responses));
        }
        $this->connector->tries = 1;
        $this->connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 15, 'connect_timeout' => 5]);
        $this->connector->middleware()->onRequest(function (PendingRequest $request) {
            TricatelPublicationScope::assert($this->actor, $this->company, $this->connection);
            $token = app(SuperPdpOAuthService::class)->existingAccessTokenForReadOnly($this->company, $this->connection);
            $path = match ($request->getRequest()::class) {
                GetCompanyMeRequest::class => '/companies/me',
                ListDirectoryEntriesRequest::class => '/directory_entries',
                default => throw new RuntimeException('Opération interdite.'),
            };
            if (now()->timestamp >= $this->expiresAt || ! hash_equals($this->tokenHash, hash('sha256', $token))
                || $request->getMethod()->value !== 'GET' || $request->getUrl() !== SandboxEmissionAuthorization::API_URL.$path
                || $request->query()->all() !== [] || $request->headers()->get('Authorization') !== 'Bearer '.$token
                || $request->headers()->get('X-Impersonate-Company') !== null) {
                throw new RuntimeException('Périmètre de lecture ou jeton modifié.');
            }
        });
        $this->connector->middleware()->onResponse(function (Response $response) {
            $this->lastResponse = $response;
        });
    }

    public function company(): VerificationObservation
    {
        return $this->read('company', fn () => $this->connector->company()->me());
    }

    public function entries(): VerificationObservation
    {
        if (! isset($this->attempted['company'])) {
            throw new RuntimeException('Identité à contrôler avant les adresses.');
        }

        return $this->read('entries', fn () => $this->connector->directoryEntry()->index());
    }

    public function lastObservation(): ?VerificationObservation
    {
        return $this->lastResponse ? VerificationObservation::fromResponse([], $this->lastResponse) : null;
    }

    private function read(string $operation, callable $send): VerificationObservation
    {
        if (isset($this->attempted[$operation])) {
            throw new RuntimeException('Lecture déjà tentée ; aucun retry automatique.');
        }
        $this->attempted[$operation] = true;
        $this->lastResponse = null;
        try {
            $response = $send();
            if ($response->status() !== 200) {
                throw new VerificationReadException(in_array($response->status(), [400, 401, 403], true) ? 'refused' : 'unavailable',
                    'api_http_'.$response->status(), $response->status());
            }

            $data = EmissionJson::object($response->body());
            if ($operation === 'entries' && ! is_array(json_decode($response->body())->data ?? null)) {
                throw new VerificationReadException('ambiguous', 'directory_list_expected');
            }

            return VerificationObservation::fromResponse($data, $response);
        } catch (VerificationReadException $exception) {
            throw $exception;
        } catch (ReadOnlyOAuthPrerequisiteException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VerificationReadException('unavailable', 'transport_or_response_unavailable');
        }
    }

    public function __debugInfo(): array
    {
        return ['user_id' => 4, 'company_id' => 4, 'attempted' => array_keys($this->attempted)];
    }
}
