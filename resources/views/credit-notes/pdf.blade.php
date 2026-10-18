
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Avoir {{ $creditNote->credit_note_number }}</title>

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
    </style>
</head>
<body>
    <div class="document">

        @php
            $invoice = $creditNote->invoice;

            $companyName = $company->company_name
                ?? $company->name
                ?? auth()->user()->name;

            $tvaRates = $invoice->items
                ->pluck('tva_rate')
                ->unique()
                ->values();

            $serviceDate = $invoice->date;
        @endphp

        {{-- En-tête de l'avoir --}}
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
                        <div class="document-title">AVOIR</div>

                        <div>
                            <strong>Numéro :</strong>
                            {{ $creditNote->credit_note_number }}
                        </div>

                        <div>
                            <strong>Date d'émission :</strong>
                            {{ $creditNote->date->format('d/m/Y') }}
                        </div>

                        
                        <div>
                            <strong>Facture d'origine :</strong>
                            {{ $invoice->invoice_number }}
                        </div>

                        <div>
                            <strong>Date de la facture :</strong>
                            {{ $invoice->date->format('d/m/Y') }}
                        </div>

                        <div>
                            <strong>Type :</strong> Avoir total
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Motif de l'avoir --}}
        <div class="section">
            <div class="box-title">Motif de l'avoir</div>
            <div class="box">
                {{ $creditNote->reason }}
            </div>
        </div>

        {{-- Coordonnées du client --}}
        <div class="section">
            <div class="box-title">Client concerné</div>
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
                    <div>
                        {{ $invoice->client->postal_code ?? '' }}
                        {{ $invoice->client->city ?? '' }}
                    </div>
                @endif


                
                @if(!empty($invoice->client->email))
                    <div>{{ $invoice->client->email }}</div>
                @endif

                @if(!empty($invoice->client->phone))
                    <div>{{ $invoice->client->phone }}</div>
                @endif
            </div>
        </div>

        {{-- Prestations annulées --}}
        <div class="section">
            <div class="box-title">Détail des prestations annulées</div>

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
                    @forelse($invoice->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>

                            <td class="text-right">
                                {{ number_format($item->quantity, 2, ',', ' ') }}
                            </td>

                            <td class="text-right">
                                {{ number_format($item->unit_price_ht, 2, ',', ' ') }} €
                            </td>

                            <td class="text-right">
                                {{ number_format($item->tva_rate, 2, ',', ' ') }} %
                            </td>

                            <td class="text-right">
                                -{{ number_format(abs($item->line_total_ht), 2, ',', ' ') }} €
                            </td>
                        </tr>

                        
                    @empty
                        <tr>
                            <td colspan="5" class="muted">
                                Aucune prestation à afficher.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Totaux de l'avoir --}}
        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td class="label">Total HT annulé</td>
                    <td class="value">
                        -{{ number_format(abs($creditNote->subtotal_ht), 2, ',', ' ') }} €
                    </td>
                </tr>

                <tr>
                    <td class="label">TVA annulée</td>
                    <td class="value">
                        -{{ number_format(abs($creditNote->total_tva), 2, ',', ' ') }} €
                    </td>
                </tr>

                <tr class="grand-total">
                    <td class="label">Total TTC de l'avoir</td>
                    <td class="value">
                        -{{ number_format(abs($creditNote->total_ttc), 2, ',', ' ') }} €
                    </td>
                </tr>
            </table>
        </div>

        {{-- Référence à la facture initiale --}}
        <div class="notes">
            Cet avoir annule intégralement la facture
            <strong>{{ $invoice->invoice_number }}</strong>
            émise le {{ $invoice->date->format('d/m/Y') }}.
        </div>

        
        {{-- Notes éventuelles de l'avoir --}}
        @if(!empty($creditNote->notes))
            <div class="notes">
                <strong>Notes :</strong>
                <div>{{ $creditNote->notes }}</div>
            </div>
        @endif

        {{-- Informations complémentaires --}}
        <div class="notes">
            <strong>Nature du document :</strong>
            Avoir total sur facture.

            <div style="margin-top: 8px;">
                Ce document constitue une rectification
                de la facture d'origine.
            </div>

            <div style="margin-top: 8px;">
                <strong>Devise :</strong> EUR
            </div>
        </div>

        {{-- Pied de page --}}
        <div class="footer">
            <div>
                Avoir émis par {{ $companyName }}.
            </div>

            <div style="margin-top: 6px;">
                Référence :
                {{ $creditNote->credit_note_number }}
            </div>

            <div style="margin-top: 6px;">
                Facture concernée :
                {{ $invoice->invoice_number }}
            </div>
        </div>

    </div>
</body>
</html>

