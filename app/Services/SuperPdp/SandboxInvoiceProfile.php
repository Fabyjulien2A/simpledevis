<?php

namespace App\Services\SuperPdp;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Persisted test documents only. Never a French identity, approval or transport switch. */
final class SandboxInvoiceProfile
{
    public const PROFILE = 'sandbox_superpdp_bq_tricatel_v1';

    public const LABEL = 'Sandbox SUPER PDP';

    public const NOTICE = 'Facture de test — sans valeur fiscale';

    public static function is(Invoice $invoice): bool
    {
        return $invoice->emission_profile === self::PROFILE;
    }

    public static function assertRuntime(): void
    {
        abort_unless(app()->environment('sandbox') && config('app.env') === 'sandbox'
            && app()->bound(SandboxDatabaseIsolation::class)
            && config('services.superpdp.environment') === 'sandbox'
            && config('services.superpdp.api_url') === Emission\SandboxEmissionAuthorization::API_URL, 403);
        SandboxDatabaseIsolation::assertConfiguredScope();
        abort_unless(app('db') instanceof SandboxDatabaseManager, 403);
    }

    public static function available(): bool
    {
        try {
            self::assertRuntime();

            return auth()->id() === 3;
        } catch (Throwable) {
            return false;
        }
    }

    public static function profiles(): array
    {
        return self::available() ? [InvoiceEmissionContext::PROFILE, self::PROFILE] : [InvoiceEmissionContext::PROFILE];
    }

    public static function parties(?Company $company, Client $client): SandboxEmissionContext
    {
        self::assertRuntime();
        abort_unless(auth()->id() === 3 && $company?->id === 3 && $company->user_id === 3 && $client->user_id === 3, 403);
        abort_unless($company->company_name === 'Burger Queen' && $company->siret === null, 403);
        $context = SandboxEmissionContext::forCompany($company);
        // The explicit profile binds company 4; its identity is never inferred from a client name.
        $buyer = Company::on($company->getConnectionName())->whereKey(4)->where('user_id', 4)->firstOrFail();
        foreach ([[$company, 1, 18172, '000000002'], [$buyer, 2, 18171, '000000001']] as [$party, $id, $provider, $number]) {
            $connection = $party->superPdpConnection()->sole();
            abort_unless($connection->id === $id && $connection->superpdp_company_id === $provider
                && $connection->environment === 'sandbox' && $connection->directory_identifier === $number, 403);
            $connection->assertOAuthContext('sandbox', (string) config('services.superpdp.client_id'), Emission\SandboxEmissionAuthorization::API_URL);
        }
        if ($buyer->company_name !== 'Tricatel' || $buyer->siret !== null || $client->company_name !== 'Tricatel'
            || $client->client_type !== 'professional' || $client->country_code !== 'FR' || $client->siret !== null
            || ($client->billing_electronic_address !== null && $client->billing_electronic_address !== $context->toArray()['buyer']['electronic_address'])) {
            throw ValidationException::withMessages(['client_id' => 'Choisissez votre client Tricatel de test, sans SIRET, pour le destinataire sandbox explicitement lié à l’entreprise 4.']);
        }

        return $context;
    }

    public static function capture(Invoice $invoice, Company $company, Client $client): array
    {
        $context = self::parties($company, $client);

        return ['schema_version' => 1, 'profile' => self::PROFILE, 'document_kind' => 'sandbox_test',
            'invoice_id' => $invoice->id, 'owner_user_id' => 3, 'company_id' => 3, 'client_id' => $client->id,
            'buyer_company_id' => 4, 'seller_connection_id' => 1, 'buyer_connection_id' => 2,
            'declaration' => $context->toArray(),
            // 4-character prefix + 26-character ULID = 30, within BT-1's limit of 35.
            // Existing frozen references are preserved verbatim.
            'test_reference' => $invoice->emission_context['test_reference'] ?? 'SBX-'.(string) Str::ulid(),
            'document_sha256' => CiiPreparationEvidence::hash([
                'date' => $invoice->date?->format('Y-m-d'), 'due_date' => $invoice->due_date?->format('Y-m-d'),
                'business_process_type' => $invoice->french_business_process_type, 'calculation_version' => $invoice->calculation_version,
                'totals' => $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc']),
                'items' => $invoice->items()->orderBy('id')->get()->map(fn ($item) => $item->only([
                    'id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht', 'unit_code',
                    'vat_category', 'exemption_reason_code', 'exemption_reason']))->all(),
            ]),
            'party_snapshot_sha256' => hash('sha256', $invoice->getAttributes()['party_snapshot']),
            'test_notice' => self::NOTICE];
    }

