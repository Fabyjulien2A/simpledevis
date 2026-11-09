<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\SuperPdp\InvoiceEmissionContext;
use LogicException;
use Illuminate\Validation\ValidationException;

/** Capture persistée réservée à la transaction de finalisation de l'appelant. */
class InvoicePartySnapshotCapture
{
    public function __construct(private readonly InvoiceLogoArchive $logos) {}

    private const COMPANY_FIELDS = [
        'company_name', 'name', 'legal_status', 'siret', 'vat_number', 'tva_number', 'vat_regime',
        'address', 'postal_code', 'city', 'country_code', 'email', 'phone', 'iban', 'bic',
        'payment_terms', 'invoice_recovery_indemnity_text', 'invoice_late_payment_penalties_text',
        'invoice_early_payment_discount_text', 'logo',
    ];

    private const CLIENT_FIELDS = [
        'company_name', 'first_name', 'last_name', 'full_name', 'client_type', 'siret', 'vat_number',
        'address', 'postal_code', 'city', 'country_code', 'email', 'phone',
    ];

    public function captureForFinalization(Invoice $invoice): void
    {
        $connection = $invoice->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('La capture des parties exige une transaction de finalisation.');
        }

        $stored = $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
        if (! $stored->isDraft()) {
            throw new LogicException('Seul un brouillon peut recevoir son instantané de finalisation.');
        }
        if ($stored->getRawOriginal('party_snapshot') !== null) {
            try {
                $existing = InvoicePartySnapshot::fromJson($stored->getRawOriginal('party_snapshot'))->toArray();
            } catch (InvoicePartySnapshotException $exception) {
                throw ValidationException::withMessages(['party_snapshot' => $exception->getMessage()]);
            }
            if ($existing['schema_version'] === InvoicePartySnapshot::PRIVATE_LOGO_SCHEMA_VERSION) {
                $this->logos->resolve((int) $stored->user_id, $existing['seller']['logo'] ?? null);
            }
            if ($stored->emission_profile !== null && $stored->getRawOriginal('emission_context') === null) {
                throw ValidationException::withMessages(['emission_profile' => 'Un instantané existant ne peut pas être enrichi rétroactivement pour l’émission.']);
            }
            return;
        }

        // Même connexion et ordre de verrous pour les deux parcours : facture, entreprise, client.
        $company = Company::on($connection->getName())->where('user_id', $stored->user_id)->lockForUpdate()->first();
        $client = Client::on($connection->getName())->whereKey($stored->client_id)
            ->where('user_id', $stored->user_id)->lockForUpdate()->firstOrFail();

        // Attributs réellement stockés uniquement : aucun accesseur, nom User de repli,
        // pays FR implicite, rapprochement des alias TVA ou contexte de requête HTTP.
        $seller = array_intersect_key($company?->getRawOriginal() ?? [], array_flip(self::COMPANY_FIELDS));
        if (($seller['country_code'] ?? null) === null) {
            unset($seller['country_code']);
        }
        if (array_key_exists('logo', $seller)) {
            $seller['logo'] = $this->logos->archive((int) $stored->user_id, $seller['logo']);
        }
        $snapshot = InvoicePartySnapshot::capture(
            $seller,
            array_intersect_key($client->getRawOriginal(), array_flip(self::CLIENT_FIELDS)),
            InvoicePartySnapshot::PRIVATE_LOGO_SCHEMA_VERSION,
        );

        // Non fillable : l'instantané n'est jamais accepté depuis un formulaire.
        // jsonSerialize() conserve les parties vides sous forme d'objets JSON.
        $stored->party_snapshot = $snapshot->jsonSerialize();
        $stored->emission_context = InvoiceEmissionContext::capture(
            $stored->emission_profile, $company?->billing_electronic_address, $client->billing_electronic_address,
            $snapshot->toArray(),
        );
        $stored->save();
    }
}
