const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/invoice-finalization-summary.js'), 'utf8');

function load(profileValue, invoiceId = '', hasSandbox = false) {
    const events = {};
    const requests = [];
    const timers = [];
    const option = { disabled: false };
    const sandboxOption = hasSandbox ? { value: 'sandbox_superpdp_bq_tricatel_v1' } : null;
    const profile = { value: profileValue, validationMessage: '', querySelector: selector => selector === '[data-sandbox-profile]' ? sandboxOption : option,
        setCustomValidity(message) { this.validationMessage = message; } };
    const content = { textContent: '', innerHTML: '', sections: [], querySelectorAll() { return this.sections; },
        querySelector() { const match = this.innerHTML.match(/data-profile-compatible="(true|false)"/); return match ? { dataset: { profileCompatible: match[1] } } : null; } };
    const refresh = { addEventListener: (name, fn) => { events.refresh = fn; } };
    const form = {
        querySelector: () => profile,
        addEventListener: (name, fn) => { events[name] = fn; },
    };
    const panel = {
        hidden: true, dataset: { url: '/invoices/electronic-summary', invoiceId },
        closest: () => form,
        querySelector: (selector) => selector === '[data-summary-content]' ? content : refresh,
    };
    class FakeFormData extends Map {
        constructor(source) {
            super([['_method', 'PUT'], ['_token', 'test-csrf'], ['client_id', '12'], ['emission_profile', profile.value]]);
            assert.equal(source, form);
        }
    }
    vm.runInNewContext(script, {
        document: { addEventListener: (name, fn) => fn(), querySelectorAll: () => [panel] },
        window: { addEventListener: (name, fn) => { events.focus = fn; } },
        FormData: FakeFormData, AbortController,
        setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout: () => {},
        fetch: (url, options) => new Promise((resolve, reject) => { requests.push({ url, options, resolve, reject }); }),
    });
    return { profile, option, panel, content, events, requests, timers };
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('the sandbox refresh posts the selected profile and renders updated totals before finalization', async () => {
    const page = load('sandbox_superpdp_bq_tricatel_v1', '', true);
    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].options.body.get('emission_profile'), page.profile.value);
    page.requests[0].resolve({ ok: true, redirected: false, text: async () => '<section>Sandbox SUPER PDP — 100.00 HT — 20.00 TVA — 120.00 TTC</section>' });
    await flush();
    assert.match(page.content.innerHTML, /120.00 TTC/);
    page.events.refresh();
    assert.equal(page.requests.length, 2);
    assert.match(page.content.textContent, /Actualisation/);
    page.requests[1].resolve({ ok: true, redirected: false, text: async () => '<section>Sandbox SUPER PDP — 240.00 TTC</section>' });
    await flush();
    assert.match(page.content.innerHTML, /240.00 TTC/);
    assert.equal(page.profile.validationMessage, '');
    page.events.input();
    page.timers.at(-1)();
    assert.equal(page.requests.length, 3);
    page.profile.value = 'fr_b2b_380_eur';
    page.events.change();
    page.timers.at(-1)();
    assert.equal(page.requests.length, 4);
});

test('sandbox refresh displays an explicit HTTP error and can be retried', async () => {
    const page = load('sandbox_superpdp_bq_tricatel_v1', '', true);
    for (const status of [422, 403, 419, 500]) {
        page.requests.at(-1).resolve({ ok: false, status, redirected: false,
            json: async () => ({ errors: { due_date: ['La date d’échéance est invalide.'] } }) });
        await flush();
        assert.match(page.content.textContent, new RegExp(`Récapitulatif indisponible. Erreur HTTP ${status}`));
        if (status === 422) assert.match(page.content.textContent, /La date d’échéance est invalide/);
        assert.equal(page.profile.validationMessage, '');
        page.events.refresh();
    }
    page.requests.at(-1).reject(new TypeError('Failed to fetch'));
    await flush();
    assert.match(page.content.textContent, /Récapitulatif indisponible.*Failed to fetch/);
});

test('shows electronic prerequisites before selection while keeping the classical profile available', async () => {
    const page = load('');
    assert.equal(page.panel.hidden, false);
    assert.equal(page.requests.length, 1);
    assert.equal(page.option.disabled, true);
    assert.equal(page.profile.validationMessage, '');
    page.requests[0].resolve({ ok: true, redirected: false, text: async () => '<section data-profile-compatible="true">Summary</section>' });
    await flush();
    assert.equal(page.option.disabled, false);
    page.profile.value = 'fr_b2b_380_eur';
    page.events.change();
    assert.equal(page.panel.hidden, false);
    page.timers.at(-1)();
    assert.equal(page.requests.length, 2);
    page.requests[1].resolve({ ok: true, redirected: false, text: async () => '<section>Summary</section>' });
    await flush();
    assert.equal(page.content.innerHTML, '<section>Summary</section>');
});

