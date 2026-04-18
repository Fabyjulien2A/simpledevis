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
            font-size: 26px;
            font-weight: bold;
            margin-bottom: 8px;
            letter-spacing: 1px;
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
            width: 320px;
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
            width: 120px;
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
        }

        .signature {
            margin-top: 50px;
            width: 100%;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }

        .signature-table td {
            width: 50%;
            vertical-align: top;
            padding-top: 20px;
        }

        .signature-box {
            border-top: 1px solid #999;
            padding-top: 8px;
            width: 220px;
            color: #555;
            font-size: 11px;
        }

        .footer {
            margin-top: 40px;
            font-size: 11px;
            color: #666;
            border-top: 1px solid #dcdcdc;
            padding-top: 15px;
        }
    </style>
</head>
<body>
    <div class="document">

        {{-- En-tête --}}
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="company-block">
                        @if(!empty($company?->logo))
                            <img src="{{ public_path('storage/' . $company->logo) }}" alt="Logo" class="logo">
                        @endif

                        <div style="font-size: 16px; font-weight: bold; margin-bottom: 6px;">
                            {{ $company->company_name ?? $company->name ?? auth()->user()->name }}
                        </div>

                        @if(!empty($company?->address))
                            <div>{{ $company->address }}</div>
                        @endif

                        @if(!empty($company?->postal_code) || !empty($company?->city))
                            <div>
                                {{ $company->postal_code ?? '' }} {{ $company->city ?? '' }}
                            </div>
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

                        @if(!empty($company?->tva_number))
                            <div>TVA : {{ $company->tva_number }}</div>
                        @endif
                    </td>

                    <td class="quote-block">
                        <div class="document-title">DEVIS</div>
                        <div><strong>Numéro :</strong> {{ $quote->quote_number }}</div>
                        <div><strong>Date :</strong> {{ $quote->date->format('d/m/Y') }}</div>
                        <div><strong>Validité :</strong> 30 jours</div>

                        <div style="margin-top: 8px;">
                            <strong>Statut :</strong>
                            @php
                                $statusLabel = match ($quote->status) {
                                    'brouillon' => 'Brouillon',
                                    'envoye' => 'Envoyé',
                                    'accepte' => 'Accepté',
                                    'refuse' => 'Refusé',
                                    default => ucfirst($quote->status),
                                };
                            @endphp
                            {{ $statusLabel }}
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
                        <div class="box-title">Émetteur</div>
                        <div class="box">
                            <div style="font-weight: bold; margin-bottom: 4px;">
                                {{ $company->company_name ?? $company->name ?? auth()->user()->name }}
                            </div>

                            @if(!empty($company?->address))
                                <div>{{ $company->address }}</div>
                            @endif

                            @if(!empty($company?->postal_code) || !empty($company?->city))
                                <div>
                                    {{ $company->postal_code ?? '' }} {{ $company->city ?? '' }}
                                </div>
                            @endif

                            @if(!empty($company?->email))
                                <div>{{ $company->email }}</div>
                            @endif

                            @if(!empty($company?->phone))
                                <div>{{ $company->phone }}</div>
                            @endif
                        </div>
                    </td>

                    <td style="padding-left: 10px;">
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
                                <div>
                                    {{ $quote->client->postal_code ?? '' }} {{ $quote->client->city ?? '' }}
                                </div>
                            @endif

                            @if(!empty($quote->client->email))
                                <div>{{ $quote->client->email }}</div>
                            @endif

                            @if(!empty($quote->client->phone))
                                <div>{{ $quote->client->phone }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Tableau --}}
        <div class="section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Qté</th>
                        <th class="text-right">Prix unitaire HT</th>
                        <th class="text-right">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($quote->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
                            <td class="text-right">{{ number_format($item->unit_price_ht, 2) }} €</td>
                            <td class="text-right">{{ number_format($item->line_total_ht, 2) }} €</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">Aucune ligne de devis.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Totaux --}}
        <div class="totals-wrapper">
            @php
                $tvaRate = $quote->items->first()?->tva_rate ?? 0;
            @endphp

            <table class="totals-table">
                <tr>
                    <td class="label">Total HT</td>
                    <td class="value">{{ number_format($quote->subtotal_ht, 2) }} €</td>
                </tr>
                <tr>
                    <td class="label">TVA ({{ number_format($tvaRate, 0) }} %)</td>
                    <td class="value">{{ number_format($quote->total_tva, 2) }} €</td>
                </tr>
                <tr class="grand-total">
                    <td class="label">Total TTC</td>
                    <td class="value">{{ number_format($quote->total_ttc, 2) }} €</td>
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

        {{-- Signature --}}
        <div class="signature">
            <table class="signature-table">
                <tr>
                    <td>
                        <div class="signature-box">
                            Signature de l’émetteur
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <div class="signature-box" style="margin-left: auto;">
                            Bon pour accord, signature du client
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Footer --}}
        <div class="footer">
            Merci pour votre confiance.
        </div>
    </div>
</body>
</html>