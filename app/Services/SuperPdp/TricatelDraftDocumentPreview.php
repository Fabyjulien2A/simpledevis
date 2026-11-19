<?php

namespace App\Services\SuperPdp;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/** Read-only draft projection. Never a payload, a frozen snapshot or a CII preparation. */
final class TricatelDraftDocumentPreview
{
    public function __construct(
        private readonly TricatelDraftPreviewScope $scope,
        private readonly InvoiceFinalizationSummary $summaries,
    ) {}

    public function build(User $actor, Invoice $invoice): array
    {
        $this->scope->company($actor, $invoice);
        // Discard route-bound/cached attributes and reauthorize the persisted draft.
        $draft = $invoice->newQuery()->whereKey($invoice->getKey())->firstOrFail();
        $company = $this->scope->company($actor, $draft);
        $client = $draft->client_id === null ? null : Client::on($draft->getConnectionName())->find($draft->client_id);
        abort_unless($client === null || $client->user_id === $actor->id, 403);

        $summary = $this->summaries->build($company, $client, [
            'date' => $draft->date?->format('Y-m-d'), 'due_date' => $draft->due_date?->format('Y-m-d'),
            'french_business_process_type' => $draft->french_business_process_type,
        ], $draft);
        $storedItems = $draft->items()->orderBy('id')->get()->map(fn ($item) => $item->only([
            'description', 'quantity', 'unit_price_ht', 'tva_rate', 'vat_category', 'unit_code',
            'exemption_reason_code', 'exemption_reason', 'line_total_ht',
        ]))->all();
        $blockers = array_merge($summary['groups']['Entreprise'], $summary['groups']['Client'],
            $summary['groups']['Facture'], $summary['unavailable']);
        // Calculated amounts never replace stored amounts, even if the latter are inconsistent.
        foreach ($summary['data']['totals'] as $field => $calculated) {
            if (! $this->sameAmount($draft->getRawOriginal($field), $calculated)) {
                $blockers[] = ['code' => 'draft.'.$field.'.mismatch', 'category' => 'invalid_data', 'field' => $field,
                    'message' => 'Le montant '.$field.' enregistré diffère du calcul des lignes. Aucune correction effectuée.'];
            }
        }
        foreach ($summary['data']['items'] as $index => $item) {
            if (! $this->sameAmount($storedItems[$index]['line_total_ht'] ?? null, $item['line_total_ht'])) {
                $blockers[] = ['code' => 'draft.line_total_ht.mismatch', 'category' => 'invalid_data', 'field' => 'items.'.$index,
                    'message' => 'Le montant HT enregistré de la ligne '.($index + 1).' diffère du calcul. Aucune correction effectuée.'];
            }
        }

        return [
            'invoice_id' => $draft->id, 'data' => $summary['data'], 'stored_items' => $storedItems,
            'blockers' => $blockers,
            // Show the declaration even when the production validator rejects it.
            'declared_context' => [
                'emission_profile' => $draft->emission_profile,
                'french_business_process_type' => $draft->french_business_process_type,
                'seller_electronic_address' => $company->billing_electronic_address,
                'buyer_electronic_address' => $client?->billing_electronic_address,
            ],
            // The scheme comes from the existing OAuth company 4 / owner 4 mapping.
            // These metadata must never be merged into the seller's legal/fiscal fields.
            'sandbox' => ['company_id' => 4, 'owner_user_id' => 4,
                'superpdp_identity' => ['number_scheme' => 'sandbox', 'number' => '000000001'],
                'identity_source' => 'local_oauth_binding', 'remote_verification_performed' => false],
            'cii_produced' => false, 'conformity_established' => false,
            'emission_allowed' => false, 'routing_verified' => false,
        ];
    }

    private function sameAmount(mixed $stored, string $calculated): bool
    {
        try {
            return is_string($stored) && BigDecimal::of($stored)->isEqualTo($calculated);
        } catch (\InvalidArgumentException|MathException) {
            return false;
        }
    }
}
