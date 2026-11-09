<?php

namespace Tests\Support;

use App\Services\SuperPdp\Emission\EmissionDocument;
use App\Services\SuperPdp\Emission\EmissionTransport;

/** Faux serveur pour les entrelacements et pertes de réponse, sans aucun client réseau. */
final class EmissionTestTransport implements EmissionTransport
{
    public int $calls = 0;

    public mixed $onSend = null;

    public array $remote = [];

    public string $bytes = '';

    public array $page = ['data' => [], 'has_after' => false];

    public array $eventQueries = [];

    public function mode(): string
    {
        return 'test';
    }

    public function send(EmissionDocument $document, string $reference): array
    {
        $this->calls++;
        $this->bytes = $document->xml;
        $this->remote = ['id' => 900, 'company_id' => $document->companyId, 'direction' => 'out',
            'external_id' => $reference, 'created_at' => '2026-11-10T10:00:00+00:00'];
        if ($this->onSend) {
            return ($this->onSend)($document, $reference);
        }

        return ['status' => 200, 'body' => json_encode($this->remote)];
    }

    public function lookup(int $invoiceId): array
    {
        return $this->remote;
    }

    public function original(int $invoiceId): string
    {
        return $this->bytes;
    }

    public function events(int $invoiceId, ?int $cursor): array
    {
        $this->eventQueries[] = [$invoiceId, $cursor];

        return $this->page;
    }
}
