<?php

namespace App\Services\SuperPdp\Emission;

use LogicException;

final class DisabledEmissionTransport implements EmissionTransport
{
    public function mode(): string
    {
        return 'disabled';
    }

    public function send(EmissionDocument $document, string $reference): array
    {
        throw new LogicException('Transport fermé.');
    }

    public function lookup(int $invoiceId): array
    {
        throw new LogicException('Transport fermé.');
    }

    public function original(int $invoiceId): string
    {
        throw new LogicException('Transport fermé.');
    }

    public function events(int $invoiceId, ?int $cursor): array
    {
        throw new LogicException('Transport fermé.');
    }
}