    public static function payload(array $data, SuperPdpInvoicePayloadBuilder $builder): array
    {
        self::assertRuntime();
        $frozen = EmissionJson::object($data['emission_context'] ?? '');
        $invoice = Invoice::findOrFail($frozen['invoice_id'] ?? 0);
        abort_unless(self::is($invoice) && $invoice->user_id === 3 && auth()->id() === 3, 403);
        $company = Company::findOrFail(3);
        $client = Client::on($invoice->getConnectionName())->findOrFail($invoice->client_id);
        $context = self::parties($company, $client);
        $expected = self::capture($invoice, $company, $client);
        if ($frozen !== $expected || $invoice->getRawOriginal('emission_context') !== $data['emission_context']
            || ! $invoice->isIssued() || ! str_starts_with((string) $invoice->invoice_number, 'SBX-')
            || $invoice->invoice_number !== $expected['test_reference']
            || ! hash_equals($expected['party_snapshot_sha256'], hash('sha256', $data['party_snapshot'] ?? ''))
            || CiiPreparationEvidence::hash($data) !== CiiPreparationEvidence::hash(app(InvoiceExportDataBuilder::class)->buildElectronic($invoice))) {
            throw ValidationException::withMessages(['emission_profile' => 'Le contexte sandbox ne correspond pas à cette nouvelle facture figée.']);
        }
        // Keep the frozen SQL snapshot intact. Only the dedicated test builder sees this projection.
        unset($data['party_snapshot'], $data['emission_profile'], $data['emission_context']);
        $data['company_id'] = 3;
        $data['user_id'] = 3;
        $data['company_invoice_mentions'] = array_fill_keys(['PMT', 'PMD', 'AAB'], self::NOTICE);
        $french = ['business_process_type' => $data['french_business_process_type'], 'notes' => array_map(
            fn ($code) => ['subject_code' => $code, 'note' => self::NOTICE], ['PMT', 'PMD', 'AAB'])];

        return $builder->buildSandboxFromData($data, $context, $company, $french)['payload'];
    }

    public static function diagnostic(Invoice $invoice): array
    {
        $blockers = [['code' => 'routing.verification_unavailable', 'category' => 'verification_unavailable',
            'field' => 'buyer.electronic_address', 'message' => 'Les preuves sandbox récentes du vendeur, de sa session et de la destination restent nécessaires.']];
        try {
            $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
            app(SuperPdpInvoicePayloadBuilder::class)->buildFromData($data);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $blockers[] = ['code' => 'sandbox.document.invalid', 'category' => 'invalid_data',
                        'field' => $field, 'message' => $message];
                }
            }
        } catch (InvalidArgumentException $exception) {
            $blockers[] = ['code' => 'sandbox.document.invalid', 'category' => 'invalid_data',
                'field' => 'emission_profile', 'message' => $exception->getMessage()];
        } catch (Throwable) {
            $blockers[] = ['code' => 'sandbox.invalid', 'category' => 'invalid_data', 'field' => 'emission_profile',
                'message' => 'Le profil Sandbox SUPER PDP exige ses parties autorisées, un contexte figé et des données documentaires complètes et concordantes.'];
        }

        return ['eligible' => false, 'local_checks_passed' => count($blockers) === 1, 'blockers' => $blockers];
    }
}
