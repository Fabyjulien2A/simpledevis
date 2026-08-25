<?php

namespace App\Services\SuperPdp;

use App\Models\ElectronicInvoiceEvent;
use App\Models\SupplierInvoice;
use App\Models\SuperPdpConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SuperPdpInvoiceService
{

public function __construct(
    private readonly SuperPdpOAuthService $oauthService
) {
}
    /**
     * Récupère une page de factures depuis SUPER PDP.
     *
     * @throws RequestException
     */
    public function fetchInvoices(
        SuperPdpConnection $connection,
        ?int $startingAfterId = null
    ): array {
           $connection = $this->oauthService
        ->ensureValidAccessToken($connection);

        $query = [];

        if ($startingAfterId !== null && $startingAfterId > 0) {
            $query['starting_after_id'] = $startingAfterId;
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500)
            ->get(
                $this->apiUrl() . '/invoices',
                $query
            );

        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'La réponse de SUPER PDP est invalide.'
            );
        }

        return $data;
    }

    /**
     * Récupère le détail complet d’une facture SUPER PDP.
     *
     * @throws RequestException
     */
    public function fetchInvoice(
        SuperPdpConnection $connection,
        int $invoiceId
    ): array {
        $connection = $this->oauthService
    ->ensureValidAccessToken($connection);

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500)
            ->get(
                $this->apiUrl() . '/invoices/' . $invoiceId
            );

        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Le détail de la facture SUPER PDP est invalide.'
            );
        }

        return $data;
    }

    /**
     * Synchronise toutes les nouvelles factures d’une entreprise.
     *
     * Retourne le nombre de factures créées ou mises à jour.
     *
     * @throws RequestException
     */
    public function synchronize(
        SuperPdpConnection $connection
    ): int {
        $synchronizedCount = 0;
        $startingAfterId = (int) $connection->last_invoice_id;

        do {
            $response = $this->fetchInvoices(
                $connection,
                $startingAfterId
            );

            $summaries = $response['data'] ?? [];

            if (!is_array($summaries)) {
                throw new RuntimeException(
                    'La liste des factures SUPER PDP est invalide.'
                );
            }

            foreach ($summaries as $summary) {
                $superPdpInvoiceId = (int) ($summary['id'] ?? 0);

                if ($superPdpInvoiceId <= 0) {
                    continue;
                }

                $invoiceData = $this->fetchInvoice(
                    $connection,
                    $superPdpInvoiceId
                );

                $this->storeInvoice(
                    $connection,
                    $invoiceData
                );

                $startingAfterId = max(
                    $startingAfterId,
                    $superPdpInvoiceId
                );

                $synchronizedCount++;
            }

            $hasAfter = (bool) ($response['has_after'] ?? false);
        } while ($hasAfter === true);

        $connection->update([
            'last_invoice_id' => $startingAfterId,
            'last_sync_at' => now(),
        ]);

        return $synchronizedCount;
    }

    /**
     * Enregistre une facture complète, ses lignes et ses événements.
     */
    private function storeInvoice(
        SuperPdpConnection $connection,
        array $invoiceData
    ): SupplierInvoice {
        return DB::transaction(function () use (
            $connection,
            $invoiceData
        ) {
            $electronicInvoice = $invoiceData['en_invoice'] ?? [];

            $seller = $electronicInvoice['seller'] ?? [];

            $legalIdentifier =
                $seller['legal_registration_identifier'] ?? [];

            $postalAddress =
                $seller['postal_address'] ?? [];

            /*
             * Totaux de la facture.
             */
            $totals = $electronicInvoice['totals'] ?? [];

            $totalHt = $this->extractAmount(
                $totals['total_without_vat']
                    ?? $totals['sum_invoice_lines_amount']
                    ?? 0
            );

            $totalVat = $this->extractAmount(
                $totals['total_vat_amount']
                    ?? 0
            );

            $totalTtc = $this->extractAmount(
                $totals['total_with_vat']
                    ?? 0
            );

            $amountDue = $this->extractAmount(
                $totals['amount_due_for_payment']
                    ?? $totalTtc
            );

            /*
             * Création ou mise à jour de la facture.
             */
            $supplierInvoice = SupplierInvoice::updateOrCreate(
                [
                    'company_id' => $connection->company_id,

                    'superpdp_invoice_id' =>
                        (int) $invoiceData['id'],
                ],
                [
                    'superpdp_company_id' =>
                        $invoiceData['company_id'] ?? null,

                    'direction' =>
                        $invoiceData['direction'] ?? 'in',

                    'invoice_number' =>
                        $electronicInvoice['number']
                            ?? 'SUPERPDP-' . $invoiceData['id'],

                    'type_code' =>
                        isset($electronicInvoice['type_code'])
                            ? (string) $electronicInvoice['type_code']
                            : null,

                    'invoice_date' =>
                        $electronicInvoice['issue_date']
                            ?? now()->toDateString(),

                    'due_date' =>
                        $electronicInvoice['payment_due_date']
                            ?? null,

                    'supplier_name' =>
                        $seller['name']
                            ?? 'Fournisseur non renseigné',

                    'supplier_siren' =>
                        $this->extractFrenchIdentifier(
                            $legalIdentifier
                        ),

                    'supplier_vat_number' =>
                        $seller['vat_identifier']['value']
                            ?? $seller['vat_identifier']
                            ?? null,

                    'supplier_email' =>
                        $seller['electronic_address']['value']
                            ?? $seller['email']
                            ?? null,

                    'supplier_address' =>
                        $postalAddress['street_name']
                            ?? $postalAddress['line_one']
                            ?? $postalAddress['address_line']
                            ?? null,

                    'supplier_postal_code' =>
                        $postalAddress['post_code']
                            ?? $postalAddress['postal_code']
                            ?? null,

                    'supplier_city' =>
                        $postalAddress['city_name']
                            ?? $postalAddress['city']
                            ?? null,

                    'currency' =>
                        $electronicInvoice['currency_code']
                            ?? 'EUR',

                    'total_ht' => $totalHt,
                    'total_vat' => $totalVat,
                    'total_ttc' => $totalTtc,
                    'amount_due' => $amountDue,

                    'status' =>
                        $this->resolveStatus($invoiceData),

                    'received_at' =>
                        $invoiceData['created_at']
                            ?? now(),

                    'payload' => $invoiceData,
                ]
            );

            /*
             * On remplace les anciennes lignes par celles
             * actuellement reçues de SUPER PDP.
             */
            $supplierInvoice->items()->delete();

            $lines = $electronicInvoice['lines'] ?? [];

            if (!is_array($lines)) {
                $lines = [];
            }

            foreach ($lines as $index => $line) {
                $quantity = (float) (
                    $line['quantité_facturée']
                    ?? $line['quantite_facturee']
                    ?? $line['invoiced_quantity']
                    ?? $line['quantity']
                    ?? 1
                );

                $lineTotalHt = $this->extractAmount(
                    $line['montant_net']
                        ?? $line['net_amount']
                        ?? $line['line_total']
                        ?? 0
                );

                $priceDetails =
                    $line['détails_prix']
                    ?? $line['details_prix']
                    ?? $line['price_details']
                    ?? [];

                $unitPrice = $this->extractAmount(
                    $priceDetails['prix_net_article']
                        ?? $priceDetails['net_item_price']
                        ?? $priceDetails['unit_price']
                        ?? $line['prix_unitaire']
                        ?? $line['unit_price']
                        ?? 0
                );

                /*
                 * Si SUPER PDP ne fournit pas le prix unitaire,
                 * on le calcule depuis le total HT et la quantité.
                 */
                if ($unitPrice <= 0 && $quantity > 0) {
                    $unitPrice = round(
                        $lineTotalHt / $quantity,
                        6
                    );
                }

                $vatInformation =
                    $line['informations_TVA']
                    ?? $line['informations_tva']
                    ?? $line['vat_information']
                    ?? [];

                $vatRate = (float) (
                    $vatInformation['invoiced_item_vat_rate']
                    ?? 0
                );

                $vatAmount = round(
                    $lineTotalHt * $vatRate / 100,
                    2
                );

                $supplierInvoice->items()->create([
                    'line_number' =>
                        $line['identifier']
                            ?? $line['id']
                            ?? ($index + 1),

                    'description' =>
                        $line['informations_article']['nom']
                            ?? $line['informations_article']['name']
                            ?? $line['item_information']['name']
                            ?? $line['description']
                            ?? 'Ligne de facture',

                    'quantity' => $quantity,

                    'unit_code' =>
                        $line['code_quantité_facturée']
                            ?? $line['code_quantite_facturee']
                            ?? $line['invoiced_quantity_code']
                            ?? $line['unit_code']
                            ?? null,

                    'unit_price_ht' => $unitPrice,

                    'line_total_ht' => $lineTotalHt,

                    'vat_rate' => $vatRate,

                    'vat_amount' => $vatAmount,

                    'line_total_ttc' => round(
                        $lineTotalHt + $vatAmount,
                        2
                    ),

                    'discount_amount' => 0,

                    'payload' => $line,
                ]);
            }

            /*
             * Synchronisation des événements SUPER PDP.
             */
            $events = $invoiceData['events'] ?? [];

            if (!is_array($events)) {
                $events = [];
            }

            foreach ($events as $event) {
                $superPdpEventId = (int) ($event['id'] ?? 0);

                if ($superPdpEventId <= 0) {
                    continue;
                }

                ElectronicInvoiceEvent::updateOrCreate(
                    [
                        'superpdp_event_id' =>
                            $superPdpEventId,
                    ],
                    [
                        'supplier_invoice_id' =>
                            $supplierInvoice->id,

                        'event_type' =>
                            $event['status_text']
                                ?? $event['event_type']
                                ?? $event['status_code']
                                ?? 'Événement SUPER PDP',

                        'status' =>
                            $event['status_code']
                                ?? $event['status']
                                ?? null,

                        'event_date' =>
                            $event['created_at']
                                ?? $event['event_date']
                                ?? null,

                        'payload' => $event,
                    ]
                );
            }

            return $supplierInvoice;
        });
    }

    /**
     * Extrait proprement un montant SUPER PDP.
     *
     * Le montant peut être une chaîne, un nombre ou un tableau
     * contenant une clé "value".
     */
    private function extractAmount(
        mixed $amount
    ): float {
        if (is_array($amount)) {
            $amount = $amount['value']
                ?? $amount['amount']
                ?? 0;
        }

        if (is_string($amount)) {
            $amount = str_replace(',', '.', $amount);
        }

        return round((float) $amount, 2);
    }

    /**
     * Détermine le statut à partir du dernier événement SUPER PDP.
     */
    private function resolveStatus(
        array $invoiceData
    ): string {
        $events = $invoiceData['events'] ?? [];

        if (!is_array($events) || empty($events)) {
            return 'received';
        }

        $lastEvent = end($events);

        $statusCode =
            $lastEvent['status_code']
            ?? $lastEvent['code']
            ?? null;

        return match ($statusCode) {
            'fr:202' => 'received',
            'fr:203' => 'processing',
            'fr:204' => 'accepted',
            'fr:205' => 'rejected',
            default => 'received',
        };
    }

    /**
     * Récupère l’identifiant juridique français du fournisseur.
     */
    private function extractFrenchIdentifier(
        array|string|null $identifier
    ): ?string {
        if (is_array($identifier)) {
            $value = $identifier['value'] ?? null;
        } else {
            $value = $identifier;
        }

        if (!$value) {
            return null;
        }

        $digits = preg_replace(
            '/\D/',
            '',
            (string) $value
        );

        if (!$digits) {
            return null;
        }

        /*
         * Un SIRET contient 14 chiffres.
         * On conserve le SIREN : les neuf premiers chiffres.
         */
        if (strlen($digits) === 14) {
            return substr($digits, 0, 9);
        }

        return $digits;
    }


    /**
     * Retourne l’URL de base de l’API.
     */
    private function apiUrl(): string
    {
        $apiUrl = rtrim(
            (string) config('services.superpdp.api_url'),
            '/'
        );

        if ($apiUrl === '') {
            throw new RuntimeException(
                'L’URL de l’API SUPER PDP n’est pas configurée.'
            );
        }

        return $apiUrl;
    }
}