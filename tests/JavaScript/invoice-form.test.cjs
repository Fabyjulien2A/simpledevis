const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');

// Exercise the actual inline script against a minimal DOM test double.
const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/invoices/_form.blade.php'), 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1];

function loadForm(creation, initial = {}) {
    const lines = [];
    function control(value = '') {
        return {
            value, name: '', textContent: '', hidden: false, readOnly: false, events: {},
            addEventListener(name, callback) { this.events[name] = callback; },
        };
    }
    function makeLine(source) {
        const names = ['description', 'quantity', 'price', 'tva'];
        if (creation) names.push('unit', 'treatment', 'exemption-code', 'exemption-reason', 'exemption-fields');
        const controls = Object.fromEntries(names.map(name => [
            '.invoice-' + name,
            control(source?.['.invoice-' + name]?.value ?? ({ quantity: '1', tva: '20' }[name] ?? '')),
        ]));
        controls['.line-total'] = control();
        controls['.remove-invoice-line'] = control();
        return {
            controls,
            querySelector(selector) { assert.ok(controls[selector], selector); return controls[selector]; },
            querySelectorAll(selector) {
                if (selector === '.invoice-field-error') return [];
                return Object.entries(controls)
                    .filter(([key]) => !['.line-total', '.remove-invoice-line', '.invoice-exemption-fields'].includes(key))
                    .map(([, value]) => value);
            },
            cloneNode() { return makeLine(controls); },
            remove() { lines.splice(lines.indexOf(this), 1); },
        };
    }
    lines.push(makeLine());
    for (const [name, value] of Object.entries(initial)) {
        lines[0].controls['.invoice-' + name].value = value;
    }
    const add = control();
    const totals = Object.fromEntries(['total-ht', 'total-tva', 'total-ttc'].map(key => [key, control()]));
    const container = {
        querySelectorAll() { return lines; },
        querySelector() { return lines[0]; },
        appendChild(line) { lines.push(line); },
    };
    const document = {
        addEventListener(name, callback) { callback(); },
        getElementById(id) {
            return id === 'invoice-lines' ? container : id === 'add-invoice-line' ? add : totals[id];
        },
    };
    vm.runInNewContext(script.replace('@json($usesDecimalFields)', String(creation)), { document, Intl });
    return { lines, add, totals };
}

for (const creation of [true, false]) {
    test(`add, remove and reindex lines in ${creation ? 'creation' : 'historical edit'} mode`, () => {
        const { lines, add } = loadForm(creation);
        add.events.click();
        assert.equal(lines.length, 2);
        assert.equal(lines[1].controls['.invoice-description'].name, 'items[1][description]');
        if (creation) {
            assert.equal(lines[1].controls['.invoice-unit'].value, '');
            assert.equal(lines[1].controls['.invoice-treatment'].value, '');
        }
        lines[0].controls['.remove-invoice-line'].events.click();
        assert.equal(lines.length, 1);
        assert.equal(lines[0].controls['.invoice-tva'].name, 'items[0][tva_rate]');
        if (creation) {
            assert.equal(lines[0].controls['.invoice-exemption-code'].name, 'items[0][exemption_reason_code]');
        }
        lines[0].controls['.remove-invoice-line'].events.click();
        assert.equal(lines.length, 1);
    });
}

test('franchise selection is explicit and is not inherited by a new line', () => {
    const { lines, add, totals } = loadForm(true);
    assert.equal(totals['total-ttc'].textContent, '\u00c0 compl\u00e9ter');
    const row = lines[0];
    row.controls['.invoice-treatment'].value = 'franchise';
    row.controls['.invoice-treatment'].events.change();
    assert.equal(row.controls['.invoice-exemption-code'].value, 'VATEX-FR-FRANCHISE');
    assert.equal(row.controls['.invoice-tva'].value, '0');
    assert.equal(row.controls['.invoice-exemption-code'].readOnly, true);
    add.events.click();
    assert.equal(lines[1].controls['.invoice-exemption-code'].value, '');
    assert.equal(lines[1].controls['.invoice-exemption-code'].readOnly, false);
    assert.equal(lines[1].controls['.invoice-treatment'].value, '');
    assert.equal(lines[1].controls['.invoice-tva'].value, '');
});

test('decimal edit keeps prefilled fiscal values until the user changes treatment', () => {
    const { lines, add } = loadForm(true, {
        treatment: 'franchise', tva: '0', unit: 'HUR', price: '100',
        'exemption-code': 'VATEX-FR-FRANCHISE', 'exemption-reason': 'Saved justification',
    });
    assert.equal(lines[0].controls['.invoice-exemption-code'].value, 'VATEX-FR-FRANCHISE');
    assert.equal(lines[0].controls['.invoice-exemption-reason'].value, 'Saved justification');
    assert.equal(lines[0].controls['.invoice-unit'].value, 'HUR');
    assert.equal(lines[0].controls['.invoice-tva'].value, '0');
    add.events.click();
    assert.equal(lines[1].controls['.invoice-exemption-reason'].value, '');
    assert.equal(lines[1].controls['.invoice-unit'].value, '');
    assert.equal(lines[0].controls['.invoice-exemption-reason'].value, 'Saved justification');
});
