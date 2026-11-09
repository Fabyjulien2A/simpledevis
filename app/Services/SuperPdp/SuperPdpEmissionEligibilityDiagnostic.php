<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\InvoicePartySnapshotException;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Diagnostic local uniquement : aucune écriture, OAuth, conversion ou transmission.
 * L'autorisation d'accès à la facture relève de l'appelant.
 */
class SuperPdpEmissionEligibilityDiagnostic
{
    public function __construct(
        private readonly InvoiceExportDataBuilder $dataBuilder,
        private readonly InvoiceFiscalDataPreparer $fiscalPreparation,
        private readonly SuperPdpInvoicePayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Le cadre provient de la facture, les parties de l'instantané validé lorsqu'il existe.
     * Les profils actuels ne servent qu'à la lecture historique bloquée pour l'émission.
     * Les autres qualifications absentes du modèle sont explicites, jamais déduites.
     * Aucun booléen de l'appelant ne peut attester le routage.
     *
     * @param array{
     *     document_kind?: mixed, type_code?: mixed, currency_code?: mixed,
     *     vat_regime?: mixed, tax_registration_identifier?: mixed,
     *     seller_electronic_address?: mixed, buyer_electronic_address?: mixed,
     *     french_context?: mixed
     * } $context
     * @return array{eligible: bool, local_checks_passed: bool, blockers: list<array{code: string, category: string, field: string, message: string}>}
     */
    public function diagnose(Invoice $invoice, array $context = []): array
    {
        $blockers = [];
        $this->add($blockers, 'routing.verification_unavailable', 'verification_unavailable', 'buyer.electronic_address',
            'La vérification de la destination électronique n’est pas implémentée. Un SIRET, une adresse ou une connexion SUPER PDP ne prouvent pas le routage.');

        if (! $invoice->exists || $invoice->getKey() === null) {
            $this->add($blockers, 'invoice.not_persisted', 'unsupported', 'invoice', 'Le diagnostic nécessite une facture enregistrée.');

            return $this->result($blockers);
        }

        try {
            // Toujours relire le stockage, sans charger ni modifier les relations de l'appelant.
            $data = $this->dataBuilder->buildElectronic($invoice);
        } catch (InvoicePartySnapshotException $exception) {
            $blockers[] = $exception->blocker();

            return $this->result($blockers);
        } catch (ModelNotFoundException) {
            $this->add($blockers, 'invoice.not_found', 'missing_data', 'invoice', 'La facture enregistrée est introuvable.');

            return $this->result($blockers);
        } catch (InvalidArgumentException) {
            $this->add($blockers, 'invoice.data_unreadable', 'invalid_data', 'invoice', 'Les données enregistrées ne peuvent pas être lues dans leur format attendu.');

            return $this->result($blockers);
        }

        try {
            $data = InvoiceElectronicPartyData::resolve($data, true);
        } catch (InvoicePartySnapshotException $exception) {
            $blockers[] = $exception->blocker();
            if ($exception->reason !== 'missing') {
                return $this->result($blockers);
            }
            // Historique : conserver les contrôles et la lecture existants, sans autoriser un payload.
        }

        foreach (['document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR'] as $field => $expected) {
            if ($this->missing($context[$field] ?? null)) {
                $this->add($blockers, 'context.'.$field.'.missing', 'missing_data', $field, 'Renseignez explicitement la qualification '.$field.' ; elle n’est pas conservée dans la facture actuelle.');
            } elseif ($expected !== null && $context[$field] !== $expected) {
                $this->add($blockers, 'context.'.$field.'.unsupported', 'unsupported', $field, 'Ce diagnostic couvre uniquement les factures ordinaires de type 380 en EUR.');
            }
        }

        if (($data['document_status'] ?? null) !== 'issued') {
            $this->add($blockers, 'invoice.not_issued', 'unsupported', 'document_status', 'La facture doit être définitive.');
        }
        foreach (['invoice_number', 'date', 'calculation_version'] as $field) {
            $this->requireValue($blockers, $data, $field, 'invoice.'.$field.'.missing', 'Le champ '.$field.' de la facture est obligatoire.');
        }
        if (! $this->missing($data['calculation_version'] ?? null) && $data['calculation_version'] !== 'decimal-v1') {
            $this->add($blockers, 'invoice.calculation_version.unsupported', 'unsupported', 'calculation_version', 'Seule la version de calcul decimal-v1 est prise en charge.');
        }

        foreach (['payments' => 'payments', 'issued_credit_notes' => 'credit_notes'] as $field => $code) {
            if (! array_key_exists($field, $data) || ! is_array($data[$field])) {
                $this->add($blockers, 'invoice.'.$code.'.unknown', 'verification_unavailable', $field, 'L’absence de règlements ou d’avoirs définitifs ne peut pas être établie.');
            } elseif ($data[$field] !== []) {
                $this->add($blockers, 'invoice.'.$code.'.unsupported', 'unsupported', $field, 'Les factures avec règlements, acomptes ou avoirs définitifs ne sont pas prises en charge.');
            }
        }
        $paid = $data['amount_paid'] ?? null;
        if (! is_string($paid) || ! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $paid)) {
            $this->add($blockers, 'invoice.amount_paid.invalid', 'invalid_data', 'amount_paid', 'Le montant payé enregistré doit être un décimal exploitable.');
        } elseif (! BigDecimal::of($paid)->isZero() || ($data['status'] ?? null) !== 'non_payee') {
            $this->add($blockers, 'invoice.settlement.unsupported', 'unsupported', 'status', 'Un montant payé nul et le statut non payé sont requis.');
        }

        $this->checkParties($data, $context, $blockers);
        $this->checkTaxRegime($data, $context, $blockers);

        $frenchContext = $this->checkFrenchContext($data, $context, $blockers);

        if (($data['calculation_version'] ?? null) === 'decimal-v1') {
            try {
                $this->fiscalPreparation->prepare($data['items'] ?? [], $data['totals'] ?? [], 'decimal-v1');
            } catch (ValidationException $exception) {
                $this->validationErrors($blockers, $exception, 'fiscal.invalid', $data);
            } catch (InvalidArgumentException $exception) {
                $this->add($blockers, 'fiscal.invalid', 'invalid_data', 'items', $exception->getMessage());
            }
        }

        // Dernière validation partagée avec le vrai builder, sans seconde lecture SQL.
        // Le payload temporaire n'est ni retourné ni conservé : ce résultat n'autorise aucun envoi.
        if ($this->localChecksPassed($blockers)) {
            try {
                $this->payloadBuilder->buildFromData($data, $context['seller_electronic_address'], $context['buyer_electronic_address'], $frenchContext);
            } catch (ValidationException $exception) {
                $this->validationErrors($blockers, $exception, 'payload.invalid', $data);
            } catch (InvalidArgumentException $exception) {
                $this->add($blockers, 'payload.invalid', 'invalid_data', 'payload', $exception->getMessage());
            }
        }

        return $this->result($blockers);
    }

