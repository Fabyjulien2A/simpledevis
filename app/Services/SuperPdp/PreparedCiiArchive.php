<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Paquet privé immuable CII + manifeste ; aucun lien public, aucune suppression d'archive. */
class PreparedCiiArchive
{
    public function store(User $actor, Invoice $invoice, string $xml, array $manifest): array
    {
        $stored = $this->owned($actor, $invoice);
        if (($manifest['user_id'] ?? null) !== $actor->id || ($manifest['invoice_id'] ?? null) !== $stored->id
            || ($manifest['mode'] ?? null) !== 'offline_simulation' || ($manifest['ready_for_emission'] ?? null) !== false
            || ($manifest['cii_sha256'] ?? null) !== hash('sha256', $xml)) {
            throw new RuntimeException('Manifeste de préparation incohérent.');
        }
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $root = $this->root(true);
        $parent = $root;
        foreach (['v1', 'simulations', (string) $actor->id, (string) $stored->id] as $segment) {
            $parent .= DIRECTORY_SEPARATOR.$segment;
            if (! is_dir($parent) && ! mkdir($parent, 0700) && ! is_dir($parent)) {
                throw new RuntimeException('Stockage CII indisponible.');
            }
            $this->assertPath($parent);
        }
        $path = $parent.DIRECTORY_SEPARATOR.$hash;
        if (! file_exists($path) && ! is_link($path)) {
            $temporary = $parent.DIRECTORY_SEPARATOR.'.tmp-'.bin2hex(random_bytes(16));
            if (! mkdir($temporary, 0700)) {
                throw new RuntimeException('Stockage CII indisponible.');
            }
            try {
                $this->write($temporary.DIRECTORY_SEPARATOR.'document.xml', $xml);
                $this->write($temporary.DIRECTORY_SEPARATOR.'manifest.json', $json);
                if (! @rename($temporary, $path) && ! is_dir($path)) {
                    throw new RuntimeException('Publication du CII impossible.');
                }
            } finally {
                // Seulement nos deux temporaires connus ; jamais une archive finale existante.
                if (is_dir($temporary) && ! is_link($temporary)) {
                    @unlink($temporary.DIRECTORY_SEPARATOR.'document.xml');
                    @unlink($temporary.DIRECTORY_SEPARATOR.'manifest.json');
                    @rmdir($temporary);
                }
            }
        }
        $reference = 'invoice-cii/v1/simulations/'.$actor->id.'/'.$stored->id.'/'.$hash;
        $this->read($actor, $stored, $reference);

        return ['reference' => $reference, 'cii_sha256' => $manifest['cii_sha256'], 'manifest_sha256' => $hash];
    }

    public function read(User $actor, Invoice $invoice, string $reference): array
    {
        $stored = $this->owned($actor, $invoice);
        if (! preg_match('~\Ainvoice-cii/v1/simulations/([1-9][0-9]*)/([1-9][0-9]*)/([a-f0-9]{64})\z~D', $reference, $match)
            || $match[1] !== (string) $actor->id || $match[2] !== (string) $stored->id) {
            throw new RuntimeException('Référence CII non autorisée.');
        }
        $path = $this->root(false);
        foreach (['v1', 'simulations', $match[1], $match[2], $match[3]] as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;
            $this->assertPath($path);
        }
        $json = $this->bytes($path.DIRECTORY_SEPARATOR.'manifest.json');
        $xml = $this->bytes($path.DIRECTORY_SEPARATOR.'document.xml');
        if (! hash_equals($match[3], hash('sha256', $json))) {
            throw new RuntimeException('Manifeste CII altéré.');
        }
        $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['user_id'] ?? null) !== $actor->id || ($manifest['invoice_id'] ?? null) !== $stored->id
            || ($manifest['mode'] ?? null) !== 'offline_simulation' || ($manifest['ready_for_emission'] ?? null) !== false
            || ($manifest['cii_sha256'] ?? null) !== hash('sha256', $xml)) {
            throw new RuntimeException('Archive CII altérée ou non autorisée.');
        }

        return ['xml' => $xml, 'manifest' => $manifest];
    }

    private function owned(User $actor, Invoice $invoice): Invoice
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        abort_unless($stored->isIssued(), 409);

        return $stored;
    }

    private function root(bool $create): string
    {
        if (config('filesystems.disks.invoice_cii.driver') !== 'local'
            || config('filesystems.disks.invoice_cii.serve', false)
            || config('filesystems.disks.invoice_cii.visibility') !== 'private') {
            throw new RuntimeException('Stockage CII privé local requis.');
        }
        $configured = Storage::disk('invoice_cii')->path('');
        if ($create && ! is_dir($configured) && ! mkdir($configured, 0700, true) && ! is_dir($configured)) {
            throw new RuntimeException('Stockage CII indisponible.');
        }
        $root = realpath($configured);
        if ($root === false || is_link(rtrim($configured, '/\\'))) {
            throw new RuntimeException('Racine CII absente ou non conforme.');
        }
        $exposed = [public_path(), Storage::disk('public')->path('')];
        if (config('filesystems.disks.local.serve')) {
            $exposed[] = config('filesystems.disks.local.root');
        }
        foreach ($exposed as $location) {
            $location = realpath($location);
            if ($location !== false && ($root === $location || str_starts_with($root, $location.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Stockage CII exposé interdit.');
            }
        }

        return $root;
    }

    private function assertPath(string $path): void
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Chemin CII absent ou non conforme.');
        }
    }

    private function bytes(string $path): string
    {
        $this->assertPath($path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5242880) {
            throw new RuntimeException('Archive CII illisible ou trop volumineuse.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Archive CII vide ou illisible.');
        }

        return $bytes;
    }

    protected function write(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Écriture CII impossible.');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Permissions CII impossibles.');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Écriture CII incomplète.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Synchronisation CII impossible.');
            }
        } finally {
            fclose($stream);
        }
        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->bytes($path)))) {
            throw new RuntimeException('Vérification de l’écriture CII impossible.');
        }
    }
}
