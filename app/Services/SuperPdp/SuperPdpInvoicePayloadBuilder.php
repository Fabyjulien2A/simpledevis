<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SuperPdpInvoicePayloadBuilder
{
    public function __construct(
        private readonly InvoiceExportDataBuilder $dataBuilder,
        private readonly InvoiceFiscalDataPreparer $fiscalPreparation,
        private readonly SuperPdpInvoicePayloadValidator $payloadValidator
    ) {}

    /**
     * @param  array{scheme: string, value: string}|null  $sellerElectronicAddress
     * @param  array{scheme: string, value: string}|null  $buyerElectronicAddress
     * @param  array{business_process_type?: mixed, notes?: mixed}|null  $frenchContext
     */
    public function build(
        Invoice $invoice,
        ?array $sellerElectronicAddress = null,
        ?array $buyerElectronicAddress = null,
        ?array $frenchContext = null
    ): array {
        if (! $invoice->isIssued()) {
            throw new InvalidArgumentException('Seule une facture définitive peut être préparée pour SUPER PDP.');
        }

        if (trim((string) $invoice->invoice_number) === '') {
            throw new InvalidArgumentException('La facture doit posséder un numéro avant sa préparation pour SUPER PDP.');
        }

        if ($invoice->date === null) {
            throw new InvalidArgumentException('La facture doit posséder une date d’émission avant sa préparation pour SUPER PDP.');
        }

        if (! $invoice->exists && $sellerElectronicAddress === null) {
            throw new InvalidArgumentException('L’adresse électronique de facturation du vendeur doit être fournie explicitement.');
        }

        return $this->buildWithData(fn () => $this->dataBuilder->buildElectronic($invoice), $sellerElectronicAddress, $buyerElectronicAddress, $frenchContext, $invoice->exists);
    }

    /**
     * Valide et construit un instantané fourni explicitement, sans base ni réseau.
     * Ne prouve ni sa provenance, ni une autorisation d'émission ou un routage.
     */
    public function buildFromData(
        array $data,
        ?array $sellerElectronicAddress = null,
        ?array $buyerElectronicAddress = null,
        ?array $frenchContext = null
    ): array {
        return $this->buildWithData(fn () => $data, $sellerElectronicAddress, $buyerElectronicAddress, $frenchContext);
    }

    private function buildWithData(
        callable $source,
        ?array $sellerElectronicAddress,
        ?array $buyerElectronicAddress,
        ?array $frenchContext,
        bool $persisted = false
    ): array {
        if ($sellerElectronicAddress !== null) {
            // Garder le refus précoce des entrées explicites mal formées.
            $this->requiredString($sellerElectronicAddress, 'scheme', 'Le schéma de l’adresse électronique du vendeur');
            $this->requiredString($sellerElectronicAddress, 'value', 'La valeur de l’adresse électronique du vendeur');
        }
        $data = InvoiceElectronicPartyData::resolve($source(), $persisted);
        $supplied = [];
        if ($sellerElectronicAddress !== null) {
            $supplied['seller_electronic_address'] = $sellerElectronicAddress;
        }
        if ($buyerElectronicAddress !== null) {
            $supplied['buyer_electronic_address'] = $buyerElectronicAddress;
        }
        $qualified = InvoiceEmissionContext::resolve($data, $supplied);
        $sellerElectronicAddress = $qualified['seller_electronic_address'] ?? null;
        $buyerElectronicAddress = $qualified['buyer_electronic_address'] ?? null;
        if ($sellerElectronicAddress === null) {
            throw new InvalidArgumentException('L’adresse électronique de facturation du vendeur doit être fournie explicitement.');
        }

        // La vérification du circuit et de l’inscription à l’annuaire relève de l’appelant.
        // Aucune adresse n’est déduite du SIRET ou de l’e-mail de contact.
        $electronicAddress = [
            'scheme' => $this->requiredString($sellerElectronicAddress, 'scheme', 'Le schéma de l’adresse électronique du vendeur'),
            'value' => $this->requiredString($sellerElectronicAddress, 'value', 'La valeur de l’adresse électronique du vendeur'),
        ];

        $context = $persisted || array_key_exists('french_business_process_type', $data)
            ? FrenchInvoiceContext::fromInvoiceData($data, $frenchContext)
            : FrenchInvoiceContext::fromArray($frenchContext);

        // Contrôler aussi l'instantané relu, pas seulement le modèle fourni par l'appelant.
        if (($data['document_status'] ?? null) !== 'issued') {
            throw new InvalidArgumentException('Seule une facture définitive peut être préparée pour SUPER PDP.');
        }

        $this->assertNoSettlement($data);
        if (isset($data['party_snapshot'])) {
            $taxBlockers = [];
            (new FrenchInvoiceTaxRegime)->check($data, [], $taxBlockers);
            if ($taxBlockers !== []) {
                $errors = [];
                foreach ($taxBlockers as $blocker) {
                    $errors[$blocker['field']][] = $blocker['message'];
                }
                throw ValidationException::withMessages($errors);
            }
        }
        $fiscal = $this->fiscalPreparation->prepare(
            $data['items'] ?? [],
            $data['totals'] ?? [],
            $data['calculation_version'] ?? null
        );

        $payload = [
            'number' => $this->requiredString($data, 'invoice_number', 'Le numéro de facture'),
            'issue_date' => $this->requiredDate($data, 'date', 'La date d’émission'),
            'type_code' => 380,
            'currency_code' => 'EUR',
            // OpenAPI SUPER PDP 1.39.0.beta, process_control.specification_identifier (BT-24).
            // BT-24 inchangé ; BT-23 provient de la facture ou du scénario explicite.
            'process_control' => [
                'specification_identifier' => 'urn:cen.eu:en16931:2017',
                'business_process_type' => $context->businessProcessType,
            ],
            'notes' => $context->notes,
            'seller' => $this->buildSeller($data['seller'] ?? [], $electronicAddress),
            'buyer' => $this->buildBuyer($data['buyer'] ?? [], $buyerElectronicAddress),
            'lines' => array_map(fn ($item, $index) => $this->buildLine($item, $index), $fiscal['items'], array_keys($fiscal['items'])),
            'vat_break_down' => array_map(fn ($group) => $this->buildVatBreakdown($group), $fiscal['vat_breakdowns']),
            'totals' => [
                'sum_invoice_lines_amount' => $fiscal['totals']['subtotal_ht'],
                'total_without_vat' => $fiscal['totals']['subtotal_ht'],
                'total_vat_amount' => ['value' => $fiscal['totals']['total_tva'], 'currency_code' => 'EUR'],
                'total_with_vat' => $fiscal['totals']['total_ttc'],
                // Autorisé uniquement après preuve de l'absence de règlements et d'avoirs.
                'amount_due_for_payment' => $fiscal['totals']['total_ttc'],
            ],
        ];

        if ($this->optionalString($data, 'due_date', 'La date d’échéance') !== null) {
            $payload['payment_due_date'] = $this->requiredDate($data, 'due_date', 'La date d’échéance');
        }

        $terms = $this->optionalString($data['seller'] ?? [], 'payment_terms', 'Les conditions de paiement');
        if ($terms !== null) {
            $payload['payment_terms'] = $terms;
        }
        if (BigDecimal::of($fiscal['totals']['total_ttc'])->isPositive()
            && ! isset($payload['payment_due_date']) && ! isset($payload['payment_terms'])) {
            throw new InvalidArgumentException('Une échéance ou des conditions de paiement sont obligatoires lorsque le montant dû est positif.');
        }

        $this->payloadValidator->validate($payload);

        return $payload;
    }

    private function assertNoSettlement(array $data): void
    {
        // Ne pas utiliser remaining_amount : le PDF déduit les avoirs et les paiements.
        // Même un paiement nul ou postérieur est refusé tant que sa qualification n'est pas gérée.
        if (! array_key_exists('payments', $data) || $data['payments'] !== []) {
            throw new InvalidArgumentException('Les factures avec paiements ou acomptes ne sont pas prises en charge ; leur absence doit être établie.');
        }
        if (! array_key_exists('issued_credit_notes', $data) || $data['issued_credit_notes'] !== []) {
            throw new InvalidArgumentException('Les factures avec avoirs définitifs ne sont pas prises en charge ; leur absence doit être établie.');
        }
        $paid = $data['amount_paid'] ?? null;
        if (! is_string($paid) || ! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $paid)
            || ! BigDecimal::of($paid)->isZero() || ($data['status'] ?? null) !== 'non_payee') {
            throw new InvalidArgumentException('L’absence de paiement anticipé doit être établie : montant payé nul et statut non payé requis.');
        }
    }

    private function buildLine(array $item, int $index): array
    {
        $vat = [
            'invoiced_item_vat_category_code' => $item['vat_category'],
            'invoiced_item_vat_rate' => $item['tva_rate'],
        ];
        foreach (['exemption_reason_code', 'exemption_reason'] as $key) {
            if (isset($item[$key]) && trim($item[$key]) !== '') {
                $vat[$key] = $item[$key];
            }
        }

        return [
            'identifier' => (string) ($index + 1),
            'invoiced_quantity' => $item['quantity'],
            'invoiced_quantity_code' => $item['unit_code'],
            'net_amount' => $item['line_total_ht'],
            'price_details' => [
                'item_net_price' => $item['unit_price_ht'],
                'item_price_base_quantity' => '1.00',
                'quantity_unit_code' => $item['unit_code'],
            ],
            'item_information' => ['name' => $item['description']],
            'vat_information' => $vat,
        ];
    }

    private function buildVatBreakdown(array $group): array
    {
        $result = [
            'vat_category_code' => $group['vat_category'],
            'vat_category_rate' => $group['vat_rate'],
            'vat_category_taxable_amount' => $group['taxable_amount'],
            'vat_category_tax_amount' => $group['vat_amount'],
        ];
        foreach (['exemption_reason_code' => 'vat_exemption_reason_code', 'exemption_reason' => 'vat_exemption_reason'] as $source => $target) {
            if ($group[$source] !== null) {
                $result[$target] = $group[$source];
            }
        }

        return $result;
    }

    private function requiredDate(array $data, string $key, string $label): string
    {
        $value = $this->requiredString($data, $key, $label);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label.' doit être une date valide au format YYYY-MM-DD.');
        }

        return $value;
    }

    private function buildSeller(array $data, array $electronicAddress): array
    {
        $name = $this->requiredString($data, 'name', 'Le nom du vendeur');
        $siret = $this->requiredString($data, 'siret', 'Le SIRET du vendeur');

        $siren = FrenchSellerIdentifiers::sirenFromSiret($siret);

        $countryCode = $this->requiredString($data, 'country_code', 'Le code pays du vendeur');

        if (! preg_match('/^[A-Z]{2}$/D', $countryCode)) {
            throw new InvalidArgumentException('Le code pays du vendeur doit contenir deux lettres majuscules.');
        }

        $seller = [
            'name' => $name,
            'legal_registration_identifier' => [
                // BT-30 : SIREN de l'entreprise, extrait du SIRET validé sans conversion numérique.
                'value' => $siren,
                'scheme' => '0002',
            ],
            'identifiers' => $this->buildSellerIdentifiers($data, $siret),
            'electronic_address' => $electronicAddress,
            'postal_address' => [
                'address_line1' => $this->requiredString($data, 'address', 'L’adresse postale du vendeur'),
                'post_code' => $this->requiredString($data, 'postal_code', 'Le code postal du vendeur'),
                'city' => $this->requiredString($data, 'city', 'La ville du vendeur'),
                'country_code' => $countryCode,
            ],
        ];

        $vatNumber = $this->optionalString($data, 'vat_number', 'Le numéro de TVA du vendeur');

        if ($vatNumber !== null) {
            $seller['vat_identifier'] = $vatNumber;
        }

        // BT-32 vient de la source (dont le mapping métier de franchise), jamais d'un régime déduit ici.
        $taxIdentifier = $this->optionalString($data, 'tax_registration_identifier', 'L’identifiant fiscal du vendeur');
        if ($taxIdentifier !== null) {
            $seller['tax_registration_identifier'] = $taxIdentifier;
        }

        $email = $this->optionalString($data, 'email', 'L’e-mail de contact du vendeur');
        $phone = $this->optionalString($data, 'phone', 'Le téléphone du vendeur');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('L’e-mail de contact du vendeur est invalide.');
        }

        $contact = [];

        if ($email !== null) {
            $contact['email_address'] = $email;
        }

        if ($phone !== null) {
            $contact['phone_number'] = $phone;
        }

        if ($contact !== []) {
            $seller['contact'] = $contact;
        }

        return $seller;
    }

    private function buildSellerIdentifiers(array $data, string $siret): array
    {
        $identifiers = $data['identifiers'] ?? [];
        if (! is_array($identifiers) || ! array_is_list($identifiers)) {
            throw new InvalidArgumentException('Les identifiants du vendeur doivent former une liste.');
        }

        $hasSiret = false;
        foreach ($identifiers as $identifier) {
            if (! is_array($identifier)) {
                throw new InvalidArgumentException('Chaque identifiant du vendeur doit être un objet avec scheme et value.');
            }
            $scheme = $this->requiredString($identifier, 'scheme', 'Le schéma de l’identifiant du vendeur');
            $value = $this->requiredString($identifier, 'value', 'La valeur de l’identifiant du vendeur');
            if ($scheme !== $identifier['scheme'] || $value !== $identifier['value']) {
                throw new InvalidArgumentException('Les identifiants du vendeur ne doivent pas contenir d’espaces périphériques.');
            }
            if ($scheme === '0009') {
                if (! preg_match('/^[0-9]{14}$/D', $value) || substr($value, 0, 9) !== substr($siret, 0, 9)) {
                    throw new InvalidArgumentException('Un identifiant SIRET du vendeur est invalide ou incohérent avec son SIREN.');
                }
                $hasSiret = $hasSiret || $value === $siret;
            }
        }

        // BT-29 : préserver les autres identifiants et ne pas dupliquer le SIRET déjà présent.
        if (! $hasSiret) {
            $identifiers[] = ['value' => $siret, 'scheme' => '0009'];
        }

        return $identifiers;
    }

    private function buildBuyer(array $data, ?array $electronicAddress): array
    {
        $clientType = $this->requiredString($data, 'client_type', 'Le type de client');

        if (! in_array($clientType, ['professional', 'individual'], true)) {
            throw new InvalidArgumentException('Le type de client doit être professional ou individual.');
        }

        $countryCode = $this->requiredString($data, 'country_code', 'Le code pays de l’acheteur');

        if (! preg_match('/^[A-Z]{2}$/D', $countryCode)) {
            throw new InvalidArgumentException('Le code pays de l’acheteur doit contenir deux lettres majuscules.');
        }

        if ($clientType === 'professional' && $countryCode !== 'FR') {
            throw new InvalidArgumentException('Le mapping des acheteurs professionnels étrangers n’est pas encore pris en charge.');
        }

        $name = $clientType === 'professional'
            ? $this->optionalString($data, 'company_name', 'La raison sociale de l’acheteur')
            : null;
        $name ??= $this->requiredString($data, 'full_name', 'Le nom de l’acheteur');

        if (preg_match('/^client\s+sans\s+nom$/iu', $name)) {
            throw new InvalidArgumentException('Le nom de l’acheteur doit être une identité réelle, pas « Client sans nom ».');
        }

        $buyer = [
            'name' => $name,
            'postal_address' => [
                'address_line1' => $this->requiredString($data, 'address', 'L’adresse postale de l’acheteur'),
                'post_code' => $this->requiredString($data, 'postal_code', 'Le code postal de l’acheteur'),
                'city' => $this->requiredString($data, 'city', 'La ville de l’acheteur'),
                'country_code' => $countryCode,
            ],
        ];

        if ($clientType === 'professional') {
            $siret = $this->optionalString($data, 'siret', 'Le SIRET de l’acheteur');

            if ($siret !== null) {
                if (! preg_match('/^[0-9]{14}$/D', $siret)) {
                    throw new InvalidArgumentException('Le SIRET de l’acheteur doit contenir exactement 14 chiffres.');
                }

                // Le SIREN est la partie entreprise du SIRET fourni, sans génération d’identifiant.
                $buyer['legal_registration_identifier'] = [
                    'value' => substr($siret, 0, 9),
                    'scheme' => '0002',
                ];
                $buyer['identifiers'] = [
                    ['value' => $siret, 'scheme' => '0009'],
                ];
            }

            $vatNumber = $this->optionalString($data, 'vat_number', 'Le numéro de TVA de l’acheteur');

            if ($vatNumber !== null) {
                if (! preg_match('/^[A-Z]{2}[A-Z0-9]+$/D', $vatNumber)) {
                    throw new InvalidArgumentException('Le numéro de TVA de l’acheteur doit comporter un préfixe pays et une valeur alphanumérique.');
                }

                $buyer['vat_identifier'] = $vatNumber;
            }
        }

        $email = $this->optionalString($data, 'email', 'L’e-mail de contact de l’acheteur');
        $phone = $this->optionalString($data, 'phone', 'Le téléphone de l’acheteur');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('L’e-mail de contact de l’acheteur est invalide.');
        }

        $contact = [];

        if ($email !== null) {
            $contact['email_address'] = $email;
        }

        if ($phone !== null) {
            $contact['phone_number'] = $phone;
        }

        if ($contact !== []) {
            $buyer['contact'] = $contact;
        }

        // Construction uniquement : aucune déduction d’adresse ni autorisation d’émission B2B/B2C.
        if ($electronicAddress !== null) {
            $buyer['electronic_address'] = [
                'scheme' => $this->requiredString($electronicAddress, 'scheme', 'Le schéma de l’adresse électronique de l’acheteur'),
                'value' => $this->requiredString($electronicAddress, 'value', 'La valeur de l’adresse électronique de l’acheteur'),
            ];
        }

        return $buyer;
    }

    private function requiredString(array $data, string $key, string $label): string
    {
        $value = $this->optionalString($data, $key, $label);

        if ($value === null) {
            throw new InvalidArgumentException($label.' est obligatoire.');
        }

        return $value;
    }

    private function optionalString(array $data, string $key, string $label): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException($label.' doit être une chaîne de caractères.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
