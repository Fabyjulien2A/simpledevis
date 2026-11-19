<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationGuards;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxInvoiceProfile;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;

/** Thin runner for the existing producer. Default: local READ ONLY preflight. */
$prepareInvoice80 = static function (bool $execute = false, ?string $evidenceReference = null, int $invoiceId = 80): int {
    $reference = $evidenceReference ?? 'invoice-party-checks/v1/3/80/f60c953dc8b342faa5b27767fac56a3904ec237be0d8e777852b95d3b4a21c2c.json';
    $expectedSource = '94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89';
    $expectedReference = 'SBX-ea09521e-ab70-4f90-aa63-8268d743d151';
    if ($invoiceId === 81) {
        $reference = $evidenceReference ?? '';
        $expectedSource = '43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2';
        $expectedReference = 'SBX-01M7G5J2H7KJQG4DWKE0QK5CTD';
    }
    $previousUser = null;
    $previousConfiguration = null;
    $restore = false;
    $stage = 'sandbox_scope';
    try {
        SandboxInvoiceProfile::assertRuntime();
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException;
        }
        if (! in_array($invoiceId, [80, 81], true)
            || ! preg_match('~\Ainvoice-party-checks/v1/3/'.$invoiceId.'/[a-f0-9]{64}\.json\z~D', $reference)) {
            throw new RuntimeException;
        }
        $previousUser = auth()->user();
        $previousConfiguration = config('superpdp_preparation');
        $restore = true;
        $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException;
        }
        $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                throw new RuntimeException;
            }
            $stage = 'frozen_invoice';
            $actor = User::findOrFail(3);
            auth()->setUser($actor);
            $invoice = Invoice::where('user_id', 3)->findOrFail($invoiceId);
            if (! $invoice->isIssued() || ! SandboxInvoiceProfile::is($invoice)
                || $invoice->invoice_number !== $expectedReference) {
                throw new RuntimeException;
            }
            $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
            if (! hash_equals($expectedSource, CiiPreparationEvidence::hash($data))
                || ! app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['local_checks_passed']) {
                throw new RuntimeException;
            }
            app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data);
            $stage = 'seller_oauth';
            $company = Company::where('user_id', 3)->findOrFail(3);
            $connection = $company->superPdpConnection()->sole();
            if ($company->company_name !== 'Burger Queen' || $connection->id !== 1
                || $connection->superpdp_company_id !== 18172 || $connection->directory_identifier !== '000000002') {
                throw new RuntimeException;
            }
            // Process-local binding only, never .env/config files or a web activation.
            config(['superpdp_preparation.sandbox_client_id' => config('services.superpdp.client_id')]);
            $guards = app(CiiPreparationGuards::class);
            $client = $guards->configuration();
            $guards->connection($connection, $client);
            $stage = 'party_evidence';
            $evidence = app(CiiPreparationEvidence::class)->assess($actor, $invoice, $data, $connection, $reference);
            if (! $evidence['live']) {
                throw new RuntimeException;
            }
            $report = $evidence['report'];
            $deadline = min([now()->timestamp + 900, strtotime($report['checked_at']) + 900,
                $connection->access_token_expires_at->timestamp - 300,
                ...array_values(array_map(fn ($check) => strtotime($check['observed_at']) + 900, $report['checks']))]);
            if ($deadline <= now()->timestamp) {
                throw new RuntimeException;
            }
            $authorization = [
                'approved_by' => $execute ? 'explicit_user_approved_invoice_'.$invoiceId.'_cli' : 'pending_explicit_user_approval',
                'approved_at' => $execute ? now()->timestamp : null, 'expires_at' => $deadline,
                'user_id' => 3, 'invoice_id' => $invoiceId, 'source_sha256' => $expectedSource,
                'verification_reference' => $reference, 'provider_company_id' => 18172,
                'environment' => 'sandbox', 'oauth_client_id' => $client, 'api_url' => $connection->api_url,
            ];
        } finally {
            $db->rollBack();
        }
        if (! $execute) {
            echo json_encode(['status' => 'local_prerequisites_passed', 'api_called' => false,
                'invoice_issued_and_unchanged' => true, 'live_evidence_valid' => true,
                'seller_oauth_expires_at' => $connection->access_token_expires_at->toIso8601String(),
                'authorization_proposal' => $authorization,
                'authorization_deadline' => date(DATE_ATOM, $deadline),
                'requests' => ['POST /v1.beta/invoices/convert?from=en16931&to=cii', 'POST /v1.beta/validation_reports'],
                'emission_authorized' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
            return 0;
        }
        // Explicit execution only: existing temporary guards and producer own all API calls,
        // reservations, CII checks and private archives. No invoice sending operation.
        $stage = 'existing_cii_producer';
        config(['superpdp_preparation.live_enabled' => true, 'superpdp_preparation.authorization' => $authorization]);
        $guards->authorization(3, $invoiceId, $expectedSource, $reference, $connection, $client, true);
        $result = app(InvoiceCiiPreparation::class)->produce($actor, $invoice, $reference);
        echo json_encode(array_intersect_key($result, array_flip(['status', 'reason', 'artifact', 'deposit_performed',
            'failure_stage', 'failure_kind', 'diagnostic_reference', 'diagnostic_status'])),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return ($result['status'] ?? null) === 'prepared_validated' ? 0 : 1;
    } catch (Throwable $exception) {
        // No raw exception, response, XML or credentials in console output.
        $reason = match ($exception->getMessage()) {
            'Fresh party evidence required (maximum 15 minutes).' => 'evidence_expired_or_invalid_date',
            'Complete matching party evidence required.' => 'evidence_checks_incomplete',
            'Party evidence does not match the historical invoice or sandbox company.' => 'evidence_context_mismatch',
            default => 'local_prerequisite_unavailable',
        };
        echo json_encode(['status' => 'blocked', 'stage' => $stage, 'reason' => $reason,
            'exception_type' => $exception::class, 'emission_authorized' => false,
            'message' => 'Preparation refused; recheck local prerequisites. No retry or OAuth refresh.'], JSON_THROW_ON_ERROR), PHP_EOL;
        return 1;
    } finally {
        if ($restore) {
            config(['superpdp_preparation' => $previousConfiguration]);
            $previousUser !== null ? auth()->setUser($previousUser) : auth()->forgetUser();
        }
    }
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = array_slice($_SERVER['argv'], 1);
    $invoiceArguments = array_values(array_filter($arguments, static fn ($arg) => str_starts_with($arg, '--invoice=')));
    $evidenceArguments = array_values(array_filter($arguments, static fn ($arg) => str_starts_with($arg, '--evidence=')));
    if (! in_array('--env=sandbox', $arguments, true)
        || count($evidenceArguments) > 1 || count($invoiceArguments) > 1
        || ($invoiceArguments !== [] && ! in_array($invoiceArguments[0], ['--invoice=80', '--invoice=81'], true))
        || count(array_diff($arguments, ['--env=sandbox', '--diagnose', '--execute', ...$evidenceArguments, ...$invoiceArguments])) !== 0
        || ($evidenceArguments !== [] && ! preg_match('~\A--evidence=invoice-party-checks/v1/3/(80|81)/[a-f0-9]{64}\.json\z~D', $evidenceArguments[0]))
        || (in_array('--diagnose', $arguments, true) && in_array('--execute', $arguments, true))) {
        fwrite(STDERR, "Use --env=sandbox --diagnose, or --execute only after explicit approval. No operation performed.\n");
        exit(1);
    }
    try {
        require dirname(__DIR__).'/vendor/autoload.php';
        $application = require dirname(__DIR__).'/bootstrap/app.php';
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        exit($prepareInvoice80(in_array('--execute', $arguments, true),
            $evidenceArguments === [] ? null : substr($evidenceArguments[0], strlen('--evidence=')),
            $invoiceArguments === [] ? 80 : (int) substr($invoiceArguments[0], strlen('--invoice='))));
    } catch (Throwable) {
        fwrite(STDERR, "Sandbox bootstrap refused. No preparation performed.\n");
        exit(1);
    }
}

return $prepareInvoice80;
