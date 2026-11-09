<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Archives locales privées, adressées par contenu et jamais supprimées ou remplacées. */
class InvoiceLogoArchive
{
    private const MAX_BYTES = 2097152;

    /** L'appelant détient le verrou utilisateur commun au remplacement et à l'émission. */
    public function archive(int $userId, ?string $logo): ?string
    {
        if ($logo === null || trim($logo) === '') {
            return null;
        }

        try {
            if ($userId <= 0 || config('filesystems.disks.public.driver') !== 'local'
                || preg_match('~\A[\\\\/]|:|\x00|(?:\A|[\\\\/])\.\.(?:[\\\\/]|\z)~', $logo)) {
                throw new RuntimeException('Source invalide.');
            }
            $public = Storage::disk('public');
            $sourceRoot = realpath($public->path(''));
            $source = realpath($public->path($logo));
            if ($sourceRoot === false || $source === false || ! str_starts_with($source, $sourceRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Source absente.');
            }
            // Lire une seule fois : l'empreinte et la copie portent sur les mêmes octets.
            $bytes = $this->readBytes($source);
            $extension = $this->imageExtension($bytes);
            $hash = hash('sha256', $bytes);
            $reference = 'invoice-logos/v1/'.$userId.'/'.$hash.'.'.$extension;
            $root = $this->root(true);
            $directory = $root.DIRECTORY_SEPARATOR.$userId;
            if (! is_dir($directory) && ! mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new RuntimeException('Création du répertoire impossible.');
            }
            if (realpath($directory) !== $directory || is_link($directory)) {
                throw new RuntimeException('Répertoire non conforme.');
            }
            $path = $directory.DIRECTORY_SEPARATOR.$hash.'.'.$extension;
            if (! file_exists($path) && ! is_link($path)) {
                $this->publish($path, $bytes);
            }
            // Vérifier aussi une archive préexistante, sans jamais la réparer par écrasement.
            $this->verifiedPath(InvoiceLogoReference::parse($reference), $userId);

            return $reference;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'logo' => 'Le logo déclaré ne peut pas être archivé et vérifié. La facture n’a pas été finalisée.',
            ]);
        }
    }

    public function resolve(int $userId, ?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        try {
            return $this->verifiedPath(InvoiceLogoReference::parse($reference), $userId);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'party_snapshot' => 'Le logo archivé est absent, illisible, altéré ou non autorisé. Aucun logo actuel ne peut le remplacer.',
            ]);
        }
    }

    private function verifiedPath(InvoiceLogoReference $reference, int $userId): string
    {
        if ($reference->userId !== (string) $userId) {
            throw new RuntimeException('Propriétaire invalide.');
        }
        $root = $this->root(false);
        $directory = $root.DIRECTORY_SEPARATOR.$reference->userId;
        $path = $directory.DIRECTORY_SEPARATOR.$reference->sha256.'.'.$reference->extension;
        if (realpath($directory) !== $directory || is_link($directory)
            || realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Chemin non conforme.');
        }
        $bytes = $this->readBytes($path);
        if (! hash_equals($reference->sha256, hash('sha256', $bytes))
            || $this->imageExtension($bytes) !== $reference->extension) {
            throw new RuntimeException('Intégrité invalide.');
        }

        return $path;
    }

    private function root(bool $create): string
    {
        if (config('filesystems.disks.invoice_logos.driver') !== 'local'
            || config('filesystems.disks.invoice_logos.serve', false)) {
            throw new RuntimeException('Stockage privé local requis.');
        }
        $configured = Storage::disk('invoice_logos')->path('');
        if ($create && ! is_dir($configured) && ! mkdir($configured, 0700, true) && ! is_dir($configured)) {
            throw new RuntimeException('Stockage indisponible.');
        }
        $root = realpath($configured);
        if ($root === false) {
            throw new RuntimeException('Stockage indisponible.');
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

        return $root;
    }

    protected function readBytes(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Fichier illisible.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Ouverture impossible.');
        }
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                throw new RuntimeException('Lecture impossible ou taille invalide.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private function imageExtension(string $bytes): string
    {
        $info = getimagesizefromstring($bytes);
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp',
            default => throw new RuntimeException('Format non pris en charge.'),
        };
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Image illisible.');
        }
        imagedestroy($image);

        return $extension;
    }

    /** Publication atomique par lien physique, sans écrasement du nom final. */
    protected function publish(string $path, string $bytes): void
    {
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(16)).'.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Création impossible.');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Permissions privées impossibles.');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Écriture incomplète.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Synchronisation impossible.');
            }
            fclose($stream);
            $stream = null;
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->readBytes($temporary)))) {
                throw new RuntimeException('Copie incomplète.');
            }
            // link() échoue si la destination existe : ne jamais la remplacer.
            if (! @link($temporary, $path) && ! file_exists($path)) {
                throw new RuntimeException('Publication impossible.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Seul notre temporaire est supprimé, jamais le fichier partagé final.
            @unlink($temporary);
        }
    }
}
