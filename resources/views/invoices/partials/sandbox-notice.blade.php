@if(\App\Services\SuperPdp\SandboxInvoiceProfile::is($invoice))
    <p role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 font-bold text-amber-900">Sandbox SUPER PDP — Facture de test — sans valeur fiscale</p>
@endif
