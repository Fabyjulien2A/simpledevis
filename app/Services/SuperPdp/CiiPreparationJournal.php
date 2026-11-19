<?php

namespace App\Services\SuperPdp;

use RuntimeException;

/** Atomic append-only local journal. The directory comes from PreparedCiiArchive, never HTTP. */
final class CiiPreparationJournal
{
    public function __construct(private readonly string $directory) {}

    public static function key(array $binding): string
    {
        return CiiPreparationEvidence::hash($binding);
    }

    public function reserve(array $binding, int $now): array
    {
        return $this->locked($binding, true, function ($stream, ?array $last) use ($binding, $now) {
            if ($last === null) {
                $claim = CiiPreparationClaim::issue($binding);
                $last = $this->append($stream, null, ['binding' => $binding, 'state' => 'running',
                    'claim_hash' => $claim->hash(), 'reserved_at' => $now, 'updated_at' => $now,
                    'reason' => null, 'result' => null]);

                return $this->summary($last) + ['claim' => $claim];
            }
            if ($last['state'] === 'running' && $now >= $last['reserved_at'] + 900) {
                $last = $this->append($stream, $last, ['state' => 'review_required',
                    'reason' => 'interrupted_or_expired', 'updated_at' => $now]);
            }

            return $this->summary($last);
        });
    }

    public function read(array $binding): ?array
    {
        return $this->locked($binding, false, fn ($stream, $last) => $last === null ? null : $this->summary($last), true);
    }

    public function finish(array $binding, CiiPreparationClaim $claim, ?array $result, bool $completed, int $now): array
    {
        return $this->locked($binding, false, function ($stream, $last) use ($claim, $result, $completed, $now) {
            if ($last === null || $claim->id !== self::key($last['binding']) || $last['state'] !== 'running') {
                throw new RuntimeException('Réservation CII absente ou déjà terminée ; aucune relance autorisée.');
            }
            $claim->assertHash($last['claim_hash']);
            if ($now >= $last['reserved_at'] + 900) {
                $this->append($stream, $last, ['state' => 'review_required', 'reason' => 'interrupted_or_expired', 'updated_at' => $now]);
                throw new RuntimeException('Réservation CII expirée ; résultat à examiner.');
            }
            $last = $this->append($stream, $last, ['state' => $completed ? 'completed' : 'review_required',
                'reason' => $completed ? null : 'preparation_uncertain', 'result' => $result, 'updated_at' => $now]);

            return $this->summary($last);
        });
    }

    public function assertClaim(array $binding, CiiPreparationClaim $claim): void
    {
        $this->locked($binding, false, function ($stream, $last) use ($claim) {
            if ($last === null || $last['state'] !== 'running' || $claim->id !== self::key($last['binding'])) {
                throw new RuntimeException('Réservation CII non active.');
            }
            $claim->assertHash($last['claim_hash']);
        });
    }

    public function quarantine(array $binding, int $now): void
    {
        $this->locked($binding, false, function ($stream, $last) use ($now) {
            if ($last !== null && $last['state'] === 'completed') {
                $this->append($stream, $last, ['state' => 'review_required', 'reason' => 'result_integrity', 'updated_at' => $now]);
            }
        });
    }

    private function summary(array $row): array
    {
        return ['reservation_id' => self::key($row['binding']), 'state' => $row['state'],
            'reason' => $row['reason'], 'result' => $row['result'],
            'reserved_at' => $row['reserved_at'], 'updated_at' => $row['updated_at']];
    }

    private function locked(array $binding, bool $create, callable $operation, bool $readOnly = false): mixed
    {
        $key = self::key($binding);
        if (realpath($this->directory) !== $this->directory || is_link($this->directory)) {
            throw new RuntimeException('Répertoire privé de réservations CII non conforme.');
        }
        $path = $this->directory.DIRECTORY_SEPARATOR.$key.'.jsonl';
        if (is_link($path)) {
            throw new RuntimeException('Journal CII lié interdit.');
        }
        $fresh = false;
        if ($create) {
            $stream = @fopen($path, 'x+b');
            $fresh = $stream !== false;
        }
        if (! $fresh) {
            if (! file_exists($path)) {
                return $create ? throw new RuntimeException('Création du journal CII impossible.') : null;
            }
            $stream = @fopen($path, $readOnly ? 'rb' : 'r+b');
        }
        if ($stream === false) {
            throw new RuntimeException('Journal CII indisponible ; examen manuel requis.');
        }
        try {
            if (! flock($stream, ($readOnly ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
                throw new RuntimeException('Une opération CII concurrente détient le journal.');
            }
            if (! $readOnly && ! chmod($path, 0600)) {
                throw new RuntimeException('Permissions privées du journal CII impossibles.');
            }
            $bytes = stream_get_contents($stream, 1048577);
            if ($bytes === false || strlen($bytes) > 1048576 || (! $fresh && $bytes === '')
                || ($bytes !== '' && ! str_ends_with($bytes, "\n"))) {
                throw new RuntimeException('Journal CII incomplet ; examen manuel requis, aucune relance.');
            }
            $last = null;
            foreach ($bytes === '' ? [] : explode("\n", substr($bytes, 0, -1)) as $line) {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $checksum = $row['entry_sha256'] ?? null;
                unset($row['entry_sha256']);
                if (($row['sequence'] ?? null) !== ($last['sequence'] ?? 0) + 1
                    || ($row['previous_sha256'] ?? null) !== ($last === null ? null : CiiPreparationEvidence::hash($last))
                    || ($row['binding'] ?? null) !== $binding || ! is_string($checksum)
                    || ! hash_equals(CiiPreparationEvidence::hash($row), $checksum)
                    || ! in_array($row['state'] ?? null, ['running', 'completed', 'review_required'], true)
                    || ($last !== null && $last['state'] !== 'running'
                        && ! ($last['state'] === 'completed' && $row['state'] === 'review_required'))
                    || ! is_int($row['reserved_at'] ?? null) || ! is_int($row['updated_at'] ?? null)
                    || ! is_string($row['claim_hash'] ?? null)) {
                    throw new RuntimeException('Journal CII altéré ; examen manuel requis.');
                }
                $last = $row;
            }

            return $operation($stream, $last);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function append($stream, ?array $last, array $changes): array
    {
        $row = array_replace($last ?? [], $changes, ['sequence' => ($last['sequence'] ?? 0) + 1,
            'previous_sha256' => $last === null ? null : CiiPreparationEvidence::hash($last)]);
        $entry = $row + ['entry_sha256' => CiiPreparationEvidence::hash($row)];
        $bytes = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        fseek($stream, 0, SEEK_END);
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Écriture du journal CII incomplète ; aucune relance.');
            }
        }
        if (! fflush($stream) || ! fsync($stream)) {
            throw new RuntimeException('Réservation CII non synchronisée ; aucune conversion autorisée.');
        }

        return $row;
    }
}