    /**
     * Présence technique seulement, sans validation juridique des textes.
     * Le cadre provient de la facture ; aucun texte manuel ne remplace une mention du profil.
     */
    private function checkFrenchContext(array $data, array $context, array &$blockers): ?array
    {
        $stored = $data['company_invoice_mentions'] ?? [];
        $historical = isset($data['party_snapshot']);
        $notes = [];
        foreach ([
            'PMT' => ['invoice_recovery_indemnity_text', 'l’indemnité forfaitaire pour frais de recouvrement'],
            'PMD' => ['invoice_late_payment_penalties_text', 'les pénalités de retard'],
            'AAB' => ['invoice_early_payment_discount_text', 'l’escompte pour paiement anticipé'],
        ] as $code => [$column, $label]) {
            $text = is_array($stored) ? ($stored[$code] ?? null) : null;
            $notes[] = ['subject_code' => $code, 'note' => $text];
            if ($this->missing($text)) {
                $this->add($blockers, 'company.mentions.'.$code.'.missing', 'missing_data', ($historical ? 'party_snapshot.seller.' : 'company.').$column,
                    $historical ? 'La mention '.$code.' concernant '.$label.' est absente de l’instantané de la facture.'
                        : 'Renseignez la mention '.$code.' concernant '.$label.' dans les paramètres de votre entreprise.');
            } elseif (! is_string($text) || mb_strlen($text) > 2000) {
                $this->add($blockers, 'company.mentions.'.$code.'.invalid', 'invalid_data', ($historical ? 'party_snapshot.seller.' : 'company.').$column,
                    'La mention '.$code.' concernant '.$label.' doit être un texte de 2 000 caractères maximum '.($historical ? 'dans l’instantané de la facture.' : 'dans les paramètres de votre entreprise.'));
            }
        }

        $frenchContext = $context['french_context'] ?? [];
        $process = $data['french_business_process_type'] ?? null;
        foreach (FrenchInvoiceContext::storedBusinessProcessBlockers($process, is_array($frenchContext) ? $frenchContext : []) as $blocker) {
            $blockers[] = $blocker;
        }
        if (! is_array($frenchContext)) {
            $this->add($blockers, 'french_context.invalid', 'invalid_data', 'french_context', 'Le contexte français doit être un tableau structuré.');

            return null;
        }

        $frenchContext['business_process_type'] = $process;

        $fromCompany = ! array_key_exists('notes', $frenchContext);
        if ($fromCompany) {
            $frenchContext['notes'] = $notes;
        } elseif (is_array($frenchContext['notes']) && array_is_list($frenchContext['notes'])) {
            foreach ($frenchContext['notes'] as $note) {
                $code = is_array($note) ? ($note['subject_code'] ?? null) : null;
                if (is_string($code) && in_array($code, ['PMT', 'PMD', 'AAB'], true)
                    && ($note['note'] ?? null) !== (is_array($stored) ? ($stored[$code] ?? null) : null)) {
                    $this->add($blockers, 'company.mentions.'.$code.'.context_conflict', 'invalid_data', 'french_context.notes.'.$code,
                        $historical ? 'La mention '.$code.' fournie dans le contexte contredit celle de l’instantané de la facture. Le contexte ne remplace pas l’instantané.'
                            : 'La mention '.$code.' fournie dans le contexte contredit celle enregistrée dans les paramètres de votre entreprise. Le contexte ne remplace pas le profil.');
                }
            }
        }

        try {
            FrenchInvoiceContext::fromArray($frenchContext);
        } catch (ValidationException $exception) {
            // Les absences du profil ont déjà un blocage précis par code et champ.
            $errors = array_filter($exception->errors(), fn ($field) => $field !== 'french_context.business_process_type'
                && (! $fromCompany || ! str_starts_with($field, 'french_context.notes')), ARRAY_FILTER_USE_KEY);
            if ($errors !== []) {
                $this->validationErrors($blockers, ValidationException::withMessages($errors), 'french_context.invalid', $frenchContext, 'french_context');
            }
        }

        // Le builder reçoit toujours la source persistée, après contrôle du contexte fourni.
        $frenchContext['notes'] = $notes;

        return $frenchContext;
    }

