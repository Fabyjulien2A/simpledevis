<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InvoiceEmissionPreparation
{
    public function assertCompleteForFinalization(Invoice $invoice): void
    {
        if ($invoice->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Le contrôle exige la transaction de finalisation.');
        }
        $stored = $invoice->fresh();
        if ($stored->emission_profile === null) {
            return; // Le parcours classique reste disponible sans données inventées.
        }
        if (mb_strlen((string) $stored->invoice_number) > 35) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Le numéro d’une facture électronique ne doit pas dépasser 35 caractères (BR-FR-01 / BT-1).',
            ]);
        }
        $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($stored);
        $errors = [];
        foreach ($result['blockers'] as $blocker) {
            if ($blocker['code'] !== 'routing.verification_unavailable') {
                $errors[$blocker['field']][] = $blocker['message'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
