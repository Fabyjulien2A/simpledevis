<?php

namespace App\Services\SuperPdp\Emission;

interface EmissionTransport
{
    public function mode(): string;

    /** @return array{status: int, body: string} */
    public function send(EmissionDocument $document, string $reference): array;

    public function lookup(int $invoiceId): array;

    public function original(int $invoiceId): string;

    public function events(int $invoiceId, ?int $cursor): array;
}
