<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ClosedEmissionPrerequisites implements EmissionPrerequisites
{
    public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument
    {
        throw ValidationException::withMessages(['emission' => 'Émission désactivée : le diagnostic global reste bloquant et les preuves réelles de routage, d’habilitation et de validation sont requises.']);
    }
}
