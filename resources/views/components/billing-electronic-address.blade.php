@props(['address' => null, 'bt'])
<fieldset class="mt-6 space-y-2">
    <legend class="text-sm font-semibold text-gray-700">Adresse électronique de facturation {{ $bt }}</legend>
    <p class="text-sm text-gray-600">Distincte de l’email de contact. Renseignez une adresse déclarée par son titulaire. Cette saisie ne vérifie ni l’annuaire ni le routage.</p>
    <label for="billing_address_scheme" class="block text-sm">Schéma de l’adresse</label>
    <select id="billing_address_scheme" name="billing_electronic_address[scheme]" class="w-full rounded-lg border-gray-300">
        <option value="">Non renseigné</option>
        <option value="0225" @selected(old('billing_electronic_address.scheme', $address['scheme'] ?? '') === '0225')>0225 — SIREN, éventuellement avec suffixe</option>
        <option value="EM" @selected(old('billing_electronic_address.scheme', $address['scheme'] ?? '') === 'EM')>EM — adresse email de facturation explicitement déclarée</option>
    </select>
    <label for="billing_address_value" class="block text-sm">Valeur de l’adresse</label>
    <input id="billing_address_value" name="billing_electronic_address[value]" type="text" maxlength="254"
           value="{{ old('billing_electronic_address.value', $address['value'] ?? '') }}" class="w-full rounded-lg border-gray-300">
    @error('billing_electronic_address') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    @error('billing_electronic_address.scheme') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    @error('billing_electronic_address.value') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
</fieldset>
