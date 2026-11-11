<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Invoice;
use App\Models\User;

/** Explicit entry point only: no route, schedule or default container binding. */
final class SandboxEmissionFactory
{
    public function __construct(
        private readonly SandboxEmissionAuthorization $authorization,
        private readonly SandboxEmissionPrerequisites $prerequisites,
    ) {}

    public function forInvoice(User $actor, Invoice $invoice, string $reference): OutgoingEmission
    {
        $transport = new SandboxSdkEmissionTransport($actor->id, $invoice->id, $reference, $this->authorization, $this->prerequisites);
        $transport->assertReady();

        return new OutgoingEmission($this->prerequisites, $transport);
    }
}
