<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SuperPdp\Experimental\TrialDiagnostic;
use App\Services\SuperPdp\Experimental\TrialRuntime;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SuperPdpSandboxTrial extends Command
{
    protected $signature = 'superpdp:sandbox-trial
        {operation=inspect : inspect, prepare, deposit or consult}
        {--execute : Explicitly permit the selected sandbox operation and its documented API calls}
        {--diagnostic : Read-only prepare diagnosis: never SQL, API, file creation or ACL changes; incompatible with --execute}
        {--operator= : Name of the approving operator}
        {--date= : Invoice date YYYY-MM-DD}
        {--due-date= : Due date YYYY-MM-DD}
        {--confirm-tricatel-address : Confirm the interface address manually, without API proof}
        {--attempt= : Exact reviewed attempt UUID}
        {--package-sha256= : Exact reviewed archive digest}
        {--cii-sha256= : Exact reviewed CII digest}
        {--authorize-single-deposit : Approve exactly one POST for the reviewed attempt and CII}
        {--remote-id= : Explicit candidate provider invoice ID for reconciliation}';

    protected $description = 'Isolated Burger Queen 3 → Tricatel trial: prepare, explicitly deposit once, or consult; closed by default';

    public function handle(): int
    {
        $operation = $this->argument('operation');
        if (! in_array($operation, ['inspect', 'prepare', 'deposit', 'consult'], true)) {
            $this->error('Unknown operation. No SQL or API operation performed.');

            return self::FAILURE;
        }
        if ($this->option('diagnostic')) {
            if ($operation !== 'prepare' || $this->option('execute')) {
                $this->error('Diagnostic requires prepare without --execute. No SQL, API or filesystem write performed.');

                return self::FAILURE;
            }
            try {
                TrialDiagnostic::step('scope.configuration', fn () => SandboxDatabaseIsolation::assertConfiguredScope());
                TrialDiagnostic::step('command.arguments', fn () => $this->validatePreparationArguments());
                $result = app(TrialRuntime::class)->diagnosePreparation();
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
            } catch (Throwable $exception) {
                $this->printFailure($exception, true);

                return self::FAILURE;
            }
        }
        if (! $this->option('execute') || $operation === 'inspect') {
            $this->line('LOCKED: no SQL, filesystem write or API request. Select --env=sandbox and an operation with --execute after authorization. Deposit also requires --authorize-single-deposit, --attempt, --package-sha256 and --cii-sha256.');

            return self::SUCCESS;
        }
        try {
            TrialDiagnostic::step('scope.configuration', fn () => SandboxDatabaseIsolation::assertConfiguredScope());
            $operator = $this->option('operator');
            if (PHP_SAPI !== 'cli' || ! is_string($operator) || trim($operator) === '' || strlen($operator) > 200) {
                throw new RuntimeException('Named CLI operator required.');
            }
            if ($operation === 'prepare') {
                TrialDiagnostic::step('command.arguments', fn () => $this->validatePreparationArguments());
                $result = app(TrialRuntime::class)->prepare($this->option('date'), $this->option('due-date'), $operator);
            } else {
                if (($operation === 'deposit') !== (bool) $this->option('authorize-single-deposit')) {
                    throw new RuntimeException('Separate explicit single deposit authorization required; forbidden for reads.');
                }
                $expected = ['package_sha256' => $this->option('package-sha256'),
                    'cii_sha256' => $this->option('cii-sha256'), 'attempt_reference' => $this->option('attempt')];
                foreach (['package_sha256', 'cii_sha256'] as $key) {
                    if (! is_string($expected[$key]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $expected[$key])) {
                        throw new RuntimeException('Reviewed digests required.');
                    }
                }
                if (! is_string($expected['attempt_reference']) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $expected['attempt_reference'])) {
                    throw new RuntimeException('Reviewed UUID required.');
                }
                $remoteId = $this->option('remote-id');
                if ($remoteId !== null && (! ctype_digit($remoteId) || (int) $remoteId <= 0 || (string) (int) $remoteId !== $remoteId)) {
                    throw new RuntimeException('Positive provider ID required.');
                }
                $runtime = app(TrialRuntime::class);
                $result = $operation === 'deposit' ? $runtime->deposit($expected, $operator)
                    : $runtime->consult($expected, $operator, $remoteId === null ? null : (int) $remoteId);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return in_array($result['status'], ['reserved', 'accepted'], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            // Never leak tokens, SQL parameters, XML, provider bodies or exception traces.
            $this->error('Sandbox operation stopped. Check private storage/ACLs, isolated OAuth, reviewed UUID/digests and durable journal. No automatic retry: after a claimed or uncertain deposit, use consult.');
            $this->printFailure($exception, false);

            return self::FAILURE;
        } finally {
            // Approval is invocation-local; no authorization is persisted in configuration.
            auth()->forgetUser();
        }
    }

    private function validatePreparationArguments(): void
    {
        $operator = $this->option('operator');
        if (PHP_SAPI !== 'cli' || ! is_string($operator) || trim($operator) === '' || strlen($operator) > 200) {
            throw new RuntimeException('Named CLI operator required.');
        }
        if (! $this->option('confirm-tricatel-address') || $this->option('authorize-single-deposit')) {
            throw new RuntimeException('Manual destination confirmation required for preparation only.');
        }
        foreach (['date', 'due-date'] as $key) {
            $date = $this->option($key);
            if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)
                || date('Y-m-d', strtotime($date) ?: 0) !== $date) {
                throw new RuntimeException('Explicit valid dates required.');
            }
        }
        if ($this->option('due-date') < $this->option('date')) {
            throw new RuntimeException('Due date before issue date.');
        }
    }

    private function printFailure(Throwable $exception, bool $readOnly): void
    {
        $report = TrialDiagnostic::describe(TrialDiagnostic::failure($exception, 'command.arguments'));
        if ($readOnly) {
            $report += ['mode' => 'read_only_diagnostic', 'sql' => 'not_checked_no_connection',
                'oauth' => 'not_checked_no_sql_or_api', 'deposit' => 'not_executed'];
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
