<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Local OAuth binding only: no token refresh, remote verification or routing evidence. */
final class TricatelDraftPreviewScope
{
    public function company(User $actor, Invoice $invoice): Company
    {
        abort_unless($actor->exists && $actor->id === 4 && auth()->id() === 4
            && $invoice->exists && $invoice->user_id === 4, 403);
        foreach ([$actor, $invoice] as $model) {
            abort_unless(in_array($model->getConnectionName(), [null, SandboxDatabaseIsolation::CONNECTION], true), 403);
        }
        try {
            SandboxDatabaseIsolation::assertConfiguredScope();
        } catch (RuntimeException) {
            abort(403);
        }
        abort_unless(config('services.superpdp.environment') === 'sandbox'
            && config('services.superpdp.api_url') === SandboxEmissionAuthorization::API_URL, 403);
        abort_unless($invoice->isDraft(), 404);
        // In today's model the company is scoped through user_id, not an invoice company_id.
        // Reject a conflicting explicit company binding as well, if one is present.
        abort_unless(in_array($invoice->getRawOriginal('company_id'), [null, 4], true), 403);
        abort_if($invoice->getRawOriginal('party_snapshot') !== null
            || $invoice->getRawOriginal('emission_context') !== null, 409,
            'Un brouillon avec des données figées ne peut pas utiliser cet aperçu des fiches actuelles.');

        $company = $actor->company()->first();
        abort_unless($company !== null && $company->id === 4 && $company->user_id === 4
            && $company->company_name === 'Tricatel', 403);
        $connection = $company->superPdpConnection()->first();
        abort_unless($connection !== null && $connection->company_id === 4
            && $connection->directory_identifier === '000000001' && $connection->status === 'connected'
            && is_int($connection->superpdp_company_id) && $connection->superpdp_company_id > 0, 403);
        $clientId = config('services.superpdp.client_id');
        abort_unless(is_string($clientId) && $clientId !== '', 403);
        try {
            $connection->assertOAuthContext('sandbox', $clientId, SandboxEmissionAuthorization::API_URL);
        } catch (RuntimeException) {
            abort(403);
        }

        return $company;
    }

    public function allows(User $actor, Invoice $invoice): bool
    {
        try {
            $this->company($actor, $invoice);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }
}
