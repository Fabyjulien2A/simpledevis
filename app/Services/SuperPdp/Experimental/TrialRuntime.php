<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\Verification\VerificationObservation;
use Bluerock\SuperPdp\Requests\Companies\GetCompanyMeRequest;
use Bluerock\SuperPdp\Requests\DirectoryEntries\ListDirectoryEntriesRequest;
use Bluerock\SuperPdp\Requests\InvoiceEvents\ListInvoiceEventsRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\GetInvoiceRequest;
use Bluerock\SuperPdp\Requests\Sessions\GetSessionMeRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/** CLI orchestration of the existing experimental components. No SQL writes. */
class TrialRuntime
{
    public function __construct(protected readonly TrialStorage $storage) {}

    protected function identity(): array
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        if (! app()->bound(SandboxDatabaseIsolation::class)
            || config('database.connections.superpdp_sandbox.username') !== SandboxDatabaseIsolation::DATABASE) {
            throw new RuntimeException('Real isolated sandbox bootstrap required.');
        }
        $db = TrialDiagnostic::step('identity.connection', fn () => DB::connection('superpdp_sandbox'));
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('External SQL transaction refused.');
        }
        TrialDiagnostic::step('identity.read_only', fn () => $db->statement('SET TRANSACTION READ ONLY'));
        TrialDiagnostic::step('identity.transaction', fn () => $db->beginTransaction());
        try {
            if (TrialDiagnostic::step('identity.database', fn () => $db->selectOne('SELECT DATABASE() AS selected_database'))->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                throw new RuntimeException('Sandbox database mismatch.');
            }
            $user = TrialDiagnostic::step('identity.user', fn () => User::on('superpdp_sandbox')->select(['id'])->findOrFail(3));
            $company = TrialDiagnostic::step('identity.company', fn () => Company::on('superpdp_sandbox')->whereKey(3)->where('user_id', 3)->firstOrFail());
            $connection = TrialDiagnostic::step('identity.oauth_record', fn () => SuperPdpConnection::on('superpdp_sandbox')->whereKey(1)->where('company_id', 3)->firstOrFail());
        } finally {
            TrialDiagnostic::step('identity.rollback', fn () => $db->rollBack());
        }
        auth()->setUser($user);
        $context = TrialDiagnostic::step('identity.context', fn () => SandboxEmissionContext::forCompany($company));
        TrialDiagnostic::step('oauth.local', fn () => $this->assertOAuth($context, $company, $connection));

        return [$company, $connection, $context];
    }

    protected function assertOAuth(SandboxEmissionContext $context, Company $company, SuperPdpConnection $connection): void
    {
        $context->assertFor($company);
        $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), SandboxEmissionAuthorization::API_URL);
        if ($connection->company_id !== 3 || $connection->superpdp_company_id !== 18172
            || $connection->directory_identifier !== '000000002' || ! $connection->isConnected()
            || $connection->tokenIsExpired() || ! is_string($connection->access_token) || $connection->access_token === '') {
            throw new RuntimeException('Sandbox OAuth expired, unreadable or mismatched; reconnect separately.');
        }
    }

    protected function request(Request $request, SandboxEmissionContext $context, Company $company,
        SuperPdpConnection $connection, ?callable $beforeSend = null): Response
    {
        $this->assertOAuth($context, $company, $connection);
        if (! in_array($request::class, [GetSessionMeRequest::class, GetCompanyMeRequest::class,
            ListDirectoryEntriesRequest::class, CreateInvoiceRequest::class, GetInvoiceRequest::class, ListInvoiceEventsRequest::class], true)) {
            throw new RuntimeException('SDK request outside sandbox allowlist.');
        }
        $tokenHash = hash('sha256', $connection->access_token);
        $connector = SuperPdpConnector::withToken($connection->access_token, SandboxEmissionAuthorization::ORIGIN);
        $connector->tries = 1;
        $connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
        $connector->middleware()->onRequest(function (PendingRequest $pending) use ($context, $company, $connection, $tokenHash, $beforeSend) {
            $this->assertOAuth($context, $company, $connection);
            if (! hash_equals($tokenHash, hash('sha256', $connection->access_token))
                || ! str_starts_with($pending->getUrl(), SandboxEmissionAuthorization::API_URL.'/')
                || ($pending->query()->get('disable_pre_check') ?? false) !== false) {
                throw new RuntimeException('Sandbox request context changed.');
            }
            if ($beforeSend !== null) {
                $beforeSend();
            }
        });

        return $connector->send($request);
    }

    private function fetch(string $root, Request $request, array $identity, ?callable $beforeSend = null): Response
    {
        TrialDiagnostic::step('journal.intent', fn () => $this->storage->intent($root, $request->getMethod()->value, $request->resolveEndpoint()));

        return $this->request($request, $identity[2], $identity[0], $identity[1], $beforeSend);
    }

    private function seller(string $root, array $identity): array
    {
        $proofs = [];
        foreach ([new GetCompanyMeRequest, new GetSessionMeRequest, new ListDirectoryEntriesRequest] as $request) {
            $stage = match ($request::class) {
                GetCompanyMeRequest::class => 'seller.company',
                GetSessionMeRequest::class => 'seller.session',
                default => 'seller.directory',
            };
            $proofs[] = TrialDiagnostic::step($stage, function () use ($root, $request, $identity) {
                return TrialSellerEvidence::fromResponse($this->fetch($root, $request, $identity));
            });
        }

        return $proofs;
    }

    protected function documentary(string $root, array $identity, SandboxInvoiceScenario $scenario): array
    {
        $gateway = TrialDiagnostic::step('document.gateway', fn () => SandboxCiiPreparationGateway::forScenario($identity[2], $identity[0], $identity[1], $scenario, authorizeNetwork: true));
        TrialDiagnostic::step('journal.conversion_intent', fn () => $this->storage->intent($root, 'POST', '/v1.beta/invoices/convert'));
        $conversion = TrialDiagnostic::step('document.conversion', fn () => $gateway->convert());
        TrialDiagnostic::step('archive.cii_write', fn () => $this->storage->create($root, 'document.cii.xml', $conversion->data['xml']));
        TrialDiagnostic::step('journal.validation_intent', fn () => $this->storage->intent($root, 'POST', '/v1.beta/validation_reports'));
        $validation = TrialDiagnostic::step('document.validation', fn () => $gateway->validate($conversion->data['xml']));
        TrialDiagnostic::step('archive.report_write', fn () => $this->storage->create($root, 'validation-report.json', $validation->data['report_raw']));

        return [$conversion, $validation];
    }

    public function prepare(string $date, string $dueDate, string $operator): array
    {
        TrialDiagnostic::step('scope.configuration', fn () => $this->assertOperation($operator));

        return TrialDiagnostic::step('storage.operation', fn () => $this->storage->exclusively(function (string $root) use ($date, $dueDate, $operator) {
            $identity = TrialDiagnostic::step('identity.bootstrap', fn () => $this->identity());
            if (is_file($root.'/prepared.json')) {
                [$package, $ledger, $index] = TrialDiagnostic::step('archive.read', fn () => $this->load($root));
                if ($package->manifest['invoice']['date'] !== $date || $package->manifest['invoice']['due_date'] !== $dueDate) {
                    throw new TrialFailure('archive.dates', 'dates_mismatch', 'RuntimeException');
                }

                return $this->summary($root, $package, $ledger, $index);
            }
            $scenario = TrialDiagnostic::step('document.scenario', fn () => SandboxInvoiceScenario::forContext($identity[2], $identity[0], $date, $dueDate));
            // Persistent marker prevents a preparation crash from silently reissuing POSTs.
            TrialDiagnostic::step('preparation.marker', fn () => $this->storage->create($root, 'preparation.started', now()->toIso8601String()));
            $proofs = $this->seller($root, $identity);
            [$conversion, $validation] = $this->documentary($root, $identity, $scenario);
            $package = TrialDiagnostic::step('document.freeze', fn () => TrialPackage::freeze($identity[2], $identity[0], $scenario, $conversion, $validation, $operator, now()->toIso8601String()));
            TrialDiagnostic::step('seller.qualification', fn () => (new TrialGate)->inspect($package, $identity[2], $identity[0], $identity[1], ...$proofs));
            TrialDiagnostic::step('archive.package_write', fn () => $package->archive($root));
            $ledger = new TrialLedger($root);
            $attempt = TrialDiagnostic::step('journal.reserve', fn () => $ledger->reserve($package));
            $index = ['package_sha256' => $package->sha256(), 'cii_sha256' => $package->manifest['cii_sha256'],
                'attempt_reference' => $attempt['attempt_reference']];
            TrialDiagnostic::step('archive.index_write', fn () => $this->storage->create($root, 'prepared.json', json_encode($index, JSON_THROW_ON_ERROR)));

            return $this->summary($root, $package, $ledger, $index);
        }));
    }

    /** No identity(), DB, network, lock creation, marker, or ACL mutation. */
    public function diagnosePreparation(): array
    {
        TrialDiagnostic::step('scope.configuration', fn () => SandboxDatabaseIsolation::assertConfiguredScope());

        return $this->storage->diagnose() + ['mode' => 'read_only_diagnostic',
            'sql' => 'not_checked_no_connection', 'oauth' => 'not_checked_no_sql_or_api',
            'conversion' => 'not_executed', 'validation' => 'not_executed', 'deposit' => 'not_executed',
            'historical_failure' => 'not_recorded_by_previous_version'];
    }

    private function load(string $root, ?array $expected = null): array
    {
        $index = EmissionJson::object((string) file_get_contents($root.'/prepared.json'));
        if ($expected !== null && $expected !== $index) {
            throw new RuntimeException('Prepared UUID or reviewed digest mismatch.');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $index['package_sha256'] ?? '')) {
            throw new RuntimeException('Invalid archive index.');
        }
        $package = TrialPackage::readArchive($root.'/'.$index['package_sha256'].'.trial.json', $index['package_sha256']);
        if ($index['cii_sha256'] !== $package->manifest['cii_sha256']) {
            throw new RuntimeException('CII digest mismatch.');
        }
        TrialPackage::verifyArchive($root.'/document.cii.xml', $index['cii_sha256']);
        TrialPackage::verifyArchive($root.'/validation-report.json', $package->manifest['validation']['response_sha256']);
        $ledger = new TrialLedger($root);
        $history = $ledger->history();
        foreach ($history as $row) {
            if ($row['package_sha256'] !== $index['package_sha256'] || $row['attempt_reference'] !== $index['attempt_reference']) {
                throw new RuntimeException('Journal/index mismatch.');
            }
        }

        return [$package, $ledger, $index];
    }

    private function approval(TrialPackage $package, array $index, string $operator, string $purpose, ?int $remoteId = null): array
    {
        return ['profile' => TrialPackage::PROFILE, 'purpose' => $purpose, 'environment' => 'sandbox',
            'owner_user_id' => $package->manifest['owner_user_id'], 'local_company_id' => 3, 'provider_company_id' => 18172,
            'buyer_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'], 'recipient' => '0225:315143296_18171',
            'routing_evidence' => 'operator_confirmed_without_api_proof', 'disable_pre_check' => false,
            'oauth_client_id' => $package->manifest['oauth_client_id'], 'api_url' => SandboxEmissionAuthorization::API_URL,
            'package_sha256' => $index['package_sha256'], 'cii_sha256' => $index['cii_sha256'],
            'attempt_reference' => $index['attempt_reference'], 'approved_by' => $operator,
            'approved_at' => now()->timestamp, 'expires_at' => now()->timestamp + 300, 'provider_invoice_id' => $remoteId];
    }

    public function deposit(array $expected, string $operator): array
    {
        $this->assertOperation($operator);

        return $this->storage->exclusively(function (string $root) use ($expected, $operator) {
            $identity = $this->identity();
            [$package, $ledger, $index] = $this->load($root, $expected);
            $history = $ledger->history();
            if (end($history)['phase'] !== 'reserved') {
                throw new RuntimeException('Attempt already consumed; consult/reconcile, never resend.');
            }
            $approval = $this->approval($package, $index, $operator, 'single_deposit');
            $gate = new TrialGate;
            $gate->assertApproval($package, $approval, $index['attempt_reference']);
            $proofs = $this->seller($root, $identity);
            $claim = $gate->prepareClaim($package, $identity[2], $identity[0], $identity[1], $proofs[0], $proofs[1], $proofs[2],
                $root.'/'.$package->sha256().'.trial.json', $ledger, $approval);
            try {
                $response = $this->fetch($root, $gate->requestPlan($package, $index['attempt_reference'], $approval), $identity,
                    function () use ($gate, $package, $identity, $proofs, $approval, $index, $root) {
                        $gate->assertApproval($package, $approval, $index['attempt_reference']);
                        TrialPackage::verifyArchive($root.'/'.$package->sha256().'.trial.json', $package->sha256());
                        $gate->inspect($package, $identity[2], $identity[0], $identity[1], ...$proofs);
                    });
                if ($response->isMocked() || $response->isCached()) {
                    throw new RuntimeException('Non-live deposit response refused.');
                }
                $ledger->recordOutcome($package, $claim['claim_token'], $response->status(), $response->body());
            } catch (Throwable) {
                // If persistence itself failed, the already durable claim stays unknown.
                $ledger->markUncertain($package, $claim['claim_token']);
            }

            return $this->summary($root, $package, $ledger, $index);
        });
    }

    public function consult(array $expected, string $operator, ?int $remoteId = null): array
    {
        $this->assertOperation($operator);

        return $this->storage->exclusively(function (string $root) use ($expected, $operator, $remoteId) {
            $identity = $this->identity();
            [$package, $ledger, $index] = $this->load($root, $expected);
            $history = $ledger->history();
            $last = end($history);
            $id = $last['provider_invoice_id'] ?? $remoteId;
            if (! is_int($id) || $id <= 0 || ! in_array($last['status'], ['accepted', 'unknown'], true)
                || ($remoteId !== null && $remoteId !== $id)) {
                throw new RuntimeException('Known outgoing ID, or explicit candidate ID after uncertainty, required.');
            }
            $approval = $this->approval($package, $index, $operator, 'reconciliation_read', $id);
            (new TrialGate)->assertReconciliationApproval($package, $approval, $index['attempt_reference'], $id);
            $response = $this->fetch($root, new GetInvoiceRequest($id), $identity);
            $this->assertLiveRead($response);
            $metadata = EmissionJson::object($response->body());
            if (TrialOutcome::classify(200, $response->body(), $index['attempt_reference'])['provider_invoice_id'] !== $id) {
                throw new RuntimeException('Foreign or uncorrelated outgoing invoice refused.');
            }
            if ($last['status'] === 'unknown') {
                $request = new GetInvoiceRequest($id);
                $request->query()->add('format', 'original');
                $request->headers()->add('Accept', 'application/xml');
                $original = $this->fetch($root, $request, $identity);
                $this->assertLiveRead($original);
                $ledger->reconcilePositive($package, $approval,
                    VerificationObservation::fromResponse($metadata, $response),
                    VerificationObservation::fromResponse(['xml' => $original->body()], $original));
            }
            $history = $ledger->history();
            $last = end($history);
            $cursor = $last['event_summary']['cursor'] ?? null;
            $approval = $this->approval($package, $index, $operator, 'event_read', $id);
            $events = $this->fetch($root, new ListInvoiceEventsRequest($id, $cursor, 100), $identity);
            $this->assertLiveRead($events);
            $ledger->recordEventPage($package, $approval, EmissionJson::object($events->body()));

            return $this->summary($root, $package, $ledger, $index);
        });
    }

    private function assertLiveRead(Response $response): void
    {
        if ($response->status() !== 200 || $response->isMocked() || $response->isCached() || strlen($response->body()) > 5242880) {
            throw new RuntimeException('Bounded successful live response required; no automatic retry.');
        }
    }

    private function assertOperation(string $operator): void
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        if (PHP_SAPI !== 'cli' || trim($operator) === '' || strlen($operator) > 200) {
            throw new RuntimeException('Named CLI sandbox operator required.');
        }
    }

    private function summary(string $root, TrialPackage $package, TrialLedger $ledger, array $index): array
    {
        $history = $ledger->history();
        $last = end($history);

        return $index + ['environment' => 'sandbox', 'local_company_id' => 3,
            'seller' => 'Burger Queen / sandbox:000000002 / 0225:315143296_18172',
            'buyer' => 'Tricatel / sandbox:000000001 / 0225:315143296_18171',
            'routing_api_verified' => false, 'disable_pre_check' => false, 'status' => $last['status'],
            'provider_invoice_id' => $last['provider_invoice_id'] ?? null,
            'archive' => $root.'/'.$package->sha256().'.trial.json', 'cii' => $root.'/document.cii.xml',
            'validation_report' => $root.'/validation-report.json',
            'validation_report_sha256' => $package->manifest['validation']['response_sha256'],
            'invoice_number' => $package->manifest['invoice']['invoice_number'],
            'date' => $package->manifest['invoice']['date'], 'due_date' => $package->manifest['invoice']['due_date'],
            'totals' => $package->manifest['invoice']['totals'], 'events' => $last['event_summary'] ?? null];
    }
}
