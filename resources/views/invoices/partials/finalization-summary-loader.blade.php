<div data-electronic-summary data-url="{{ route('invoices.electronic-summary') }}"
     data-invoice-id="{{ $invoice->id ?? '' }}" hidden>
    <div data-summary-content role="status" aria-live="polite"></div>
    <button type="button" data-summary-refresh class="mt-3 text-sm text-indigo-700 underline">Actualiser le récapitulatif</button>
    <noscript>Activez JavaScript pour consulter l’aperçu avant finalisation. Les contrôles serveur restent appliqués.</noscript>
</div>
<script src="{{ asset('js/invoice-finalization-summary.js') }}" defer></script>
