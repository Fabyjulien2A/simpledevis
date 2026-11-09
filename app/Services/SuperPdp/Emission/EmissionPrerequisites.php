<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Invoice;
use App\Models\User;

interface EmissionPrerequisites
{
    /** Lecture privée locale seulement. Vérifier éligibilité, preuves réelles fraîches et CII exact. */
    public function authorize(User $actor, Invoice $invoice, string $reference): EmissionDocument;
}
