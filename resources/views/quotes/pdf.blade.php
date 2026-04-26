<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Devis {{ $quote->quote_number }}</title>

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

        .quote-block {
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

        .approval-box {
            margin-top: 35px;
            border: 1px solid #dcdcdc;
            padding: 16px;
            border-radius: 4px;
        }

        .approval-title {
            font-weight: bold;
            margin-bottom: 10px;
        }

        .signature-space {
            margin-top: 35px;
        }

        .footer {
            margin-top: 35px;
            font-size: 11px;
            color: #666;
            border-top: 1px solid #dcdcdc;
            padding-top: 15px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="document">

        @php
            $companyName = $company->company_name ?? $company->name ?? auth()->user()->name;

            $statusLabel = match ($quote->status) {
                'brouillon' => 'Brouillon',
                'envoye' => 'Envoyé',
                'accepte' => 'Accepté',
                'refuse' => 'Refusé',
                default => ucfirst($quote->status),
            };

            $tvaRate = $quote->items->first()?->tva_rate ?? 0;
            $quoteValidity = $company->quote_validity ?? '30 jours';
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
                    </td>

                    <td class="quote-block">
                        <div class="document-title">DEVIS</div>
                        <div><strong>Numéro :</strong> {{ $quote->quote_number }}</div>
                        <div><strong>Date :</strong> {{ $quote->date->format('d/m/Y') }}</div>
                        <div><strong>Validité :</strong> {{ $quoteValidity }}</div>

                        <div style="margin-top: 8px;">
                            <strong>Statut :</strong> {{ $statusLabel }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Client --}}
        <div class="section">
            <table class="info-table">
                <tr>
                    <td style="padding-right: 10px;">
                        <div class="box-title">Client</div>
                        <div class="box">
                            <div style="font-weight: bold; margin-bottom: 4px;">
                                {{ $quote->client->full_name }}
                            </div>

                            @if(!empty($quote->client->company_name))
                                <div>{{ $quote->client->company_name }}</div>
                            @endif

                            @if(!empty($quote->client->address))
                                <div>{{ $quote->client->address }}</div>
                            @endif

                            @if(!empty($quote->client->postal_code) || !empty($quote->client->city))
                                <div>{{ $quote->client->postal_code ?? '' }} {{ $quote->client->city ?? '' }}</div>
                            @endif

                            @if(!empty($quote->client->email))
                                <div>{{ $quote->client->email }}</div>
                            @endif

                            @if(!empty($quote->client->phone))
                                <div>{{ $quote->client->phone }}</div>
                            @endif
                        </div>
                    </td>

                    <td style="padding-left: 10px;">
                        <div class="box-title">Informations</div>
                        <div class="box">
                            <div><strong>Devise :</strong> EUR</div>
                            <div><strong>Validité du devis :</strong> {{ $quoteValidity }}</div>

                            @if(!empty($company?->payment_terms))
                                <div><strong>Conditions de paiement :</strong> {{ $company->payment_terms }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Tableau des lignes --}}
        <div class="section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Qté</th>
                        <th class="text-right">Prix unitaire HT</th>
                        <th class="text-right">TVA</th>
                        <th class="text-right">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($quote->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="text-right">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                            <td class="text-right">{{ number_format($item->unit_price_ht, 2, ',', ' ') }} €</td>
                            <td class="text-right">{{ number_format($item->tva_rate, 2, ',', ' ') }} %</td>
                            <td class="text-right">{{ number_format($item->line_total_ht, 2, ',', ' ') }} €</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="muted">Aucune ligne de devis.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Totaux --}}
        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td class="label">Total HT</td>
                    <td class="value">{{ number_format($quote->subtotal_ht, 2, ',', ' ') }} €</td>
                </tr>
                <tr>
                    <td class="label">TVA ({{ number_format($tvaRate, 2, ',', ' ') }} %)</td>
                    <td class="value">{{ number_format($quote->total_tva, 2, ',', ' ') }} €</td>
                </tr>
                <tr class="grand-total">
                    <td class="label">Total TTC</td>
                    <td class="value">{{ number_format($quote->total_ttc, 2, ',', ' ') }} €</td>
                </tr>
            </table>
        </div>

        {{-- Notes --}}
        @if(!empty($quote->notes))
            <div class="notes">
                <strong>Notes :</strong><br>
                {{ $quote->notes }}
            </div>
        @endif

        {{-- Mentions devis --}}
        <div class="notes">
            <strong>Validité du devis :</strong><br>
            Ce devis est valable {{ $quoteValidity }} à compter de sa date d’émission.<br><br>

            @if(!empty($company?->payment_terms))
                <strong>Conditions de paiement :</strong><br>
                {{ $company->payment_terms }}<br><br>
            @endif

            <strong>Acceptation du devis :</strong><br>
            La signature de ce devis précédée de la mention « Bon pour accord » vaut acceptation pleine et entière de la prestation et des conditions indiquées.
        </div>

        {{-- Bon pour accord --}}
        <div class="approval-box">
            <div class="approval-title">Bon pour accord</div>
            <div>Nom du client : ____________________________________________</div>
            <div class="signature-space">Date : ____________________</div>
            <div class="signature-space">Signature : ______________________________</div>
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