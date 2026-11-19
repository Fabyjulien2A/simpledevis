<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Invoice;
use App\Models\User;

/** Explicit, scoped entry point. Never the default transport binding. */
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

    public function forHttpInvoice(User $actor, Invoice $invoice, string $reference, ?HttpEmissionConfirmation $confirmation = null): OutgoingEmission
    {
        HttpEmission::assertSandboxRuntime();
        // Time-sensitive document/OAuth/approval checks run inside the engine's
        // claim transaction, so a local refusal can be durably recorded.
        $transport = new SandboxSdkEmissionTransport($actor->id, $invoice->id, $reference,
            $this->authorization, $this->prerequisites, true, $confirmation);

        return new OutgoingEmission($this->prerequisites, $transport);
    }
}
