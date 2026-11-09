<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Référence logique : jamais un chemin ou une URL fournis au système de fichiers. */
final readonly class InvoiceLogoReference
{
    private function __construct(public string $userId, public string $sha256, public string $extension) {}

    public static function parse(string $reference): self
    {
        if (! preg_match('~\Ainvoice-logos/v1/([1-9][0-9]{0,19})/([a-f0-9]{64})\.(png|jpg|webp)\z~D', $reference, $matches)) {
            throw new InvalidArgumentException('Référence du logo archivé invalide.');
        }

        return new self($matches[1], $matches[2], $matches[3]);
    }

    public function path(): string
    {
        return $this->userId.'/'.$this->sha256.'.'.$this->extension;
    }
}
