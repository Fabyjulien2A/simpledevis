<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Comptes rendus privés immuables, sans corps distant, en-tête, token ni secret OAuth. */
class VerificationAuditStore
{
    public function store(User $actor, Invoice $invoice, array $report): string
    {
        $this->owned($actor, $invoice);
        if (($report['user_id'] ?? null) !== $actor->id || ($report['invoice_id'] ?? null) !== $invoice->id
            || ($report['schema_version'] ?? null) !== 1 || ($report['emission_ready'] ?? null) !== false) {
            throw new RuntimeException('Compte rendu non conforme.');
        }
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $json);
        $directory = $this->directory($actor->id, $invoice->id, true);
        $path = $directory.DIRECTORY_SEPARATOR.$sha.'.json';
        if (! file_exists($path) && ! is_link($path)) {
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Écriture du compte rendu impossible.');
            }
            try {
                if (! chmod($path, 0600)) {
                    throw new RuntimeException('Permissions privées impossibles.');
                }
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($stream, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Écriture incomplète.');
                    }
                    $offset += $written;
                }
                if (! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Synchronisation impossible.');
                }
            } finally {
                fclose($stream);
            }
        }
        $reference = 'invoice-party-checks/v1/'.$actor->id.'/'.$invoice->id.'/'.$sha.'.json';
        $this->read($actor, $invoice, $reference);

        return $reference;
    }

    public function read(User $actor, Invoice $invoice, string $reference): array
    {
        $this->owned($actor, $invoice);
        if (! preg_match('~\Ainvoice-party-checks/v1/([1-9][0-9]*)/([1-9][0-9]*)/([a-f0-9]{64})\.json\z~D', $reference, $m)
            || $m[1] !== (string) $actor->id || $m[2] !== (string) $invoice->id) {
            throw new RuntimeException('Référence de vérification non autorisée.');
        }
        $path = $this->directory($actor->id, $invoice->id, false).DIRECTORY_SEPARATOR.$m[3].'.json';
        $this->path($path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Compte rendu illisible.');
        }
        $json = file_get_contents($path);
        if (! is_string($json) || ! hash_equals($m[3], hash('sha256', $json))) {
            throw new RuntimeException('Compte rendu altéré.');
        }
        $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (($report['user_id'] ?? null) !== $actor->id || ($report['invoice_id'] ?? null) !== $invoice->id
            || ($report['schema_version'] ?? null) !== 1 || ($report['emission_ready'] ?? null) !== false) {
            throw new RuntimeException('Compte rendu non autorisé.');
        }

        return $report;
    }

    private function owned(User $actor, Invoice $invoice): void
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
    }

    private function directory(int $userId, int $invoiceId, bool $create): string
    {
        if (config('filesystems.disks.invoice_cii.driver') !== 'local' || config('filesystems.disks.invoice_cii.serve', false)
            || config('filesystems.disks.invoice_cii.visibility') !== 'private') {
            throw new RuntimeException('Stockage privé local requis.');
        }
        $configured = Storage::disk('invoice_cii')->path('');
        if ($create && ! is_dir($configured) && ! mkdir($configured, 0700, true) && ! is_dir($configured)) {
            throw new RuntimeException('Stockage indisponible.');
        }
        $root = realpath($configured);
        if ($root === false || is_link(rtrim($configured, '/\\'))) {
            throw new RuntimeException('Racine non conforme.');
        }
        $exposed = [public_path(), Storage::disk('public')->path('')];
        if (config('filesystems.disks.local.serve')) {
            $exposed[] = config('filesystems.disks.local.root');
        }
        foreach ($exposed as $location) {
            $location = realpath($location);
            if ($location !== false && ($root === $location || str_starts_with($root, $location.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Stockage exposé interdit.');
            }
        }
        foreach (['party-checks', 'v1', (string) $userId, (string) $invoiceId] as $segment) {
            $root .= DIRECTORY_SEPARATOR.$segment;
            if ($create && ! is_dir($root) && ! mkdir($root, 0700) && ! is_dir($root)) {
                throw new RuntimeException('Stockage indisponible.');
            }
            $this->path($root);
        }

        return $root;
    }

    private function path(string $path): void
    {
        if (is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('Chemin non conforme.');
        }
    }
}