test('preview posts to the read-only endpoint without the update method override', async () => {
    const page = load('fr_b2b_380_eur', '42');
    const request = page.requests[0];
    assert.equal(request.url, '/invoices/electronic-summary');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body.has('_method'), false);
    assert.equal(request.options.body.get('_token'), 'test-csrf');
    assert.equal(request.options.body.get('invoice_id'), '42');
    request.resolve({ ok: true, redirected: false, text: async () => '<section>Summary</section>' });
    await flush();
    assert.equal(page.content.innerHTML, '<section>Summary</section>');
});

test('changing profile cancels stale responses and preserves the overview for the classical choice', async () => {
    const page = load('fr_b2b_380_eur');
    page.profile.value = '';
    page.events.change();
    assert.equal(page.panel.hidden, false);
    assert.equal(page.profile.validationMessage, '');
    assert.equal(page.requests[0].options.signal.aborted, true);
    page.requests[0].resolve({ ok: true, redirected: false, text: async () => '<section>Old data</section>' });
    await flush();
    assert.equal(page.content.innerHTML, '');
});

test('failed overview is visibly unavailable and never reports eligibility', async () => {
    const page = load('fr_b2b_380_eur');
    page.requests[0].resolve({ ok: false, redirected: false });
    await flush();
    assert.match(page.content.textContent, /Récapitulatif indisponible/);
    assert.match(page.content.textContent, /les contrôles serveur restent appliqués/);
});

test('adding or removing invoice lines schedules a fresh summary', () => {
    const page = load('fr_b2b_380_eur');
    page.events.click({ target: { closest: selector => selector.includes('.remove-invoice-line') } });
    assert.equal(page.requests[0].options.signal.aborted, true);
    assert.equal(page.timers.length, 1);
    assert.match(page.content.textContent, /Actualisation/);
});

test('refresh preserves expanded sections and leaves other sections collapsed', async () => {
    const page = load('fr_b2b_380_eur');
    page.requests[0].resolve({ ok: true, redirected: false, text: async () => '<section>Summary</section>' });
    await flush();
    page.content.sections = [
        { dataset: { summaryGroup: 'Entreprise' }, open: true },
        { dataset: { summaryGroup: 'Client' }, open: false },
    ];
    page.events.refresh();
    page.content.sections = [
        { dataset: { summaryGroup: 'Entreprise' }, open: false },
        { dataset: { summaryGroup: 'Client' }, open: false },
    ];
    page.requests[1].resolve({ ok: true, redirected: false, text: async () => '<section>Updated summary</section>' });
    await flush();
    assert.equal(page.content.sections[0].open, true);
    assert.equal(page.content.sections[1].open, false);
    page.content.sections[0].open = false;
    page.events.refresh();
    page.requests[2].resolve({ ok: true, redirected: false, text: async () => '<section>Updated again</section>' });
    await flush();
    assert.equal(page.content.sections[0].open, false);
});

test('incompatible selection is never silently converted to a classical invoice', async () => {
    const page = load('fr_b2b_380_eur');
    page.requests[0].resolve({ ok: true, redirected: false, text: async () => '<section data-profile-compatible="false">Incompatible</section>' });
    await flush();
    assert.equal(page.profile.value, 'fr_b2b_380_eur');
    assert.match(page.profile.validationMessage, /incompatible/);
    // Keep the selected value serializable; native validation and server guards reject it.
    assert.equal(page.option.disabled, false);
    page.profile.value = '';
    page.events.change();
    assert.equal(page.profile.validationMessage, '');
    assert.equal(page.option.disabled, true);
});

test('a stale failed response cannot invalidate a more recent compatible summary', async () => {
    const page = load('fr_b2b_380_eur');
    page.events.change();
    page.timers.at(-1)();
    page.requests[1].resolve({ ok: true, redirected: false, text: async () => '<section data-profile-compatible="true">Current</section>' });
    await flush();
    page.requests[0].resolve({ ok: false, redirected: false });
    await flush();
    assert.equal(page.profile.validationMessage, '');
    assert.match(page.content.innerHTML, /Current/);
});
