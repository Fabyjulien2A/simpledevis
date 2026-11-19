<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\Verification\VerificationObservation;
use Illuminate\Support\Str;
use RuntimeException;

/** One permanent slot for the first BQ -> Tricatel trial. No reset, retry or SQL. */
final class TrialLedger
{
    private readonly string $path;

    public function __construct(string $privateDirectory)
    {
        $this->path = self::directory($privateDirectory).DIRECTORY_SEPARATOR.'burger-queen-3-tricatel-first-deposit.jsonl';
    }

    public static function directory(string $directory): string
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real) || is_link($directory) || ! is_writable($real)
            || preg_match('~^[a-z]+://~i', $directory)) {
            throw new RuntimeException('Existing private local directory required.');
        }

        return $real;
    }

    public function reserve(TrialPackage $package): array
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        $this->assertPath();
        $handle = @fopen($this->path, 'x+b');
        if ($handle === false) {
            return $this->locked(function ($handle, array $history) use ($package) {
                $this->match($history, $package);

                return end($history);
            });
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Experimental ledger busy.');
            }
            $row = ['sequence' => 1, 'previous_sha256' => null, 'phase' => 'reserved',
                'status' => 'reserved', 'profile' => TrialPackage::PROFILE,
                'package_sha256' => $package->sha256(), 'cii_sha256' => $package->manifest['cii_sha256'],
                'attempt_reference' => (string) Str::uuid(), 'at' => now()->toIso8601String()];
            self::writeDurably($handle, $this->line($row));

            return $row;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Recheck everything under the file lock before consuming the one-time slot. */
    public function claim(
        TrialPackage $package,
        array $approval,
        SandboxEmissionContext $context,
        Company $company,
        SuperPdpConnection $connection,
        TrialSellerEvidence $companyEvidence,
        TrialSellerEvidence $sessionEvidence,
        TrialSellerEvidence $directoryEvidence,
        string $archivePath,
    ): array {
        return $this->locked(function ($handle, array $history) use ($package, $approval, $context, $company,
            $connection, $companyEvidence, $sessionEvidence, $directoryEvidence, $archivePath) {
            $this->match($history, $package);
            $last = end($history);
            $gate = new TrialGate;
            $gate->inspect($package, $context, $company, $connection, $companyEvidence, $sessionEvidence, $directoryEvidence);
            TrialPackage::verifyArchive($archivePath, $package->sha256());
            $gate->assertApproval($package, $approval, $last['attempt_reference']);
            if ($last['phase'] !== 'reserved') {
                throw new RuntimeException('Attempt already consumed; no replay, even after rejection or uncertainty.');
            }

            return $this->append($handle, $history, ['phase' => 'claimed', 'status' => 'unknown',
                'claim_token' => (string) Str::uuid(), 'approval_sha256' => hash('sha256', json_encode($approval, JSON_THROW_ON_ERROR))]);
        });
    }

    /** No response body, XML or token is written to the journal. Null means timeout/exception. */
    public function recordOutcome(TrialPackage $package, string $claimToken, ?int $http, ?string $body): array
    {
        return $this->locked(function ($handle, array $history) use ($package, $claimToken, $http, $body) {
            $this->match($history, $package);
            $last = end($history);
            if ($last['phase'] !== 'claimed' || ! hash_equals($last['claim_token'], $claimToken)) {
                throw new RuntimeException('Current durable claim required.');
            }
            $outcome = TrialOutcome::classify($http, $body, $last['attempt_reference']);

            return $this->append($handle, $history, ['phase' => 'finished'] + $outcome);
        });
    }

    public function history(): array
    {
        return $this->locked(static fn ($handle, array $history) => $history);
    }

    /** A response/persistence failure never becomes permission to send again. */
    public function markUncertain(TrialPackage $package, string $claimToken): array
    {
        return $this->locked(function ($handle, array $history) use ($package, $claimToken) {
            $this->match($history, $package);
            $last = end($history);
            if (! in_array($last['phase'], ['claimed', 'finished'], true)
                || ! hash_equals($last['claim_token'] ?? '', $claimToken)) {
                throw new RuntimeException('Current claim required to mark an uncertain result.');
            }

            return $this->append($handle, $history, ['phase' => 'finished', 'status' => 'unknown']);
        });
    }

    public function reconcilePositive(
        TrialPackage $package,
        array $readApproval,
        VerificationObservation $metadata,
        VerificationObservation $original,
    ): array {
        return $this->locked(function ($handle, array $history) use ($package, $readApproval, $metadata, $original) {
            $this->match($history, $package);
            $last = end($history);
            if (($last['status'] ?? null) !== 'unknown' || ! in_array($last['phase'], ['claimed', 'finished'], true)) {
                throw new RuntimeException('Only an uncertain attempt can be reconciled.');
            }
            $remoteId = TrialReconciliation::positive($package, $last['attempt_reference'], $metadata, $original);
            (new TrialGate)->assertReconciliationApproval($package, $readApproval, $last['attempt_reference'], $remoteId);

            return $this->append($handle, $history, ['phase' => 'reconciled', 'status' => 'accepted',
                'provider_invoice_id' => $remoteId, 'reconciliation' => 'positive_exact_original',
                'metadata_response_sha256' => $metadata->sha256, 'original_sha256' => $original->sha256]);
        });
    }

    /** Persist a complete page and its cursor together, before any subsequent read. */
    public function recordEventPage(TrialPackage $package, array $readApproval, array $page): array
    {
        return $this->locked(function ($handle, array $history) use ($package, $readApproval, $page) {
            $this->match($history, $package);
            $last = end($history);
            if ($last['status'] !== 'accepted' || ! is_int($last['provider_invoice_id'] ?? null)) {
                throw new RuntimeException('Accepted outgoing provider ID required before event collection.');
            }
            (new TrialGate)->assertEventApproval($package, $readApproval, $last['attempt_reference'], $last['provider_invoice_id']);
            $projection = new TrialEvents($last['provider_invoice_id']);
            if (isset($last['event_summary'])) {
                $projection->applyPage(['data' => $last['event_summary']['events'], 'has_after' => false]);
            }
            $summary = $projection->applyPage($page);

            return $this->append($handle, $history, ['event_summary' => $summary]);
        });
    }

    private function locked(callable $operation): mixed
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        $this->assertPath();
        $handle = @fopen($this->path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Missing ledger; never recreate after an attempted deposit.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Experimental ledger busy.');
            }
            $bytes = stream_get_contents($handle, 1048577);
            if ($bytes === false || $bytes === '' || strlen($bytes) > 1048576 || ! str_ends_with($bytes, "\n")) {
                throw new RuntimeException('Incomplete ledger; manual investigation required, no send.');
            }
            $history = [];
            $previous = null;
            foreach (explode("\n", substr($bytes, 0, -1)) as $line) {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $checksum = $row['entry_sha256'] ?? null;
                unset($row['entry_sha256']);
                if (($row['sequence'] ?? null) !== count($history) + 1 || ($row['previous_sha256'] ?? null) !== $previous
                    || ($row['profile'] ?? null) !== TrialPackage::PROFILE
                    || ! is_string($checksum) || ! hash_equals(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $checksum)) {
                    throw new RuntimeException('Changed ledger; manual investigation required.');
                }
                $history[] = $row;
                $previous = $checksum;
            }

            return $operation($handle, $history);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function append($handle, array $history, array $changes): array
    {
        $last = end($history);
        $row = array_replace($last, $changes, ['sequence' => count($history) + 1,
            'previous_sha256' => hash('sha256', json_encode($last, JSON_THROW_ON_ERROR)), 'at' => now()->toIso8601String()]);
        fseek($handle, 0, SEEK_END);
        self::writeDurably($handle, $this->line($row));

        return $row;
    }

    private function match(array $history, TrialPackage $package): void
    {
        foreach ($history as $row) {
            if (($row['package_sha256'] ?? null) !== $package->sha256()
                || ($row['cii_sha256'] ?? null) !== $package->manifest['cii_sha256']) {
                throw new RuntimeException('The first-deposit slot belongs to a different frozen package.');
            }
        }
    }

    private function assertPath(): void
    {
        if (is_link($this->path)) {
            throw new RuntimeException('Linked ledger refused.');
        }
    }

    private function line(array $row): string
    {
        $row['entry_sha256'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return json_encode($row, JSON_THROW_ON_ERROR)."\n";
    }

    public static function writeDurably($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Incomplete write; stop, never send.');
            }
            $offset += $written;
        }
        if (! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Durable write failed; stop, never send.');
        }
    }
}
