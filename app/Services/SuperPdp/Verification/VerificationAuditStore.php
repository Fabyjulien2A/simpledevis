<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Comptes rendus privés immuables, sans corps distant, en-tête, token ni secret OAuth. */
class VerificationAuditStore
{
    /** Rapport d'entreprise distinct : jamais associé à une facture ou une preuve de routage. */
    public function storeCompanyPublication(User $actor, Company $company, array $report): string
    {
        TricatelPublicationScope::assert($actor, $company);
        $this->assertCompanyReport($report);
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $json);
        $directory = $this->directory(4, 4, true, 'company-publication-checks');
        $path = $directory.DIRECTORY_SEPARATOR.$sha.'.json';
        if (! file_exists($path) && ! is_link($path)) {
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Écriture du rapport de publication impossible.');
            }
            try {
                if (! chmod($path, 0600)) {
                    throw new RuntimeException('Permissions privées impossibles.');
                }
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($stream, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Rapport incomplet.');
                    }
                    $offset += $written;
                }
                if (! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Synchronisation du rapport impossible.');
                }
            } finally {
                fclose($stream);
            }
        }
        $reference = 'company-publication-checks/v1/4/4/'.$sha.'.json';
        $this->readCompanyPublication($actor, $company, $reference);

        return $reference;
    }

    public function readCompanyPublication(User $actor, Company $company, string $reference): array
    {
        TricatelPublicationScope::assert($actor, $company);
        if (! preg_match('~\Acompany-publication-checks/v1/4/4/([a-f0-9]{64})\.json\z~D', $reference, $matches)) {
            throw new RuntimeException('Référence de publication non autorisée.');
        }
        $path = $this->directory(4, 4, false, 'company-publication-checks').DIRECTORY_SEPARATOR.$matches[1].'.json';
        $this->path($path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Rapport de publication illisible.');
        }
        $json = file_get_contents($path);
        if (! is_string($json) || ! hash_equals($matches[1], hash('sha256', $json))) {
            throw new RuntimeException('Rapport de publication altéré.');
        }
        $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $this->assertCompanyReport($report);

        return $report;
    }

    private function assertCompanyReport(array $report): void
    {
        if (($report['kind'] ?? null) !== 'tricatel_sandbox_publication' || ($report['schema_version'] ?? null) !== 1
            || ($report['user_id'] ?? null) !== 4 || ($report['company_id'] ?? null) !== 4
            || ($report['emission_ready'] ?? null) !== false || ($report['routing_from_burger_queen_verified'] ?? null) !== false) {
            throw new RuntimeException('Compte rendu de publication non conforme.');
        }
    }

    /** Index privé du dernier contrôle terminé ; les rapports restent immuables. */
    public function storeDestination(User $actor, Invoice $invoice, array $report): string
    {
        if (($report['kind'] ?? null) !== 'buyer_destination') {
            throw new RuntimeException('Type de contrôle non conforme.');
        }
        $reference = $this->store($actor, $invoice, $report);
        $path = $this->directory($actor->id, $invoice->id, false).DIRECTORY_SEPARATOR.'destination.latest';
        if (file_exists($path) || is_link($path)) {
            $this->path($path);
        }
        $stream = fopen($path, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('Index de vérification indisponible.');
        }
        try {
            if (! flock($stream, LOCK_EX) || ! chmod($path, 0600) || ! ftruncate($stream, 0)
                || fwrite($stream, $reference) !== strlen($reference) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Index de vérification incomplet.');
            }
        } finally {
            fclose($stream);
        }

        return $reference;
    }

    /** Lecture locale seulement, sans création de répertoire ou recherche distante. */
    public function latestDestination(User $actor, Invoice $invoice): ?array
    {
        $this->owned($actor, $invoice);
        $folder = Storage::disk('invoice_cii')->path('party-checks/v1/'.$actor->id.'/'.$invoice->id);
        if (! file_exists($folder) && ! is_link($folder)) {
            return null;
        }
        $path = $this->directory($actor->id, $invoice->id, false).DIRECTORY_SEPARATOR.'destination.latest';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        $this->path($path);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Index de vérification illisible.');
        }
        try {
            if (! flock($stream, LOCK_SH)) {
                throw new RuntimeException('Index de vérification verrouillé.');
            }
            $reference = stream_get_contents($stream, 512);
        } finally {
            fclose($stream);
        }
        $report = $this->read($actor, $invoice, is_string($reference) ? $reference : '');
        if (($report['kind'] ?? null) !== 'buyer_destination') {
            throw new RuntimeException('Compte rendu de destination attendu.');
        }

        return $report + ['audit_reference' => $reference];
    }

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

    private function directory(int $userId, int $invoiceId, bool $create, string $scope = 'party-checks'): string
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
        if (! in_array($scope, ['party-checks', 'company-publication-checks'], true)) {
            throw new RuntimeException('Périmètre de stockage non autorisé.');
        }
        foreach ([$scope, 'v1', (string) $userId, (string) $invoiceId] as $segment) {
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
