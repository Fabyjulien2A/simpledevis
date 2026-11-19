<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxInvoiceEvidence;
use App\Services\SuperPdp\SandboxInvoiceProfile;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Saloon\Http\PendingRequest;
use Throwable;

/** Explicitly approved SDK GETs only. No refresh or invoice writes. */
final class CollectSandboxInvoiceEvidence extends Command
{
    protected $signature = 'superpdp:collect-invoice-evidence
        {--diagnose : Read only the exact invoice and local OAuth prerequisites; no API or archive}
        {--execute : Authorize this invocation of the listed SDK GETs}
        {--buyer-only : Collect only the Tricatel destination diagnostic (one GET)}
        {--seller-evidence= : Private previous invoice report to reuse if still valid; buyer-only mode}
        {--invoice=80 : Explicitly selected sandbox invoice: 80 or 81}
        {--source-sha256= : Required frozen source fingerprint for execution}';

    protected $description = 'Collect private SDK party evidence for an explicitly bound sandbox invoice';

    private const REFERENCES = [80 => 'SBX-ea09521e-ab70-4f90-aa63-8268d743d151',
        81 => 'SBX-01M7G5J2H7KJQG4DWKE0QK5CTD'];

    private string $stage = 'sandbox_scope';

    private function invoiceId(): int
    {
        if (! in_array((string) $this->option('invoice'), ['80', '81'], true)) {
            throw new RuntimeException('Explicitly bound sandbox invoice required.');
        }
        return (int) $this->option('invoice');
    }