    private function checkParties(array $data, array $context, array &$blockers): void
    {
        foreach (['seller', 'buyer'] as $party) {
            foreach (['address', 'postal_code', 'city', 'country_code', 'siret'] as $field) {
                $this->requireValue($blockers, $data[$party] ?? [], $field, $party.'.'.$field.'.missing', 'Renseignez '.$field.' pour '.($party === 'seller' ? 'le vendeur' : 'l’acheteur').'.', $party.'.');
            }
            $name = $party === 'seller' ? ($data[$party]['name'] ?? null) : ($data[$party]['company_name'] ?? null);
            if ($party === 'buyer' && $this->missing($name)) {
                $name = $data[$party]['full_name'] ?? null;
            }
            if ($this->missing($name)) {
                $this->add($blockers, $party.'.name.missing', 'missing_data', $party.'.name', 'L’identité du '.($party === 'seller' ? 'vendeur' : 'client').' est obligatoire.');
            }
            $country = $data[$party]['country_code'] ?? null;
            if (! $this->missing($country) && $country !== 'FR') {
                $this->add($blockers, $party.'.country.unsupported', 'unsupported', $party.'.country_code', 'Ce périmètre couvre uniquement un vendeur et un acheteur français.');
            }

            $address = $context[$party.'_electronic_address'] ?? null;
            if ($address !== null && ! is_array($address)) {
                $this->add($blockers, $party.'.electronic_address.invalid', 'invalid_data', $party.'.electronic_address', 'L’adresse électronique doit contenir scheme et value.');
            } else {
                foreach (['scheme', 'value'] as $field) {
                    $this->requireValue($blockers, $address ?? [], $field, $party.'.electronic_address.'.$field.'.missing', 'Renseignez explicitement '.$field.' pour l’adresse électronique '.($party === 'seller' ? 'du vendeur.' : 'de l’acheteur.'), $party.'.electronic_address.');
                }
            }
        }
        $type = $data['buyer']['client_type'] ?? null;
        if ($this->missing($type)) {
            $this->add($blockers, 'buyer.client_type.missing', 'missing_data', 'buyer.client_type', 'Le type de client doit être renseigné.');
        } elseif ($type !== 'professional') {
            $this->add($blockers, 'buyer.client_type.unsupported', 'unsupported', 'buyer.client_type', 'Seuls les clients professionnels sont pris en charge.');
        }
    }

    private function checkTaxRegime(array &$data, array $context, array &$blockers): void
    {
        (new FrenchInvoiceTaxRegime)->check($data, $context, $blockers);
    }

    private function missing(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/[^\s\p{Z}]/u', $value) !== 1);
    }

    private function requireValue(array &$blockers, array $data, string $field, string $code, string $message, string $prefix = ''): void
    {
        if ($this->missing($data[$field] ?? null)) {
            $this->add($blockers, $code, 'missing_data', $prefix.$field, $message);
        }
    }

    private function validationErrors(array &$blockers, ValidationException $exception, string $code, array $data, string $prefix = ''): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $source = $prefix !== '' ? preg_replace('/^'.preg_quote($prefix, '/').'\.?/', '', $field) : $field;
            $value = data_get($data, $source, data_get($data['totals'] ?? [], $source));
            // Les erreurs de notes à indice/code peuvent signifier une absence ou une structure invalide.
            $category = $code !== 'payload.invalid' && $this->missing($value) ? 'missing_data' : 'invalid_data';
            foreach ($messages as $message) {
                $this->add($blockers, $code, $category, $field, $message);
            }
        }
    }

    private function add(array &$blockers, string $code, string $category, string $field, string $message): void
    {
        $blockers[] = compact('code', 'category', 'field', 'message');
    }

    private function localChecksPassed(array $blockers): bool
    {
        // Seul le routage est une vérification indépendante des contrôles locaux.
        return array_filter($blockers, fn ($blocker) => $blocker['code'] !== 'routing.verification_unavailable') === [];
    }

    private function result(array $blockers): array
    {
        return ['eligible' => $blockers === [], 'local_checks_passed' => $this->localChecksPassed($blockers), 'blockers' => $blockers];
    }
}
