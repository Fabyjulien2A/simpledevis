<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Bluerock\SuperPdp\Requests\DirectoryEntries\GetDirectoryEntryRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\PendingRequest;

/** One explicitly authorized detail GET. No persistence, refresh or qualification. */
class TricatelDirectoryEntryDiagnostic
{
    public const EVIDENCE = 'invoice-party-checks/v1/3/80/c1119ce955c7be83699182abb5c697f3b023136e2512d19ab10d27a1e1f80401.json';

    public function entryId(array $report): int
    {
        $diagnostic = $report['checks']['buyer_destination']['assessment']['classification_diagnostic'] ?? [];
        $observations = $report['observations'] ?? [];
        $observation = $observations[0] ?? [];
        if (($report['kind'] ?? null) !== 'sandbox_buyer_destination_diagnostic'
            || ($report['invoice_id'] ?? null) !== 80 || ($report['user_id'] ?? null) !== 3
            || ($report['buyer_provider_company_id'] ?? null) !== 18171
            || count($observations) !== 1 || ($observation['connection_id'] ?? null) !== 2
            || ($observation['method'] ?? null) !== 'GET' || ($observation['http_status'] ?? null) !== 200
            || ($observation['source'] ?? null) !== 'live' || ($observation['query'] ?? null) !== []
            || ($observation['endpoint'] ?? null) !== SandboxEmissionAuthorization::API_URL.'/directory_entries'
            || ! is_int($diagnostic['entry_id'] ?? null) || $diagnostic['entry_id'] <= 0
            || ($diagnostic['identifier'] ?? null) !== TricatelPublicationScope::ADDRESS) {
            throw new RuntimeException('Exact local Tricatel entry evidence required.');
        }
        return $diagnostic['entry_id'];
    }

    public function read(User $actor, Company $company, SuperPdpConnection $connection, int $entryId, bool $authorizeNetwork = false): array
    {
        $this->assertScope($actor, $company, $connection);
        if (! $authorizeNetwork || $entryId <= 0) {
            throw new RuntimeException('Explicit single GET authorization required.');
        }
        $token = app(SuperPdpOAuthService::class)->existingAccessTokenForReadOnly($company, $connection);
        $connector = SuperPdpConnector::withToken($token, SandboxEmissionAuthorization::ORIGIN);
        $connector->tries = 1;
        $connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 15, 'connect_timeout' => 5]);
        $connector->middleware()->onRequest(function (PendingRequest $pending) use ($actor, $company, $connection, $entryId, $token) {
            $this->assertScope($actor, $company, $connection);
            $current = app(SuperPdpOAuthService::class)->existingAccessTokenForReadOnly($company, $connection);
            if (! hash_equals($token, $current) || $pending->getMethod()->value !== 'GET'
                || $pending->getRequest()::class !== GetDirectoryEntryRequest::class
                || $pending->getUrl() !== SandboxEmissionAuthorization::API_URL.'/directory_entries/'.$entryId
                || $pending->query()->all() !== [] || $pending->headers()->get('Authorization') !== 'Bearer '.$token
                || $pending->headers()->get('X-Impersonate-Company') !== null) {
                throw new RuntimeException('Single entry request scope changed.');
            }
        });
        $response = $connector->send(new GetDirectoryEntryRequest($entryId));
        if ($response->status() !== 200) {
            throw new RuntimeException('Directory detail unavailable.');
        }
        $row = EmissionJson::object($response->body());
        $identity = $row['company'] ?? [];
        if (($row['id'] ?? null) !== $entryId || ($row['identifier'] ?? null) !== TricatelPublicationScope::ADDRESS
            || ($row['directory'] ?? null) !== 'peppol' || ! in_array($row['status'] ?? null, ['pending', 'created', 'error'], true)
            || ! is_array($identity) || ($identity['id'] ?? null) !== 18171 || ($identity['env'] ?? null) !== 'sandbox'
            || ($identity['number_scheme'] ?? null) !== 'sandbox' || ($identity['number'] ?? null) !== '000000001'
            || ($identity['formal_name'] ?? null) !== 'Tricatel' || ($identity['country'] ?? null) !== 'FR'
            || (array_key_exists('is_replyto', $row) && ! is_bool($row['is_replyto']))) {
            throw new RuntimeException('Directory entry mismatch or malformed response.');
        }
        $result = array_intersect_key($row, array_flip(['id', 'identifier', 'directory', 'status', 'is_replyto']));
        if (! array_key_exists('is_replyto', $row)) {
            $result['field_present'] = false;
        }
        return $result;
    }

    private function assertScope(User $actor, Company $company, SuperPdpConnection $connection): void
    {
        TricatelPublicationScope::assert($actor, $company, $connection);
        if ($connection->id !== 2 || $connection->superpdp_company_id !== 18171
            || $connection->directory_identifier !== '000000001') {
            throw new RuntimeException('Exact Tricatel connection 2 required.');
        }
    }
}
