<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Votre facture {{ $invoice->invoice_number }}</title>
</head>
<body style="font-family: Arial, sans-serif; color:#333; line-height:1.6;">

    <p>Bonjour {{ $invoice->client->full_name }},</p>

    <p>
        Nous vous remercions pour votre confiance.
    </p>

    <p>
        Vous trouverez en pièce jointe votre facture :
    </p>

    <p>
        <strong>Numéro :</strong> {{ $invoice->invoice_number }}<br>
        <strong>Date :</strong> {{ $invoice->date->format('d/m/Y') }}<br>
        <strong>Montant TTC :</strong> {{ number_format($invoice->total_ttc, 2) }} €
    </p>

    <p>
        @if(($invoice->total_ttc - ($invoice->amount_paid ?? 0)) > 0)
            Le montant restant dû est de 
            <strong>
                {{ number_format($invoice->total_ttc - ($invoice->amount_paid ?? 0), 2) }} €
            </strong>.
        @else
            Cette facture est entièrement réglée.
        @endif
    </p>

    <p>
        N’hésitez pas à nous contacter si vous avez la moindre question.
    </p>

    <br>

    <p>Cordialement,</p>

    <p>
        <strong>{{ $company->name ?? $invoice->user->name }}</strong><br>

        @if(!empty($company?->email))
            {{ $company->email }}<br>
        @endif

        @if(!empty($company?->phone))
            {{ $company->phone }}
        @endif
    </p>

</body>
</html>