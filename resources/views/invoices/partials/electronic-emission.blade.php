<section id="electronic-emission" class="rounded-3xl border bg-white p-6 shadow-lg" aria-labelledby="electronic-emission-title">
    <h3 id="electronic-emission-title" class="text-lg font-semibold text-gray-900">Envoi électronique</h3>
    @if(session('emission_request_messages'))
        <ul role="status" class="mt-3 list-disc space-y-2 pl-5 text-sm text-amber-900">
            @foreach(session('emission_request_messages') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
    <div class="mt-4 rounded-xl bg-slate-50 p-4" aria-label="Statut du registre d’émission">
        <p class="font-semibold">{{ $emissionReadiness['status']['label'] }}</p>
        @if($emissionReadiness['status']['date'])
            <p class="mt-2 text-sm">Dernier état enregistré : {{ $emissionReadiness['status']['date'] }} (heure de Paris).</p>
        @endif
        @if($emissionReadiness['status']['simulation'])
            <p class="mt-2 text-sm font-semibold">Simulation uniquement ; aucune preuve de dépôt réel.</p>
        @endif
        @foreach($emissionReadiness['status']['messages'] as $message)
            <p class="mt-2 text-sm">{{ $message }}</p>
        @endforeach
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach($emissionReadiness['groups'] as $key => $group)
            <div class="rounded-xl border p-4" data-emission-prerequisite="{{ $key }}">
                <h4 class="font-semibold">{{ $group['label'] }}</h4>
                <p class="mt-2 text-sm">{{ $group['passed'] ? 'Contrôles locaux réunis.' : 'Envoi bloqué.' }}</p>
                @foreach($group['messages'] as $message)
                    <p class="mt-2 text-sm text-amber-900">{{ $message }}</p>
                @endforeach
            </div>
        @endforeach
    </div>
    @php($confirmation = session('emission_confirmation.'.$invoice->id))
    @if($confirmation && $emissionReadiness['status']['state'] === 'pending')
        <p class="mt-4 text-sm">Tentative : {{ $confirmation['uuid'] }}. Confirmation valable jusqu’à {{ gmdate('H:i:s', $confirmation['expires_at']) }} UTC.</p>
        <form method="POST" action="{{ route('invoices.request-electronic-emission', $invoice) }}" class="mt-4 space-y-3">
            @csrf
            <input type="hidden" name="step" value="confirm">
            <input type="hidden" name="attempt_reference" value="{{ $confirmation['uuid'] }}">
            <input type="hidden" name="confirmation_token" value="{{ $confirmation['token'] }}">
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm_emission" value="1" required>
                <span>Je confirme explicitement l’envoi de cette facture pour cette tentative.</span>
            </label>
            <button type="submit" @disabled(now()->timestamp >= $confirmation['expires_at']) class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white">Confirmer l’envoi électronique</button>
        </form>
    @elseif($emissionReadiness['can_send'])
        <form method="POST" action="{{ route('invoices.request-electronic-emission', $invoice) }}" class="mt-4">
            @csrf
            <input type="hidden" name="step" value="reserve">
            <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white">Envoyer électroniquement</button>
        </form>
    @else
        <p id="electronic-emission-closed" class="mt-4 text-sm font-semibold text-amber-900">L’envoi est bloqué. Consultez le statut et les raisons détaillées ci-dessus avant de poursuivre la préparation électronique.</p>
        <a href="{{ route('invoices.electronic-preparation', $invoice) }}" class="mt-4 inline-block rounded-xl bg-indigo-50 px-5 py-3 font-semibold text-indigo-700">Préparer l’envoi électronique</a>
    @endif
    @if($emissionReadiness['can_review'] ?? false)
        <form method="POST" action="{{ route('invoices.request-electronic-emission', $invoice) }}" class="mt-4">
            @csrf
            <input type="hidden" name="step" value="review">
            <button type="submit" class="rounded-xl border px-5 py-3 font-semibold">Examiner une interruption localement</button>
        </form>
    @endif
    <p class="mt-4 text-sm text-gray-600">La préparation CII, sa validation et le dépôt sont des étapes distinctes. Cette consultation ne synchronise aucun statut distant et ne renouvelle aucun jeton.</p>
</section>
