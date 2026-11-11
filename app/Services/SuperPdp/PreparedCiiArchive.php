<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Verification\VerificationObservation;
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

        return $this->publish($actor, $stored, $xml, $manifest, 'simulations');
    }

    /** Receipts are SDK-derived: callers cannot assert provenance/ready with manifest booleans. */
    public function storeProduced(User $actor, Invoice $invoice,
        VerificationObservation $conversion,
        ?VerificationObservation $validation,
        string $verificationReference): array
    {
        return $invoice->getConnection()->transaction(function () use ($actor, $invoice, $conversion, $validation, $verificationReference) {
            User::on($invoice->getConnectionName())->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked = $invoice->newQuery()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $stored = $this->owned($actor, $locked);
            $data = app(InvoiceExportDataBuilder::class)->buildElectronic($stored);
            if (! app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($stored)['local_checks_passed']) {
                throw new RuntimeException('Invoice not locally eligible for CII archiving.');
            }
            $payload = app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data);
            $payloadHash = CiiPreparationEvidence::hash($payload);
            $xml = $conversion->data['xml'] ?? null;
            if (($conversion->data['operation'] ?? null) !== 'conversion' || ! is_string($xml) || $xml === '' || strlen($xml) > 5242880
                || ! in_array($conversion->source, ['live', 'simulated', 'cached'], true)
                || $conversion->sha256 !== hash('sha256', $xml) || ($conversion->data['input_sha256'] ?? null) !== $payloadHash) {
                throw new RuntimeException('Matching conversion receipt required.');
            }
            $checks = app(CiiDocumentChecks::class);
            $checks->assertMatches($xml, $payload);
            $evidenceService = app(CiiPreparationEvidence::class);
            $evidenceService->fresh($conversion->observedAt);
            $company = Company::on($invoice->getConnectionName())->where('user_id', $actor->id)->firstOrFail();
            $connection = $company->superPdpConnection()->firstOrFail();
            $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), Emission\SandboxEmissionAuthorization::API_URL);
            $proofs = $evidenceService->assess($actor, $stored, $data, $connection, $verificationReference);
            $passed = false;
            if ($validation !== null) {
                if (($validation->data['operation'] ?? null) !== 'validation' || ($validation->data['input_sha256'] ?? null) !== hash('sha256', $xml)
                    || ! is_array($validation->data['report'] ?? null) || ! in_array($validation->source, ['live', 'simulated', 'cached'], true)) {
                    throw new RuntimeException('Validation receipt refers to different document bytes.');
                }
                $evidenceService->fresh($validation->observedAt);
                try {
                    $checks->assertReport($validation->data['report'], $xml);
                    $passed = true;
                } catch (RuntimeException) {
                    // Keep the exact converted bytes as non-authorized, never promote an invalid report.
                }
            }
            $sources = [$conversion->source, $validation?->source];
            foreach (['buyer_destination', 'seller_identity', 'seller_session'] as $key) {
                $sources[] = $proofs['report']['checks'][$key]['source'];
            }
            $simulated = in_array('simulated', $sources, true);
            $ready = ! $simulated && $passed && $conversion->source === 'live' && $validation?->source === 'live' && $proofs['live'];
            $kind = $simulated ? 'simulations' : ($ready ? 'validated' : 'pending');
            $mode = match ($kind) {
                'validated' => 'validated_private_document', 'pending' => 'pending_validation', default => 'offline_simulation'
            };
            $manifest = [
                'schema_version' => 1, 'mode' => $mode, 'ready_for_emission' => $ready,
                'provenance' => $simulated ? 'simulated' : (in_array('cached', $sources, true) ? 'cached' : 'live'),
                'user_id' => $actor->id, 'invoice_id' => $stored->id, 'environment' => 'sandbox',
                'oauth_client_id' => $connection->oauth_client_id, 'api_url' => $connection->api_url, 'provider_company_id' => $connection->superpdp_company_id,
                'prepared_at' => $validation?->observedAt ?? $conversion->observedAt,
                'source_sha256' => CiiPreparationEvidence::hash($data), 'payload_sha256' => $payloadHash,
                'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']), 'emission_context_sha256' => hash('sha256', $data['emission_context']),
                'cii_sha256' => hash('sha256', $xml), 'verification_reference' => $verificationReference,
                'local_validation' => ['status' => 'passed', 'scope' => 'structural_payload_and_cii_consistency_not_full_schematron'],
                'conversion' => ['source' => $conversion->source, 'observed_at' => $conversion->observedAt,
                    'response_sha256' => $conversion->sha256, 'input_sha256' => $payloadHash],
                'validation' => ['status' => $passed ? ($simulated ? 'passed_simulation' : 'passed') : ($validation === null ? 'unavailable' : 'failed'),
                    'source' => $validation?->source ?? 'not_called', 'observed_at' => $validation?->observedAt,
                    'cii_sha256' => hash('sha256', $xml), 'response_sha256' => $validation?->sha256, 'report' => $validation?->data['report']],
            ];

            return $this->publish($actor, $stored, $xml, $manifest, $kind)
                + ['mode' => $mode, 'ready_for_emission' => $ready, 'validation_status' => $manifest['validation']['status']];
        }, 1);
    }

    private function publish(User $actor, Invoice $stored, string $xml, array $manifest, string $kind): array
    {
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $root = $this->root(true);
        $parent = $root;
        foreach (['v1', $kind, (string) $actor->id, (string) $stored->id] as $segment) {
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
        $reference = 'invoice-cii/v1/'.$kind.'/'.$actor->id.'/'.$stored->id.'/'.$hash;
        $this->readPackage($actor, $stored, $reference, $kind);

        return ['reference' => $reference, 'cii_sha256' => $manifest['cii_sha256'], 'manifest_sha256' => $hash];
    }

    public function read(User $actor, Invoice $invoice, string $reference): array
    {
        return $this->readPackage($actor, $invoice, $reference, 'simulations');
    }

    /** Reads only the real validated namespace; simulations are never reclassified. */
    public function readValidated(User $actor, Invoice $invoice, string $reference): array
    {
        return $this->readPackage($actor, $invoice, $reference, 'validated');
    }

    public function readPending(User $actor, Invoice $invoice, string $reference): array
    {
        return $this->readPackage($actor, $invoice, $reference, 'pending');
    }

    private function readPackage(User $actor, Invoice $invoice, string $reference, string $kind): array
    {
        $stored = $this->owned($actor, $invoice);
        if (! preg_match('~\Ainvoice-cii/v1/'.$kind.'/([1-9][0-9]*)/([1-9][0-9]*)/([a-f0-9]{64})\z~D', $reference, $match)
            || $match[1] !== (string) $actor->id || $match[2] !== (string) $stored->id) {
            throw new RuntimeException('Référence CII non autorisée.');
        }
        $path = $this->root(false);
        foreach (['v1', $kind, $match[1], $match[2], $match[3]] as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;
            $this->assertPath($path);
        }
        $json = $this->bytes($path.DIRECTORY_SEPARATOR.'manifest.json');
        $xml = $this->bytes($path.DIRECTORY_SEPARATOR.'document.xml');
        if (! hash_equals($match[3], hash('sha256', $json))) {
            throw new RuntimeException('Manifeste CII altéré.');
        }
        $manifest = $kind !== 'simulations' ? EmissionJson::object($json)
            : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['user_id'] ?? null) !== $actor->id || ($manifest['invoice_id'] ?? null) !== $stored->id
            || ($manifest['mode'] ?? null) !== (match ($kind) {
                'validated' => 'validated_private_document', 'pending' => 'pending_validation', default => 'offline_simulation'
            })
            || ($manifest['ready_for_emission'] ?? null) !== ($kind === 'validated')
            || ($kind !== 'simulations' && (($manifest['schema_version'] ?? null) !== 1
                || ! in_array($manifest['provenance'] ?? null, $kind === 'validated' ? ['live'] : ['live', 'cached'], true)))
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
