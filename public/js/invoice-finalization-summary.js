document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-electronic-summary]').forEach((panel) => {
        const form = panel.closest('form');
        const profile = form?.querySelector('[name="emission_profile"]');
        if (!profile) return;
        const content = panel.querySelector('[data-summary-content]');
        const electronicOption = profile.querySelector('option[value="fr_b2b_380_eur"]');
        const sandboxOption = profile.querySelector('[data-sandbox-profile]');
        const availability = (compatible, message) => {
            if (sandboxOption && profile.value === sandboxOption.value) {
                if (electronicOption) electronicOption.disabled = false;
                profile.setCustomValidity('');
                return;
            }
            // Preserve an existing selection until the user explicitly changes it:
            // never silently turn an electronic invoice into an ordinary PDF.
            if (electronicOption) electronicOption.disabled = !compatible && profile.value !== 'fr_b2b_380_eur';
            profile.setCustomValidity(profile.value === 'fr_b2b_380_eur' && !compatible ? message : '');
        };
        let timer;
        let pending;
        let openGroups = new Set();
        const rememberOpenGroups = () => {
            const sections = content.querySelectorAll('details[data-summary-group]');
            if (sections.length) {
                openGroups = new Set(Array.from(sections).filter(section => section.open).map(section => section.dataset.summaryGroup));
            }
        };
        const refresh = async () => {
            clearTimeout(timer);
            rememberOpenGroups();
            pending?.abort();
            panel.hidden = false;
            availability(false, 'Vérification du profil électronique en cours.');
            const request = new AbortController();
            pending = request;
            content.textContent = 'Actualisation du récapitulatif…';
            const data = new FormData(form);
            data.delete('_method');
            if (panel.dataset.invoiceId) data.set('invoice_id', panel.dataset.invoiceId);
            try {
                const response = await fetch(panel.dataset.url, {
                    method: 'POST', body: data, signal: request.signal,
                    credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok || response.redirected) {
                    if (response.status === 422) {
                        const validation = await response.json();
                        throw new Error(`Erreur HTTP 422. ${Object.values(validation.errors ?? {}).flat().join(' ')}`);
                    }
                    throw new Error(response.redirected ? 'Session expirée ou accès redirigé.' : `Erreur HTTP ${response.status}.`);
                }
                const html = await response.text();
                if (!request.signal.aborted) {
                    content.innerHTML = html;
                    const compatible = content.querySelector('[data-profile-compatible]')?.dataset.profileCompatible === 'true';
                    availability(compatible, 'Ce profil électronique est incompatible. Corrigez les informations ou choisissez Sans qualification électronique pour une facture PDF classique.');
                    content.querySelectorAll('details[data-summary-group]').forEach(section => {
                        section.open = openGroups.has(section.dataset.summaryGroup);
                    });
                }
            } catch (error) {
                if (!request.signal.aborted && error.name !== 'AbortError') {
                    content.textContent = `Récapitulatif indisponible. ${error.message} Vérifiez les informations saisies puis actualisez. Cet aperçu ne valide pas la facture ; les contrôles serveur restent appliqués à la finalisation.`;
                    availability(false, 'Le profil électronique ne peut pas être vérifié. Actualisez ou choisissez Sans qualification électronique pour une facture PDF classique.');
                }
            }
        };
        const schedule = () => {
            rememberOpenGroups();
            pending?.abort();
            clearTimeout(timer);
            panel.hidden = false;
            availability(false, 'Vérification du profil électronique en cours.');
            content.textContent = 'Actualisation du récapitulatif…';
            timer = setTimeout(refresh, 400);
        };
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('click', (event) => {
            if (event.target.closest('#add-invoice-line, .remove-invoice-line')) schedule();
        });
        panel.querySelector('[data-summary-refresh]').addEventListener('click', refresh);
        window.addEventListener('focus', schedule);
        refresh();
    });
});
