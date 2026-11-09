<?php

namespace Tests\Support;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\Emission\EmissionDocument;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use RuntimeException;

/** Jonction de TEST uniquement : aucune archive déplacée, requalifiée ou autorisée en production. */
final readonly class SimulatedEmissionBridge implements EmissionPrerequisites
{
    public string $reference;

    public function __construct(private int $ownerId, private int $invoiceId, private string $simulationReference)
    {
        $this->assertTesting();
        // Alias en mémoire du contrat d'entrée de l'orchestrateur, sans fichier "validated".
        $this->reference = 'invoice-cii/v1/validated/'.$ownerId.'/'.$invoiceId.'/'.hash('sha256', 'TEST-ONLY:'.$simulationReference);
    }

    public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument
    {
        $this->assertTesting();
        if ($actor->id !== $this->ownerId || $invoice->id !== $this->invoiceId || $reference !== $this->reference
            || ! app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['local_checks_passed']) {
            throw new RuntimeException('Jonction de test non autorisée ou facture non éligible localement.');
        }
        $archive = app(PreparedCiiArchive::class)->read($actor, $invoice, $this->simulationReference);
        $manifest = $archive['manifest'];
        $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
        $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
        $hash = fn (array $value) => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($manifest['mode'] !== 'offline_simulation' || $manifest['ready_for_emission'] !== false
            || $manifest['routing']['status'] !== 'not_verified' || ! $manifest['routing']['qualified_for_simulation']
            || $manifest['seller']['status'] !== 'not_verified' || ! $manifest['seller']['checks_passed_for_simulation']
            || $manifest['validation']['status'] !== 'passed_simulation'
            || $manifest['source_sha256'] !== $hash($data) || $manifest['payload_sha256'] !== $hash($payload)) {
            throw new RuntimeException('La simulation ne correspond plus aux sources figées.');
        }
        app(CiiDocumentChecks::class)->assertMatches($archive['xml'], $payload);
        app(CiiDocumentChecks::class)->assertReport($manifest['validation']['report'], $archive['xml']);

        return new EmissionDocument($actor->id, $invoice->id, $this->reference, $archive['xml'],
            $manifest['seller']['company_id'], $manifest['seller']['environment'], 'validated_private_document');
    }

    private function assertTesting(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Cet adaptateur ne fait pas partie du parcours applicatif.');
        }
    }
}
