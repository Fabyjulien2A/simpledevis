<?php

// Reuse the exact archived bytes and existing SDK validation, never conversion.
require dirname(__DIR__).'/vendor/autoload.php';
$arguments = array_slice($_SERVER['argv'], 1);
$evidenceArguments = array_values(array_filter($arguments, static fn ($arg) => str_starts_with($arg, '--evidence=')));
if (! in_array('--env=sandbox', $arguments, true) || count($evidenceArguments) > 1
    || array_diff($arguments, ['--env=sandbox', '--diagnose', '--execute', ...$evidenceArguments]) !== []
    || ($evidenceArguments !== [] && ! preg_match('~\A--evidence=invoice-party-checks/v1/3/81/[a-f0-9]{64}\.json\z~D', $evidenceArguments[0]))
    || (in_array('--execute', $arguments, true) && (in_array('--diagnose', $arguments, true) || $evidenceArguments === []))) {
    fwrite(STDERR, "Use --env=sandbox --diagnose or --execute --evidence=REFERENCE after explicit approval.\n");
    exit(1);
}
$execute = in_array('--execute', $arguments, true);
$evidenceReference = $evidenceArguments === [] ? null : substr($evidenceArguments[0], strlen('--evidence='));
$reference = 'invoice-cii/v1/validated/3/81/4479155bb099442c7d05177e8325ab15a273dc37878b57d330233f64ade2c3ef';
$sourceHash = '43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2';
$ciiHash = '65449421a5536621c584d425041695af2ef00136d8d07dfa34f0cb4ab6d1e8ab';
$stage = 'sandbox_scope';
$restore = false;
$code = 1;
try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    \App\Services\SuperPdp\SandboxInvoiceProfile::assertRuntime();
    $previousUser = auth()->user();
    $previousConfig = config('superpdp_preparation');
    $restore = true;
    $db = \Illuminate\Support\Facades\DB::connection(\App\Services\SuperPdp\SandboxDatabaseIsolation::CONNECTION);
    if ($db->transactionLevel() !== 0) { throw new RuntimeException; }
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== \App\Services\SuperPdp\SandboxDatabaseIsolation::DATABASE) { throw new RuntimeException; }
        $actor = \App\Models\User::findOrFail(3);
        auth()->setUser($actor);
        $invoice = \App\Models\Invoice::where('user_id', 3)->findOrFail(81);
        if (! $invoice->isIssued() || ! \App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice)
            || \App\Models\ElectronicInvoice::where('invoice_id', 81)->exists()) { throw new RuntimeException; }
        $data = app(\App\Services\InvoiceExportDataBuilder::class)->buildElectronic($invoice);
        if (\App\Services\SuperPdp\CiiPreparationEvidence::hash($data) !== $sourceHash) { throw new RuntimeException; }
        $archive = app(\App\Services\SuperPdp\PreparedCiiArchive::class)->readValidated($actor, $invoice, $reference);
        if (hash('sha256', $archive['xml']) !== $ciiHash) { throw new RuntimeException; }
        app(\App\Services\SuperPdp\CiiDocumentChecks::class)->assertInvoiceMatches($archive['xml'],
            app(\App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder::class)->build($invoice), $invoice);
        app(\App\Services\SuperPdp\CiiDocumentChecks::class)->assertReport($archive['manifest']['validation']['report'], $archive['xml']);
        if ($execute) {
            $connection = \App\Models\Company::where('user_id', 3)->findOrFail(3)->superPdpConnection()->sole();
            if ($connection->id !== 1 || $connection->superpdp_company_id !== 18172) { throw new RuntimeException; }
            config(['superpdp_preparation.sandbox_client_id' => $connection->oauth_client_id]);
            $guards = app(\App\Services\SuperPdp\CiiPreparationGuards::class);
            $client = $guards->configuration();
            $guards->connection($connection, $client);
            $proofs = app(\App\Services\SuperPdp\CiiPreparationEvidence::class)->assess($actor, $invoice, $data, $connection, $evidenceReference);
            if (! $proofs['live']) { throw new RuntimeException; }
            $deadline = min([now()->timestamp + 900, $connection->access_token_expires_at->timestamp - 300,
                strtotime($proofs['report']['checked_at']) + 900,
                ...array_values(array_map(fn ($check) => strtotime($check['observed_at']) + 900, $proofs['report']['checks']))]);
            config(['superpdp_preparation.live_enabled' => true, 'superpdp_preparation.authorization' => [
                'approved_by' => 'explicit_user_archived_cii_revalidation_invoice_81', 'approved_at' => now()->timestamp, 'expires_at' => $deadline,
                'user_id' => 3, 'invoice_id' => 81, 'source_sha256' => $sourceHash, 'verification_reference' => $evidenceReference,
                'provider_company_id' => 18172, 'environment' => 'sandbox', 'oauth_client_id' => $client,
                'api_url' => \App\Services\SuperPdp\Emission\SandboxEmissionAuthorization::API_URL]]);
        }
    } finally { $db->rollBack(); }
    if (! $execute) {
        echo json_encode(['status' => 'archived_cii_checked', 'invoice_id' => 81, 'source_sha256' => $sourceHash,
            'cii_sha256' => $ciiHash, 'api_called' => false, 'previous_emission' => false]), PHP_EOL;
        $code = 0;
    } else {
        $stage = 'archived_cii_validation';
        $gateway = \App\Services\SuperPdp\SandboxCiiPreparationGateway::forInvoice($actor, $invoice, $sourceHash, $evidenceReference);
        $validation = $gateway->validate($archive['xml']);
        $artifact = app(\App\Services\SuperPdp\PreparedCiiArchive::class)->storeRevalidated($actor, $invoice, $reference, $validation, $evidenceReference);
        echo json_encode(['status' => 'revalidated', 'artifact' => $artifact], JSON_UNESCAPED_SLASHES), PHP_EOL;
        $code = 0;
    }
} catch (Throwable $exception) {
    $result = ['status' => 'blocked', 'stage' => $stage, 'emission_started' => false];
    if ($restore && isset($actor, $invoice)) {
        try {
            $detail = $exception instanceof \App\Services\SuperPdp\CiiPreparationFailure ? $exception->diagnostic
                : ['failure_stage' => $stage, 'failure_kind' => 'revalidation_rejected'];
            $result['diagnostic_reference'] = app(\App\Services\SuperPdp\Verification\VerificationAuditStore::class)->store($actor, $invoice,
                ['schema_version' => 1, 'kind' => 'cii_revalidation_diagnostic', 'user_id' => 3, 'invoice_id' => 81,
                    'emission_ready' => false, 'status' => 'failed', 'checked_at' => now()->toIso8601String(), 'diagnostic' => $detail]);
        } catch (Throwable) { $result['diagnostic_status'] = 'unavailable'; }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    if ($restore) { config(['superpdp_preparation' => $previousConfig]); $previousUser !== null ? auth()->setUser($previousUser) : auth()->forgetUser(); }
}
exit($code);
