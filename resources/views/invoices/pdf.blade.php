<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
            padding: 30px;
        }

        .document {
            width: 100%;
        }

        .header {
            width: 100%;
            margin-bottom: 30px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
        }

        .logo {
            max-height: 70px;
            margin-bottom: 10px;
        }

        .company-block {
            width: 55%;
        }

        .invoice-block {
            width: 45%;
            text-align: right;
        }

        .document-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 8px;
            letter-spacing: 2px;
        }

        .muted {
            color: #666;
        }

        .section {
            margin-bottom: 25px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            width: 50%;
            vertical-align: top;
        }

        .box-title {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
            text-transform: uppercase;
            color: #444;
        }

        .box {
            border: 1px solid #dcdcdc;
            padding: 12px;
            border-radius: 4px;
            line-height: 1.6;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .items-table thead th {
            background: #f3f4f6;
            border-bottom: 1px solid #dcdcdc;
            padding: 10px 8px;
            text-align: left;
            font-size: 12px;
        }

        .items-table tbody td {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 8px;
            vertical-align: top;
        }

        .text-right {
            text-align: right;
        }

        .totals-wrapper {
            width: 100%;
            margin-top: 25px;
        }

        .totals-table {
            width: 340px;
            margin-left: auto;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .totals-table .label {
            text-align: left;
        }

        .totals-table .value {
            text-align: right;
            width: 140px;
        }

        .grand-total td {
            font-size: 15px;
            font-weight: bold;
            border-top: 2px solid #1a1a1a;
            border-bottom: none;
            padding-top: 10px;
        }

        .notes {
            margin-top: 25px;
            font-size: 11px;
            color: #444;
            line-height: 1.7;
        }

        .footer {
            margin-top: 35px;
            font-size: 11px;
            color: #666;
            border-top: 1px solid #dcdcdc;
            padding-top: 15px;
            line-height: 1.6;
        }

        .tax-details { margin-top: 5px; font-size: 10px; line-height: 1.4; overflow-wrap: break-word; }
        .tax-table { font-size: 10px; }
        .tax-table thead th { font-size: 10px; }
        .items-table thead { display: table-header-group; }
        .items-table td.text-right { white-space: nowrap; }
        .invoice-lines { table-layout: fixed; }
        .invoice-lines td.text-right { font-size: 10px; }
        .totals-table, .notes { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="document">

        @php
            // Les autres appelants de cette vue conservent leur parcours actuel
            // tant qu'ils ne fournissent pas explicitement les données préparées.
            $hasPreparedData = isset($pdfData);
            $isDecimalPdf = $hasPreparedData && $pdfData['calculation_version'] === 'decimal-v1';
            $formatAmount = $hasPreparedData
                ? fn ($amount) => $decimalFormatter->format($amount)
                : fn ($amount) => number_format($amount, 2, ',', ' ');
            $pdfItems = $hasPreparedData ? collect($pdfData['items'])->map(fn ($item) => (object) $item) : $invoice->items;
            $pdfPayments = $hasPreparedData ? collect($pdfData['payments'])->map(fn ($payment) => (object) $payment) : $invoice->payments;
            $fiscalLabel = fn ($category, $code) => match ($category) {
                'S' => 'TVA applicable (S)',
                'E' => $code === 'VATEX-FR-FRANCHISE' ? 'Franchise en base (E)' : 'Autre exonération (E)',
                'Z' => 'Taux zéro (Z)',
                default => '',
            };
            $unitLabels = ['C62' => 'Unité', 'HUR' => 'Heure', 'MTR' => 'Mètre'];
            $companyName = $company->company_name ?? $company->name ?? $invoice->user->name;

            $statusLabel = match ($invoice->status) {
                'non_payee' => 'Non payée',
                'payee' => 'Payée',
                'partiellement_payee' => 'Partiellement payée',
                default => ucfirst($invoice->status),
            };

            $tvaRate = $pdfItems->first()?->tva_rate ?? 0;

            $lastPayment = $pdfPayments->sortByDesc('paid_at')->first();

            $lastMethodLabel = $lastPayment
                ? match ($lastPayment->method) {
                    'virement' => 'Virement',
                    'carte' => 'Carte bancaire',
                    'especes' => 'Espèces',
                    'cheque' => 'Chèque',
                    default => ucfirst($lastPayment->method ?? 'Non renseigné'),
                }
                : null;

            // À remplacer plus tard par un vrai champ si besoin
            $serviceDate = $invoice->date;
        @endphp

        {{-- En-tête --}}
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="company-block">
                        @if(!empty($company?->logo))
                            <img src="{{ public_path('storage/' . $company->logo) }}" alt="Logo" class="logo">
                        @endif

                        <div style="font-size: 16px; font-weight: bold; margin-bottom: 6px;">
                            {{ $companyName }}
                        </div>

                        @if(!empty($company?->legal_status))
                            <div>{{ $company->legal_status }}</div>
                        @endif

                        @if(!empty($company?->address))
                            <div>{{ $company->address }}</div>
                        @endif

                        @if(!empty($company?->postal_code) || !empty($company?->city))
                            <div>{{ $company->postal_code ?? '' }} {{ $company->city ?? '' }}</div>
                        @endif

                        @if(!empty($company?->email))
                            <div>{{ $company->email }}</div>
                        @endif

                        @if(!empty($company?->phone))
                            <div>{{ $company->phone }}</div>
                        @endif

                        @if(!empty($company?->siret))
                            <div>SIRET : {{ $company->siret }}</div>
                        @endif

                        @if(!empty($company?->vat_number))
                            <div>TVA intracommunautaire : {{ $company->vat_number }}</div>
                        @endif

                        @if(!empty($company?->iban))
                            <div>IBAN : {{ $company->iban }}</div>
                        @endif

                        @if(!empty($company?->bic))
                            <div>BIC : {{ $company->bic }}</div>
                        @endif
                    </td>

                    <td class="invoice-block">
                        <div class="document-title">FACTURE</div>
                        <div><strong>Numéro :</strong> {{ $hasPreparedData ? $pdfData['invoice_number'] : $invoice->invoice_number }}</div>
                        <div><strong>Date d’émission :</strong> {{ $invoice->date->format('d/m/Y') }}</div>
                        <div><strong>Date de prestation :</strong> {{ $serviceDate->format('d/m/Y') }}</div>

                        @if($invoice->due_date)
                            <div><strong>Échéance :</strong> {{ $invoice->due_date->format('d/m/Y') }}</div>
                        @endif

                        @if($invoice->quote)
                            <div><strong>Devis lié :</strong> {{ $invoice->quote->quote_number }}</div>
                        @endif

                        <div style="margin-top: 8px;">
                            <strong>Statut :</strong> {{ $statusLabel }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Client + infos --}}
        <div class="section">
            <table class="info-table">
                <tr>
                    <td style="padding-right: 10px;">
                        <div class="box-title">Facturé à</div>
                        <div class="box">
                            <div style="font-weight: bold; margin-bottom: 4px;">
                                {{ $invoice->client->full_name }}
                            </div>

                            @if(!empty($invoice->client->company_name))
                                <div>{{ $invoice->client->company_name }}</div>
                            @endif

                            @if(!empty($invoice->client->address))
                                <div>{{ $invoice->client->address }}</div>
                            @endif

                            @if(!empty($invoice->client->postal_code) || !empty($invoice->client->city))
                                <div>{{ $invoice->client->postal_code ?? '' }} {{ $invoice->client->city ?? '' }}</div>
                            @endif

                            @if(!empty($invoice->client->email))
                                <div>{{ $invoice->client->email }}</div>
                            @endif

                            @if(!empty($invoice->client->phone))
                                <div>{{ $invoice->client->phone }}</div>
                            @endif
                        </div>
                    </td>

                    <td style="padding-left: 10px;">
                        <div class="box-title">Informations de règlement</div>
                        <div class="box">
                            <div>
                                <strong>Montant payé :</strong>
                                {{ $formatAmount($hasPreparedData ? $pdfData['totals']['amount_paid'] : $invoice->amount_paid_calculated) }} €
                            </div>

                            <div>
                                <strong>Reste à payer :</strong>
                                {{ $formatAmount($hasPreparedData ? $pdfData['totals']['remaining_amount'] : $invoice->remaining_amount) }} €
                            </div>

                            @if($lastMethodLabel)
                                <div>
                                    <strong>Dernier mode de paiement :</strong>
                                    {{ $lastMethodLabel }}
                                </div>
                            @endif

                            <div>
                                <strong>Devise :</strong> EUR
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Tableau des lignes --}}
        <div class="section">
            <table class="items-table invoice-lines">
                <thead>
                    <tr>
                        <th style="width: 44%">Description</th>
                        <th style="width: 14%" class="text-right">{{ $isDecimalPdf ? 'Qté / unité' : 'Qté' }}</th>
                        <th style="width: 16%" class="text-right">Prix unitaire HT</th>
                        <th style="width: 11%" class="text-right">TVA</th>
                        <th style="width: 15%" class="text-right">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pdfItems as $item)
                        <tr>
                            <td>
                                {{ $item->description }}
                                @if($isDecimalPdf)
                                    <div class="tax-details">
                                        {{ $fiscalLabel($item->vat_category, $item->exemption_reason_code) }}
                                        @if($item->exemption_reason_code)<br>Code : {{ $item->exemption_reason_code }}@endif
                                        @if($item->exemption_reason)<br>Motif : {{ $item->exemption_reason }}@endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-right">
                                {{ $formatAmount($item->quantity) }}
                                @if($isDecimalPdf)<div class="tax-details">{{ $unitLabels[$item->unit_code] }} ({{ $item->unit_code }})</div>@endif
                            </td>
                            <td class="text-right">{{ $formatAmount($item->unit_price_ht) }} €</td>
                            <td class="text-right">{{ $formatAmount($item->tva_rate) }} %</td>
                            <td class="text-right">{{ $formatAmount($item->line_total_ht) }} €</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="muted">Aucune ligne de facture.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($isDecimalPdf)
            <div class="section">
                <div class="box-title">Ventilation de TVA</div>
                <table class="items-table tax-table">
                    <thead>
                        <tr><th>Traitement fiscal</th><th class="text-right">Taux</th><th class="text-right">Base HT</th><th class="text-right">Montant TVA</th></tr>
                    </thead>
                    <tbody>
                        @foreach($pdfData['vat_breakdowns'] as $breakdown)
                            <tr>
                                <td>
                                    {{ $fiscalLabel($breakdown['vat_category'], $breakdown['exemption_reason_code']) }}
                                    @if($breakdown['exemption_reason_code'])<div class="tax-details">Code : {{ $breakdown['exemption_reason_code'] }}</div>@endif
                                    @if($breakdown['exemption_reason'])<div class="tax-details">Motif : {{ $breakdown['exemption_reason'] }}</div>@endif
                                </td>
                                <td class="text-right">{{ $formatAmount($breakdown['vat_rate']) }} %</td>
                                <td class="text-right">{{ $formatAmount($breakdown['taxable_amount']) }} €</td>
                                <td class="text-right">{{ $formatAmount($breakdown['vat_amount']) }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Totaux --}}
        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td class="label">Total HT</td>
                    <td class="value">{{ $formatAmount($hasPreparedData ? $pdfData['totals']['subtotal_ht'] : $invoice->subtotal_ht) }} €</td>
                </tr>
                <tr>
                    <td class="label">
                        @if($hasPreparedData)
                            TVA totale
                        @else
                            TVA ({{ $formatAmount($tvaRate) }} %)
                        @endif
                    </td>
                    <td class="value">{{ $formatAmount($hasPreparedData ? $pdfData['totals']['total_tva'] : $invoice->total_tva) }} €</td>
                </tr>
                <tr class="grand-total">
                    <td class="label">Total TTC</td>
                    <td class="value">{{ $formatAmount($hasPreparedData ? $pdfData['totals']['total_ttc'] : $invoice->total_ttc) }} €</td>
                </tr>
            </table>
        </div>

        {{-- Historique des paiements --}}
        @if($pdfPayments->count())
            <div class="notes">
                <strong>Paiements enregistrés :</strong><br><br>

                @foreach($pdfPayments->sortBy('paid_at') as $payment)
                    @php
                        $methodLabel = match ($payment->method) {
                            'virement' => 'Virement',
                            'carte' => 'Carte bancaire',
                            'especes' => 'Espèces',
                            'cheque' => 'Chèque',
                            default => ucfirst($payment->method ?? 'Non renseigné'),
                        };
                    @endphp

                    <div>
                        - {{ \Carbon\Carbon::parse($payment->paid_at)->format('d/m/Y') }} :
                        {{ $formatAmount($payment->amount) }} € ({{ $methodLabel }})
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Notes --}}
        @if(!empty($invoice->notes))
            <div class="notes">
                <strong>Notes :</strong><br>
                {{ $invoice->notes }}
            </div>
        @endif

        {{-- Mentions légales --}}
        <div class="notes">
            <strong>Conditions de paiement :</strong><br>
            {{ $company->payment_terms ?? 'Paiement à réception de facture.' }}<br><br>

            <strong>Date limite de règlement :</strong><br>
            {{ $invoice->due_date ? $invoice->due_date->format('d/m/Y') : 'À réception de facture' }}<br><br>

            <strong>Escompte pour paiement anticipé :</strong><br>
            Néant.<br><br>

            <strong>Pénalités de retard :</strong><br>
            10% du montant TTC.<br><br>

            <strong>Indemnité forfaitaire pour frais de recouvrement :</strong><br>
            40 € dus en cas de retard de paiement.
        </div>

        {{-- Pied de page --}}
        <div class="footer">
            Merci pour votre confiance.

            @if(!empty($company?->company_name) || !empty($company?->name))
                <div style="margin-top: 6px;">
                    Document émis par {{ $companyName }}.
                </div>
            @endif
        </div>
    </div>
</body>
</html>
