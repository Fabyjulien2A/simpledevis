<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Devis</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 13px;
            color: #111;
            margin: 30px;
        }

        .container {
            width: 100%;
        }

        .header {
            margin-bottom: 30px;
        }

        .logo {
            margin-bottom: 20px;
        }

        .logo img {
            max-height: 80px;
        }

        .title {
            font-size: 26px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .subtitle {
            color: #555;
            font-size: 13px;
        }

        .top-section {
            width: 100%;
            margin-bottom: 30px;
        }

        .left,
        .right {
            width: 48%;
            display: inline-block;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .box {
            line-height: 1.7;
        }

        .section-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
        }

        th {
            background: #f5f5f5;
            text-align: left;
            padding: 10px 8px;
            border: 1px solid #ccc;
        }

        td {
            padding: 10px 8px;
            border: 1px solid #ccc;
        }

        .totals {
            margin-top: 30px;
            width: 320px;
            margin-left: auto;
        }

        .totals table {
            width: 100%;
            margin-top: 0;
        }

        .totals td {
            border: none;
            padding: 6px 0;
        }

        .totals-label {
            text-align: left;
        }

        .totals-value {
            text-align: right;
        }

        .total-final td {
            font-size: 17px;
            font-weight: bold;
            padding-top: 10px;
            border-top: 1px solid #999;
        }

        .footer {
            margin-top: 60px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="container">

    {{-- HEADER --}}
    <div class="header">

        @if(!empty($company?->logo))
            <div class="logo">
                <img src="{{ public_path('storage/' . $company->logo) }}" alt="Logo entreprise">
            </div>
        @endif

        <div class="title">Devis {{ $quote->quote_number }}</div>
        <div class="subtitle">Date : {{ $quote->date->format('d/m/Y') }}</div>
    </div>

    {{-- ENTREPRISE + CLIENT --}}
    <div class="top-section">
        <div class="left box">
            <div class="section-title">Émetteur</div>

            <strong>{{ $company->company_name ?? 'Nom de l’entreprise' }}</strong><br>

            @if(!empty($company?->address))
                {{ $company->address }}<br>
            @endif

            @if(!empty($company?->phone))
                Tél : {{ $company->phone }}<br>
            @endif

            @if(!empty($company?->email))
                Email : {{ $company->email }}<br>
            @endif

            @if(!empty($company?->siret))
                SIRET : {{ $company->siret }}<br>
            @endif

            @if(!empty($company?->tva_number))
                TVA : {{ $company->tva_number }}
            @endif
        </div>

        <div class="right box">
            <div class="section-title">Client</div>
            {{ $quote->client->full_name }}
        </div>
    </div>

    {{-- TABLEAU --}}
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="width: 80px;">Qté</th>
                <th style="width: 120px;">Prix unitaire</th>
                <th style="width: 120px;">Total HT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quote->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ number_format($item->quantity, 2) }}</td>
                    <td>{{ number_format($item->unit_price_ht, 2) }} €</td>
                    <td>{{ number_format($item->line_total_ht, 2) }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- TOTAUX --}}
    @php
        $tvaRate = $quote->items->first()?->tva_rate ?? 0;
    @endphp

    <div class="totals">
        <table>
            <tr>
                <td class="totals-label">Total HT :</td>
                <td class="totals-value">{{ number_format($quote->subtotal_ht, 2) }} €</td>
            </tr>
            <tr>
                <td class="totals-label">TVA ({{ number_format($tvaRate, 0) }}%) :</td>
                <td class="totals-value">{{ number_format($quote->total_tva, 2) }} €</td>
            </tr>
            <tr class="total-final">
                <td class="totals-label">Total TTC :</td>
                <td class="totals-value">{{ number_format($quote->total_ttc, 2) }} €</td>
            </tr>
        </table>
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        Merci pour votre confiance.
    </div>

</div>

</body>
</html>