    private function scope(): array
    {
        $invoiceId = $this->invoiceId();
        SandboxInvoiceProfile::assertRuntime();
        $this->stage = 'sandbox_read_only_transaction';
        $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('External transaction refused.');
        }
        $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                throw new RuntimeException('Sandbox database mismatch.');
            }
            $this->stage = 'invoice_identity';
            $actor = User::findOrFail(3);
            auth()->setUser($actor); // In-memory guard only; never log in as user 4.
            $invoice = Invoice::whereKey($invoiceId)->where('user_id', 3)->firstOrFail();
            if (! SandboxInvoiceProfile::is($invoice) || ! $invoice->isIssued() || $invoice->invoice_number !== self::REFERENCES[$invoiceId]) {
                throw new RuntimeException('Exact issued sandbox invoice required.');
            }
            $this->stage = 'frozen_invoice_fingerprints';
            $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
            $payload = app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data);
            $connections = [];
            foreach ([3 => [1, 18172, '000000002', 'Burger Queen'], 4 => [2, 18171, '000000001', 'Tricatel']] as $id => [$connectionId, $provider, $number, $name]) {
                $this->stage = 'oauth_local_company_'.$id;
                $company = Company::whereKey($id)->where('user_id', $id)->firstOrFail();
                $connection = $company->superPdpConnection()->sole();
                $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), SandboxEmissionAuthorization::API_URL);
                if ($company->company_name !== $name || $connection->id !== $connectionId
                    || $connection->superpdp_company_id !== $provider || $connection->directory_identifier !== $number
                    || $connection->company_id !== $id) {
                    throw new RuntimeException('Existing OAuth prerequisite unavailable; no refresh permitted.');
                }
                $connections[$id] = $connection;
            }
            return [$actor, $invoice, $connections, [
                'source_sha256' => CiiPreparationEvidence::hash($data),
                'payload_sha256' => CiiPreparationEvidence::hash($payload),
                'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
                'emission_context_sha256' => hash('sha256', $data['emission_context']),
                'sandbox_declaration_sha256' => CiiPreparationEvidence::hash($invoice->emission_context['declaration']),
                'document_sha256' => $invoice->emission_context['document_sha256'],
            ]];
        } finally {
            $db->rollBack();
        }
    }

    private function oauthReady(array $connections): bool
    {
        foreach ($connections as $connection) {
            if (! $connection->isConnected() || $connection->tokenIsExpired()
                || ! is_string($connection->access_token) || $connection->access_token === '') {
                return false;
            }
        }
        return true;
    }

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli' || ($this->option('execute') && $this->option('diagnose'))
            || ($this->option('seller-evidence') && ! $this->option('buyer-only'))) {
            return self::FAILURE;
        }
        if (! $this->option('execute') && ! $this->option('diagnose')) {
            $this->line('No SQL, API or archive operation. Use --env=sandbox --diagnose first.');
            return self::SUCCESS;
        }
        $previous = auth()->user();
        $journal = null;
        $actor = $invoice = null;
        $lastReference = null;
        try {
            if ($this->option('execute') && ! preg_match('/\A[a-f0-9]{64}\z/D', (string) $this->option('source-sha256'))) {
                throw new RuntimeException('An approved source fingerprint is required.');
            }
            [$actor, $invoice, $connections, $hashes] = $this->scope();
            $plan = [
                'invoice_id' => $invoice->id, 'invoice_reference' => $invoice->invoice_number, 'environment' => 'sandbox',
                'local_company_id' => 3, 'connection_id' => 1, 'provider_company_id' => 18172,
                'buyer_local_company_id' => 4, 'buyer_connection_id' => 2, 'buyer_provider_company_id' => 18171,
                'seller_address' => '0225:315143296_18172', 'buyer_address' => '0225:315143296_18171',
                'fingerprints' => $hashes, 'ready_for_emission' => false,
                'oauth_expires_at' => array_map(fn ($c) => $c->access_token_expires_at?->toIso8601String(), $connections),
                'oauth_prerequisites' => array_map(fn ($c) => [
                    'connection_id' => $c->id, 'connected' => $c->isConnected(),
                    'expired_or_expiring' => $c->tokenIsExpired(),
                    'access_token_present' => is_string($c->access_token) && $c->access_token !== '',
                ], $connections),
                'requests' => [
                    ['connection_id' => 1, 'method' => 'GET', 'endpoint' => SandboxEmissionAuthorization::API_URL.'/companies/me'],
                    ['connection_id' => 1, 'method' => 'GET', 'endpoint' => SandboxEmissionAuthorization::API_URL.'/oauth2_sessions/me'],
                    ['connection_id' => 1, 'method' => 'GET', 'endpoint' => SandboxEmissionAuthorization::API_URL.'/directory_entries'],
                    ['connection_id' => 2, 'method' => 'GET', 'endpoint' => SandboxEmissionAuthorization::API_URL.'/directory_entries'],
                ],
            ];
            if ($this->option('buyer-only')) {
                $plan['requests'] = [$plan['requests'][3]];
                $plan['mode'] = 'buyer_diagnostic_only';
                $plan['seller_evidence'] = $this->sellerEvidence($actor, $invoice, $connections[3]);
            }
            if ($this->option('diagnose')) {
                $ready = $this->oauthReady($this->option('buyer-only') ? [4 => $connections[4]] : $connections);
                $this->line(json_encode($plan + ['status' => $ready ? 'local_prerequisites_passed' : 'oauth_prerequisites_unavailable', 'api_called' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                return $ready ? self::SUCCESS : self::FAILURE;
            }
            if (! $this->oauthReady($this->option('buyer-only') ? [4 => $connections[4]] : $connections)) {
                throw new RuntimeException('Both existing OAuth credentials must be valid; no refresh permitted.');
            }
            if (! hash_equals($hashes['source_sha256'], $this->option('source-sha256'))) {
                throw new RuntimeException('Approved invoice fingerprint changed.');
            }
            $credentials = array_map(fn ($c) => hash('sha256', $c->access_token), $connections);
            $journal = $plan + ['schema_version' => 1, 'kind' => 'sandbox_sdk_collection', 'user_id' => 3,
                'id' => (string) Str::uuid(), 'emission_ready' => false, 'checked_at' => now()->toIso8601String(),
                'status' => 'started', 'observations' => []];
            $save = function () use (&$journal, &$lastReference, $actor, $invoice) {
                $lastReference = app(VerificationAuditStore::class)->store($actor, $invoice, $journal);
            };
            $save(); // Storage must work before any API call. Each checkpoint is immutable.
            $proofs = [];
            $requests = $this->option('buyer-only') ? [new ListDirectoryEntriesRequest]
                : [new GetCompanyMeRequest, new GetSessionMeRequest, new ListDirectoryEntriesRequest, new ListDirectoryEntriesRequest];
            foreach ($requests as $index => $request) {
                $companyId = $this->option('buyer-only') || $index === 3 ? 4 : 3;
                $expectedUrl = $plan['requests'][$index]['endpoint'];
                $journal['observations'][$index] = $plan['requests'][$index] + ['query' => [], 'status' => 'intent',
                    'sdk_request' => $request::class, 'at' => now()->toIso8601String(),
                    'credential_sha256' => $credentials[$companyId]];
                $save();
                $connector = SuperPdpConnector::withToken($connections[$companyId]->access_token, SandboxEmissionAuthorization::ORIGIN);
                $connector->tries = 1;
                $connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
                $connector->middleware()->onRequest(function (PendingRequest $pending) use ($expectedUrl, $hashes, $credentials, $request) {
                    [, , $current, $actual] = $this->scope();
                    if (! $this->oauthReady($this->option('buyer-only') ? [4 => $current[4]] : $current) || $actual !== $hashes || array_map(fn ($c) => hash('sha256', $c->access_token), $current) !== $credentials
                        || $pending->getMethod()->value !== 'GET' || $pending->getUrl() !== $expectedUrl
                        || $pending->getRequest()::class !== $request::class || $pending->query()->all() !== []) {
                        throw new RuntimeException('Collection scope changed before SDK request.');
                    }
                });
                $response = $connector->send($request);
                $journal['observations'][$index] = array_replace($journal['observations'][$index], [
                    'status' => 'received', 'http_status' => $response->status(),
                    'source' => $response->isMocked() ? 'simulated' : ($response->isCached() ? 'cached' : 'live'),
                    'observed_at' => now()->toIso8601String(), 'response_sha256' => hash('sha256', $response->body()),
                ]);
                $save(); // Even a refused SDK receipt retains its provenance, never tokens or response bodies.
                $proofs[$index] = SandboxSellerEvidence::fromResponse($response);
                if ($proofs[$index]->source !== 'live' || ! $proofs[$index]->usesAccessToken($connections[$companyId]->access_token)) {
                    throw new RuntimeException('Live matching SDK receipt required.');
                }
            }
            [, $fresh, $current, $actual] = $this->scope();
            if (! $this->oauthReady($this->option('buyer-only') ? [4 => $current[4]] : $current) || $actual !== $hashes || array_map(fn ($c) => hash('sha256', $c->access_token), $current) !== $credentials) {
                throw new RuntimeException('Collection scope changed after SDK requests.');
            }
            if ($this->option('buyer-only')) {
                $assessment = app(SandboxInvoiceEvidence::class)->assessBuyer($proofs[0], $current[4]);
                $journal['kind'] = 'sandbox_buyer_destination_diagnostic';
                $journal['status'] = $assessment['status'] === 'matched' ? 'passed' : 'refused';
                $journal['checks'] = ['buyer_destination' => ['status' => $journal['status'], 'assessment' => $assessment]];
                $journal['seller_evidence'] = $this->sellerEvidence($actor, $fresh, $current[3]);
                $save();
                $this->line(json_encode(['status' => $journal['status'], 'buyer_destination' => $assessment,
                    'audit_reference' => $lastReference, 'ready_for_emission' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                return $journal['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
            }
            $report = app(SandboxInvoiceEvidence::class)->record($actor, $fresh, ...$proofs);
            $journal['status'] = $report['status'];
            $journal['party_audit_reference'] = $report['audit_reference'];
            $save();
            $report['collection_reference'] = $lastReference;
            $report['sdk_provenance'] = $journal['observations'];
            unset($report['audit_reference']);
            $reference = app(VerificationAuditStore::class)->store($actor, $fresh, $report);
            $this->line(json_encode(['status' => $report['status'], 'audit_reference' => $reference,
                'collection_reference' => $lastReference, 'ready_for_emission' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($journal !== null && $actor !== null && $invoice !== null) {
                $journal['status'] = 'failed';
                try {
                    $lastReference = app(VerificationAuditStore::class)->store($actor, $invoice, $journal);
                } catch (Throwable) {
                    // Preserve the previous durable checkpoint; never retry a remote call.
                }
            }
            $this->error('Collection refused or incomplete. No retry, refresh, CII or emission.');
            $this->line('Stage: '.$this->stage.'; exception: '.$exception::class);
            if ($exception instanceof \Illuminate\Database\QueryException) {
                $this->line(json_encode(['sqlstate' => $exception->errorInfo[0] ?? null,
                    'driver_error_code' => $exception->errorInfo[1] ?? null], JSON_THROW_ON_ERROR));
            }
            if ($lastReference !== null) {
                $this->line('Last private checkpoint: '.$lastReference);
            }
            return self::FAILURE;
        } finally {
            $previous !== null ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }

    private function sellerEvidence(User $actor, Invoice $invoice, \App\Models\SuperPdpConnection $connection): array
    {
        $reference = $this->option('seller-evidence');
        if (! is_string($reference) || $reference === '') {
            return ['status' => 'not_requested'];
        }
        // Malformed or unreadable references must stop before network access.
        $report = app(VerificationAuditStore::class)->read($actor, $invoice, $reference);
        try {
            $checks = app(SandboxInvoiceEvidence::class)->reusableSellerChecks($invoice,
                app(InvoiceExportDataBuilder::class)->buildElectronic($invoice), $connection, $report);
            return ['status' => 'reused', 'reference' => $reference, 'checks' => $checks];
        } catch (RuntimeException) {
            return ['status' => 'unavailable', 'reference' => $reference,
                'reason' => 'seller_evidence_expired_or_incompatible'];
        }
    }
}
