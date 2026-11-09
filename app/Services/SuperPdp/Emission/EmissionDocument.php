<?php

namespace App\Services\SuperPdp\Emission;

use InvalidArgumentException;

/** Données retournées par une source de documents autorisée, jamais par un formulaire. */
final readonly class EmissionDocument
{
    public string $sha256;

    public function __construct(
        public int $userId,
        public int $invoiceId,
        public string $reference,
        public string $xml,
        public int $companyId,
        public string $environment,
        public string $mode,
    ) {
        self::assertReference($reference, $userId, $invoiceId);
        if ($xml === '' || strlen($xml) > 5242880 || $companyId <= 0
            || ! in_array($environment, ['sandbox', 'production'], true) || $mode !== 'validated_private_document') {
            throw new InvalidArgumentException('Document CII non autorisé pour un dépôt.');
        }
        $this->sha256 = hash('sha256', $xml);
    }

    public static function assertReference(string $reference, int $userId, int $invoiceId): void
    {
        if (! preg_match('~\Ainvoice-cii/v1/validated/([1-9][0-9]*)/([1-9][0-9]*)/[a-f0-9]{64}\z~D', $reference, $match)
            || $match[1] !== (string) $userId || $match[2] !== (string) $invoiceId) {
            throw new InvalidArgumentException('Une archive simulée, étrangère ou non validée ne peut pas servir au dépôt.');
        }
    }
}
