<?php

namespace App\Services\SuperPdp;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoiceFiscalItemTranslator;
use App\Services\InvoiceLinePreparationService;
use App\Services\InvoicePartySnapshotCapture;
use Illuminate\Validation\ValidationException;

/** Aperçu des sources qui seront figées, jamais une attestation d'éligibilité. */
class InvoiceFinalizationSummary
{
    public function __construct(
        private readonly InvoicePartySnapshotCapture $snapshots,
        private readonly SuperPdpEmissionEligibilityDiagnostic $diagnostic,
        private readonly InvoiceLinePreparationService $lines,
        private readonly InvoiceFiscalItemTranslator $translator,
    ) {}

    public function build(?Company $company, ?Client $client, array $input, ?Invoice $draft = null): array
    {
        $data = $this->snapshots->previewParties($company, $client);
        // Les données proviennent ici des fiches actuelles, pas d'un instantané enregistré.
        unset($data['party_snapshot']);
        $sandbox = ($input['emission_profile'] ?? null) === SandboxInvoiceProfile::PROFILE;
        $context = $sandbox ? [] : InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE, null, null, $data);
        $addressErrors = [];
        foreach ($sandbox ? [] : ['seller' => $company, 'buyer' => $client] as $party => $model) {
            try {
                $context[$party.'_electronic_address'] = DeclaredBillingAddress::validate(
                    $model?->billing_electronic_address, $model?->siret,
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $addressErrors[] = ['code' => $party.'.electronic_address.invalid', 'category' => 'invalid_data',
                            'field' => $party.'.electronic_address', 'message' => $message];
                    }
                }
            }
        }
        $lineErrors = [];
        try {
            $items = ! array_key_exists('items', $input) && $draft
                ? $draft->items()->orderBy('id')->get()->map(fn ($item) => $item->only([
                    'description', 'quantity', 'unit_price_ht', 'tva_rate', 'vat_category', 'unit_code',
                    'exemption_reason_code', 'exemption_reason', 'line_total_ht',
                ]))->all()
                : $this->translator->translate($input['items'] ?? []);
            $prepared = $this->lines->prepare($items);
        } catch (ValidationException $exception) {
            $prepared = ['items' => [], 'totals' => []];
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $lineErrors[] = ['code' => 'lines.invalid', 'category' => 'invalid_data', 'field' => $field,
                        'message' => $message];
                }
            }
        }
        $data = array_merge($data, [
            'date' => $input['date'] ?? null, 'due_date' => $input['due_date'] ?? null,
            'french_business_process_type' => $input['french_business_process_type'] ?? null,
            'calculation_version' => $draft ? $draft->calculation_version : 'decimal-v1',
            'status' => $draft?->status ?? 'non_payee', 'amount_paid' => $draft?->amount_paid ?? '0.00',
            'payments' => $draft ? $draft->payments()->pluck('id')->all() : [],
            'issued_credit_notes' => $draft ? $draft->creditNotes()->where('status', 'issued')->pluck('id')->all() : [],
            'items' => $prepared['items'], 'totals' => $prepared['totals'] === [] ? [] : [
                'subtotal_ht' => $prepared['totals']['total_ht'],
                'total_tva' => $prepared['totals']['total_vat'], 'total_ttc' => $prepared['totals']['total_ttc'],
            ],
        ]);
        if ($sandbox) {
            // Preview only: use the existing sandbox scope, never the French legal diagnostic.
            SandboxInvoiceProfile::assertRuntime();
            $errors = array_column($lineErrors, 'message');
            if ($client === null) {
                $errors[] = 'Choisissez votre client Tricatel de test.';
            } else {
                try {
                    $context = SandboxInvoiceProfile::parties($company, $client)->toArray();
                } catch (ValidationException $exception) {
                    $errors = array_merge($errors, $exception->validator->errors()->all());
                }
            }

            return compact('sandbox', 'data', 'context', 'errors');
        }
        $result = $this->diagnostic->preview($data, $context);
        $groups = ['Entreprise' => [], 'Client' => [], 'Facture' => []];
        $unavailable = [];
        foreach (array_merge($result['blockers'], $addressErrors, $lineErrors) as $blocker) {
            if ($blocker['code'] === 'routing.verification_unavailable') {
                $unavailable[] = $blocker;

                continue;
            }
            $field = $blocker['field'];
            $group = str_starts_with($field, 'seller.') || str_starts_with($field, 'company.') || $field === 'vat_regime'
                ? 'Entreprise' : (str_starts_with($field, 'buyer.') ? 'Client' : 'Facture');
            $groups[$group][] = $blocker;
        }

        // Same diagnostic categories, no second rule for France/B2B or unsupported cases.
        // Missing documentary fields remain editable on a draft; finalization still requires all of them.
        $compatibilityBlockers = array_values(array_filter($result['blockers'], fn ($blocker) => $blocker['category'] === 'unsupported'
            || in_array($blocker['field'], ['seller.country_code', 'buyer.country_code', 'buyer.client_type'], true)));
        $profileCompatible = $company !== null && $client !== null && $compatibilityBlockers === [];

        return compact('data', 'context', 'groups', 'unavailable', 'profileCompatible', 'compatibilityBlockers');
    }

    public function assertProfileCompatible(?string $profile, ?Company $company, Client $client, array $input, ?Invoice $draft = null): void
    {
        if ($profile === SandboxInvoiceProfile::PROFILE) {
            SandboxInvoiceProfile::parties($company, $client);
            return;
        }
        if ($profile !== InvoiceEmissionContext::PROFILE) {
            return; // Ordinary PDF invoices do not require electronic qualification.
        }
        $summary = $this->build($company, $client, $input, $draft);
        if (! $summary['profileCompatible']) {
            throw ValidationException::withMessages(['emission_profile' => array_merge([
                'Ce profil électronique est incompatible avec les informations renseignées. Corrigez les fiches ou choisissez « Sans qualification électronique » pour une facture PDF classique.',
            ], array_column($summary['compatibilityBlockers'], 'message'))]);
        }
    }
}
