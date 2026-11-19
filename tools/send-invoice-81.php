<?php

// One fixed sandbox archive, through the existing atomic reservation/dispatch engine.
require dirname(__DIR__).'/vendor/autoload.php';
$arguments = array_slice($_SERVER['argv'], 1);
$archiveArguments = array_values(array_filter($arguments, static fn ($arg) => str_starts_with($arg, '--archive=')));
if (! in_array('--env=sandbox', $arguments, true)
    || count($archiveArguments) > 1
    || ($archiveArguments !== [] && ! preg_match('~\A--archive=invoice-cii/v1/validated/3/81/[a-f0-9]{64}\z~D', $archiveArguments[0]))
    || array_diff($arguments, ['--env=sandbox', '--diagnose', '--execute', ...$archiveArguments]) !== []
    || (in_array('--diagnose', $arguments, true) && in_array('--execute', $arguments, true))) {
    fwrite(STDERR, "Use --env=sandbox --diagnose, or --execute after explicit single-send approval.\n");
    exit(1);
}
$execute = in_array('--execute', $arguments, true);
$reference = 'invoice-cii/v1/validated/3/81/4479155bb099442c7d05177e8325ab15a273dc37878b57d330233f64ade2c3ef';
if ($archiveArguments !== []) {
    $reference = substr($archiveArguments[0], strlen('--archive='));
}
$sourceHash = '43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2';
$ciiHash = '65449421a5536621c584d425041695af2ef00136d8d07dfa34f0cb4ab6d1e8ab';
$stage = 'sandbox_scope';
$previousUser = null;
$previousConfiguration = null;
$restore = false;
$exitCode = 1;
try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    \App\Services\SuperPdp\SandboxInvoiceProfile::assertRuntime();
    $previousUser = auth()->user();
    $previousConfiguration = config('superpdp_emission');
    $restore = true;
    $db = \Illuminate\Support\Facades\DB::connection(\App\Services\SuperPdp\SandboxDatabaseIsolation::CONNECTION);
    if ($db->transactionLevel() !== 0) {
        throw new RuntimeException;
    }
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== \App\Services\SuperPdp\SandboxDatabaseIsolation::DATABASE) {
            throw new RuntimeException;
        }
        $actor = \App\Models\User::findOrFail(3);
        auth()->setUser($actor);
        $stage = 'invoice_and_absence_of_previous_emission';
        $invoice = \App\Models\Invoice::where('user_id', 3)->findOrFail(81);
        if (! $invoice->isIssued() || ! \App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice)
            || $invoice->invoice_number !== 'SBX-01M7G5J2H7KJQG4DWKE0QK5CTD'
            || \App\Models\ElectronicInvoice::where('invoice_id', 81)->exists()) {
            throw new RuntimeException;
        }
        $data = app(\App\Services\InvoiceExportDataBuilder::class)->buildElectronic($invoice);
        if (\App\Services\SuperPdp\CiiPreparationEvidence::hash($data) !== $sourceHash) {
            throw new RuntimeException;
        }
        $stage = 'validated_archive';
        $archive = app(\App\Services\SuperPdp\PreparedCiiArchive::class)->readValidated($actor, $invoice, $reference);
        if (hash('sha256', $archive['xml']) !== $ciiHash) {
            throw new RuntimeException;
        }
        $m = $archive['manifest'];
        $payload = app(\App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder::class)->build($invoice);
        if ($payload['buyer']['electronic_address'] !== ['scheme' => '0225', 'value' => '315143296_18171']) {
            throw new RuntimeException;
        }
        $stage = 'oauth_and_evidence_freshness';
        $connection = \App\Models\Company::where('user_id', 3)->findOrFail(3)->superPdpConnection()->sole();
        if ($connection->id !== 1 || $connection->superpdp_company_id !== 18172 || $connection->tokenIsExpired()) {
            throw new RuntimeException;
        }
        $evidence = app(\App\Services\SuperPdp\Verification\VerificationAuditStore::class)->read($actor, $invoice, $m['verification_reference']);
        $deadline = min([now()->timestamp + 900, $connection->access_token_expires_at->timestamp - 300,
            strtotime($m['prepared_at']) + 900, strtotime($m['validation']['observed_at']) + 900,
            strtotime($evidence['checked_at']) + 900,
            ...array_values(array_map(fn ($check) => strtotime($check['observed_at']) + 900, $evidence['checks']))]);
        if ($deadline <= now()->timestamp) {
            throw new RuntimeException('evidence_expired');
        }
        $approval = ['approved_by' => $execute ? 'explicit_user_single_send_invoice_81' : 'local_preflight_only_no_dispatch',
            'approved_at' => now()->timestamp, 'expires_at' => $deadline, 'user_id' => 3, 'invoice_id' => 81,
            'document_reference' => $reference, 'cii_sha256' => $ciiHash, 'provider_company_id' => 18172,
            'environment' => 'sandbox', 'oauth_client_id' => $connection->oauth_client_id,
            'api_url' => \App\Services\SuperPdp\Emission\SandboxEmissionAuthorization::API_URL];
        // Invocation-local only. No persistent configuration or web activation.
        config(['superpdp_emission.sandbox_enabled' => true,
            'superpdp_emission.sandbox_client_id' => $connection->oauth_client_id,
            'superpdp_emission.authorization' => $approval]);
        $stage = 'existing_emission_prerequisites';
        app(\App\Services\SuperPdp\Emission\SandboxEmissionPrerequisites::class)->authorize($actor, $invoice, $reference);
    } finally {
        $db->rollBack();
    }
    if (! $execute) {
        echo json_encode(['status' => 'local_prerequisites_passed', 'invoice_id' => 81, 'cii_sha256' => $ciiHash,
            'authorization_deadline' => date(DATE_ATOM, $deadline), 'api_called' => false, 'reservation_created' => false], JSON_PRETTY_PRINT), PHP_EOL;
        $exitCode = 0;
    } else {
        $stage = 'existing_single_emission_engine';
        $engine = app(\App\Services\SuperPdp\Emission\SandboxEmissionFactory::class)->forInvoice($actor, $invoice, $reference);
        $attempt = $engine->reserve($actor, $invoice, $reference, retryRejected: false);
        // Authorization is bound to exactly the UUID generated by the existing engine.
        $approval['attempt_reference'] = $attempt->reference;
        config(['superpdp_emission.authorization' => $approval]);
        $record = \App\Models\ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);
        $attempt = $engine->dispatch($actor, $record, $attempt->id);
        echo json_encode(['status' => $attempt->status, 'attempt_reference' => $attempt->reference,
            'http_status' => $attempt->http_status, 'provider_invoice_id' => $attempt->provider_invoice_id,
            'error_code' => $attempt->error_code, 'delivery_verified' => false], JSON_PRETTY_PRINT), PHP_EOL;
        $exitCode = $attempt->status === 'accepted' ? 0 : 1;
    }
} catch (Throwable $exception) {
    echo json_encode(['status' => 'blocked_or_review_required', 'stage' => $stage,
        'reason' => $exception->getMessage() === 'evidence_expired' ? 'evidence_expired' : 'local_prerequisite_or_registry_failure',
        'message' => 'No automatic retry or OAuth refresh. Consult the existing emission registry.']), PHP_EOL;
} finally {
    if ($restore) {
        config(['superpdp_emission' => $previousConfiguration]);
        $previousUser !== null ? auth()->setUser($previousUser) : auth()->forgetUser();
    }
}
exit($exitCode);
