<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">

    <title>
        FACTURE ÉLECTRONIQUE REÇUE {{ $supplierInvoice->invoice_number }}
    </title>

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
            font-size: 26px;
            font-weight: bold;
            margin-bottom: 8px;
            letter-spacing: 1px;
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
            line-height: 1.6;
        }

        .notice {
            margin-bottom: 25px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            padding: 12px;
            color: #1d4ed8;
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
            font-size: 11px;
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
            width: 140px;
            text-align: right;
        }

        .grand-total td {
            font-size: 15px;
            font-weight: bold;
            border-top: 2px solid #1a1a1a;
            border-bottom: none;
            padding-top: 10px;
        }

        .technical-table {
            width: 100%;
            border-collapse: collapse;
        }

        .technical-table td {
            width: 50%;
            padding: 6px 0;
            vertical-align: top;
        }

        .events {
            margin-top: 25px;
            font-size: 11px;
            color: #444;
            line-height: 1.7;
        }

        .footer {
            margin-top: 35px;
            font-size: 10px;
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
            $companyName =
                $company->company_name
                ?? $company->name
                ?? auth()->user()->name;

            $statusLabel = $supplierInvoice->statusLabel();
        @endphp

        {{-- En-tête --}}
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="company-block">

                        @if(!empty($company?->logo))
                            <img
                                src="{{ public_path('storage/' . $company->logo) }}"
                                alt="Logo"
                                class="logo"
                            >
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
                            <div>
                                {{ $company->postal_code ?? '' }}
                                {{ $company->city ?? '' }}
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

                        @if(!empty($company?->vat_number))
                            <div>
                                TVA intracommunautaire :
                                {{ $company->vat_number }}
                            </div>
                        @endif

                    </td>

                    <td class="invoice-block">

                        <div class="document-title">
                            FACTURE FOURNISSEUR
                        </div>

                        <div>
                            <strong>Numéro :</strong>
                            {{ $supplierInvoice->invoice_number }}
                        </div>

                        <div>
                            <strong>Date :</strong>
                            {{ $supplierInvoice->invoice_date?->format('d/m/Y') }}
                        </div>

                        @if($supplierInvoice->due_date)
                            <div>
                                <strong>Échéance :</strong>
                                {{ $supplierInvoice->due_date->format('d/m/Y') }}
                            </div>
                        @endif

                        <div>
                            <strong>Statut :</strong>
                            {{ $statusLabel }}
                        </div>

                        <div>
                            <strong>Devise :</strong>
                            {{ $supplierInvoice->currency }}
                        </div>

                    </td>
                </tr>
            </table>
        </div>

        {{-- Mention importante --}}
        <div class="notice">
            <strong>Facture électronique reçue via SUPER PDP.</strong><br>

            Ce PDF est une représentation générée par SimpleDevis à partir
            des données structurées reçues. Il ne remplace pas nécessairement
            le document original transmis par le fournisseur.
        </div>

        {{-- Fournisseur + informations de réception --}}
        <div class="section">
            <table class="info-table">
                <tr>
                    <td style="padding-right: 10px;">

                        <div class="box-title">
                            Fournisseur
                        </div>

                        <div class="box">

                            <div style="font-weight: bold; margin-bottom: 4px;">
                                {{ $supplierInvoice->supplier_name }}
                            </div>

                            @if($supplierInvoice->supplier_address)
                                <div>
                                    {{ $supplierInvoice->supplier_address }}
                                </div>
                            @endif

                            @if(
                                $supplierInvoice->supplier_postal_code
                                || $supplierInvoice->supplier_city
                            )
                                <div>
                                    {{ $supplierInvoice->supplier_postal_code }}
                                    {{ $supplierInvoice->supplier_city }}
                                </div>
                            @endif

                            @if($supplierInvoice->supplier_siren)
                                <div>
                                    SIREN :
                                    {{ $supplierInvoice->supplier_siren }}
                                </div>
                            @endif

                            @if($supplierInvoice->supplier_vat_number)
                                <div>
                                    TVA :
                                    {{ $supplierInvoice->supplier_vat_number }}
                                </div>
                            @endif

                            @if($supplierInvoice->supplier_email)
                                <div>
                                    Adresse électronique :
                                    {{ $supplierInvoice->supplier_email }}
                                </div>
                            @endif

                        </div>

                    </td>

                    <td style="padding-left: 10px;">

                        <div class="box-title">
                            Réception électronique
                        </div>

                        <div class="box">

                            <div>
                                <strong>Plateforme :</strong>
                                SUPER PDP
                            </div>

                            <div>
                                <strong>Direction :</strong>
                                {{ $supplierInvoice->direction === 'in'
                                    ? 'Entrante'
                                    : ucfirst($supplierInvoice->direction ?? 'Non renseignée') }}
                            </div>

                            <div>
                                <strong>Type :</strong>
                                {{ $supplierInvoice->type_code ?: 'Facture' }}
                            </div>

                            <div>
                                <strong>Identifiant SUPER PDP :</strong>
                                {{ $supplierInvoice->superpdp_invoice_id ?: 'Non renseigné' }}
                            </div>

                            <div>
                                <strong>Date de réception :</strong>
                                {{ $supplierInvoice->received_at?->format('d/m/Y à H:i')
                                    ?? 'Non renseignée' }}
                            </div>

                        </div>

                    </td>
                </tr>
            </table>
        </div>

        {{-- Lignes de facture --}}
        <div class="section">

            <table class="items-table">

                <thead>
                    <tr>
                        <th>Description</th>

                        <th class="text-right">
                            Qté
                        </th>

                        <th class="text-right">
                            Prix unitaire HT
                        </th>

                        <th class="text-right">
                            TVA
                        </th>

                        <th class="text-right">
                            Total HT
                        </th>

                        <th class="text-right">
                            Total TTC
                        </th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($supplierInvoice->items as $item)

                        <tr>
                            <td>
                                {{ $item->description }}
                            </td>

                            <td class="text-right">
                                {{ number_format($item->quantity, 2, ',', ' ') }}
                            </td>

                            <td class="text-right">
                                {{ number_format($item->unit_price_ht, 2, ',', ' ') }} €
                            </td>

                            <td class="text-right">
                                {{ number_format($item->vat_rate, 2, ',', ' ') }} %
                            </td>

                            <td class="text-right">
                                {{ number_format($item->line_total_ht, 2, ',', ' ') }} €
                            </td>

                            <td class="text-right">
                                {{ number_format($item->line_total_ttc, 2, ',', ' ') }} €
                            </td>
                        </tr>

                    @empty

                        <tr>
                            <td colspan="6" class="muted">
                                Aucune ligne de facture.
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

        {{-- Totaux --}}
        <div class="totals-wrapper">

            <table class="totals-table">

                <tr>
                    <td class="label">
                        Total HT
                    </td>

                    <td class="value">
                        {{ number_format($supplierInvoice->total_ht, 2, ',', ' ') }} €
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        TVA
                    </td>

                    <td class="value">
                        {{ number_format($supplierInvoice->total_vat, 2, ',', ' ') }} €
                    </td>
                </tr>

                <tr class="grand-total">
                    <td class="label">
                        Total TTC
                    </td>

                    <td class="value">
                        {{ number_format($supplierInvoice->total_ttc, 2, ',', ' ') }} €
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Reste à payer
                    </td>

                    <td class="value">
                        {{ number_format(
                            $supplierInvoice->amount_due
                                ?? $supplierInvoice->total_ttc,
                            2,
                            ',',
                            ' '
                        ) }} €
                    </td>
                </tr>

            </table>

        </div>

        {{-- Historique SUPER PDP --}}
        @if($supplierInvoice->events->count())

            <div class="events">

                <strong>
                    Historique SUPER PDP :
                </strong>

                <br><br>

                @foreach($supplierInvoice->events as $event)

                    @php
                        $eventLabel = match ($event->status) {
                            'fr:202' => 'Facture reçue',
                            'fr:203' => 'En traitement',
                            'fr:204' => 'Facture acceptée',
                            'fr:205' => 'Facture rejetée',
                            default => $event->event_type
                                ?: 'Événement SUPER PDP',
                        };
                    @endphp

                    <div>
                        - {{ $event->event_date?->format('d/m/Y à H:i')
                            ?? 'Date inconnue' }}
                        :
                        {{ $eventLabel }}
                    </div>

                @endforeach

            </div>

        @endif

        {{-- Pied de page --}}
        <div class="footer">

            Document généré par SimpleDevis.

            <div style="margin-top: 6px;">
                Données reçues via SUPER PDP.
            </div>

            <div style="margin-top: 6px;">
                Généré le {{ now()->format('d/m/Y à H:i') }}.
            </div>

        </div>

    </div>
</body>
</html>