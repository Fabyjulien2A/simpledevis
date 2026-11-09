<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\FrenchInvoiceContext;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès SQL autorisé pour le contexte français.');
    });
    $this->context = SuperPdpPayloadFixture::frenchContext();
    $this->data = SuperPdpPayloadFixture::data();
    $this->invoice = (new Invoice)->fill([
        'document_status' => 'issued', 'invoice_number' => 'FAC-2026-0001', 'date' => '2026-11-05',
    ]);
    $this->source = $this->createMock(InvoiceExportDataBuilder::class);
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->address = ['scheme' => '0225', 'value' => 'ADRESSE_ROUTAGE_EXPLICITE'];
});

afterEach(function () {
    Http::assertNothingSent();
});

it('maps the three explicit notes and the selected ordinary mode without changing BT24', function (string $mode) {
    $this->context['business_process_type'] = $mode;
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
    expect($payload['notes'])->toBe($this->context['notes'])
        ->and(array_column($payload['notes'], 'subject_code'))->toBe(['PMT', 'PMD', 'AAB'])
        ->and($payload['process_control'])->toBe([
            'specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => $mode,
        ]);
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
})->with(['B1', 'S1', 'M1']);

it('preserves note wording punctuation whitespace and newlines exactly', function () {
    $this->context['notes'] = [
        ['subject_code' => 'AAB', 'note' => "  Texte fourni — aucune reformulation.\n"],
        ['subject_code' => 'PMT', 'note' => 'Texte fourni : montant et conditions explicites.'],
        ['subject_code' => 'PMD', 'note' => "Texte fourni : première ligne.\nDeuxième ligne, inchangée."],
    ];
    $before = $this->context;
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
    expect($payload['notes'])->toBe([$before['notes'][1], $before['notes'][2], $before['notes'][0]])
        ->and($this->context)->toBe($before);
});

it('refuses a missing context for a synthetic invoice and never falls back to profile or PDF text', function () {
    $this->data['seller']['payment_terms'] = '40 € dus en cas de retard de paiement. 10% du montant TTC. Néant.';
    $this->data['notes'] = $this->data['seller']['payment_terms'];
    $this->data['french_context'] = $this->context;
    $this->data['items'][0]['description'] = 'Prestation de service';
    $this->source->expects($this->once())->method('buildElectronic');
    try {
        $this->builder->build($this->invoice, $this->address);
        $this->fail('The caller must supply a context explicitly.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context');
    }
});

it('refuses each missing required legal note instead of supplying a default', function (int $index, string $code) {
    unset($this->context['notes'][$index]);
    $this->context['notes'] = array_values($this->context['notes']);
    try {
        $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
        $this->fail('Each legal note is required.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.notes.'.$code);
    }
})->with([[0, 'PMT'], [1, 'PMD'], [2, 'AAB']]);

it('rejects absent blank or incorrectly typed text for every note', function (int $index, mixed $text) {
    if ($text === null) {
        unset($this->context['notes'][$index]['note']);
    } else {
        $this->context['notes'][$index]['note'] = $text;
    }
    try {
        $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
        $this->fail('Missing or blank text must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.notes.'.$index.'.note');
    }
})->with([0, 1, 2])->with([[null], [''], [' '], ["\r\n\t"], ["\u{00A0}"], [40], [false], [[]]]);

it('rejects invalid subject codes with the precise property path', function (mixed $code) {
    $this->context['notes'][0]['subject_code'] = $code;
    try {
        FrenchInvoiceContext::fromArray($this->context);
        $this->fail('Invalid subject code must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.notes.0.subject_code');
    }
})->with([[null], [''], ['pmt'], [' PMT '], ['TXT'], ['AAB-PMT'], [42], [[]]]);

it('refuses duplicate codes rather than overwriting one legal text with another', function () {
    $this->context['notes'][] = ['subject_code' => 'PMT', 'note' => 'Texte contradictoire'];
    try {
        FrenchInvoiceContext::fromArray($this->context);
        $this->fail('Duplicate note subjects must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.notes.3.subject_code');
    }
});

it('rejects unknown and unsupported modes without inferring one from the invoice', function (mixed $mode) {
    $this->context['business_process_type'] = $mode;
    try {
        $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
        $this->fail('A mode outside the ordinary supported scope must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.business_process_type');
    }
})->with([[null], [''], ['S2'], ['B2'], ['M2'], ['S3'], ['B4'], ['S4'], ['M4'], ['S5'], ['S6'], ['B7'], ['S7'], ['B8'], ['S8'], ['M8'], ['B9'], ['S9'], ['M9'], ['UNKNOWN'], ['s1'], [' S1 '], [1], [[]]]);

it('requires an explicit mode even for a description and unit suggestive of services', function () {
    unset($this->context['business_process_type']);
    $this->data['items'][0]['description'] = 'Prestation de service facturée à l’heure';
    $this->data['items'][0]['unit_code'] = 'HUR';
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: $this->context))
        ->toThrow(ValidationException::class);
});

it('requires the notes list and accepts no implicit text aliases', function (mixed $notes) {
    $this->context['notes'] = $notes;
    expect(fn () => FrenchInvoiceContext::fromArray($this->context))->toThrow(ValidationException::class);
})->with([[null], [''], [[]], [['PMT' => 'Texte sans structure de note']]]);

it('rejects unsupported context and note properties rather than hiding spelling mistakes', function (string $path) {
    data_set($this->context, $path, 'Valeur non prise en charge');
    try {
        FrenchInvoiceContext::fromArray($this->context);
        $this->fail('Unknown properties must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('french_context.'.$path);
    }
})->with(['billing_mode', 'notes.0.content', 'notes.1.subject']);

it('changes only the explicitly supplied context and preserves all fiscal identity and routing data', function () {
    $beforeData = $this->data;
    $beforeInvoice = $this->invoice->getAttributes();
    $first = $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
    $this->context['business_process_type'] = 'M1';
    $this->context['notes'][0]['note'] = 'Autre texte fourni explicitement.';
    $second = $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
    expect($second['process_control']['business_process_type'])->toBe('M1')
        ->and($second['notes'][0]['note'])->toBe('Autre texte fourni explicitement.');
    unset($first['notes'], $second['notes'], $first['process_control']['business_process_type'], $second['process_control']['business_process_type']);
    expect($second)->toBe($first)->and($this->data)->toBe($beforeData)
        ->and($this->invoice->getAttributes())->toBe($beforeInvoice)
        ->and($second['totals']['total_with_vat'])->toBe('72.00');
});

describe('persisted French mentions', function () {
    beforeEach(function () {
        $this->data['french_business_process_type'] = 'S1';
        $this->data['company_invoice_mentions'] = array_column($this->context['notes'], 'note', 'subject_code');
    });

    it('uses stored notes with absent or identical manual notes in either order', function (string $mode) {
        $context = match ($mode) {
            'absent' => null,
            'reversed' => ['notes' => array_reverse($this->context['notes'])],
            default => $this->context,
        };
        $before = $this->data;
        $payload = $this->builder->buildFromData($this->data, $this->address, frenchContext: $context);
        expect($payload['notes'])->toBe($this->context['notes'])
            ->and($payload['process_control']['business_process_type'])->toBe('S1')
            ->and($this->data)->toBe($before);
    })->with(['absent', 'identical', 'reversed']);

    it('refuses each contradictory manual note even for a direct builder call', function (int $index, string $code, string $change) {
        $this->context['notes'][$index]['note'] .= $change;
        try {
            $this->builder->build($this->invoice, $this->address, frenchContext: $this->context);
            $this->fail('Manual notes must never replace stored mentions.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('french_context.notes.'.$code)
                ->and($exception->errors()['french_context.notes.'.$code][0])->toContain('contredit');
        }
    })->with([[0, 'PMT'], [1, 'PMD'], [2, 'AAB']])->with(['Autre texte', ' ', "\n", '.']);

    it('rejects missing blank invalid or oversized stored notes despite valid manual notes', function (string $code, mixed $value) {
        $this->data['company_invoice_mentions'][$code] = $value;
        expect(fn () => $this->builder->buildFromData($this->data, $this->address, frenchContext: $this->context))
            ->toThrow(ValidationException::class);
    })->with(['PMT', 'PMD', 'AAB'])->with([[null], [''], [' '], ["\u{00A0}"], [false], [42], [[]], [str_repeat('é', 2001)]]);

    it('rejects missing stored mention metadata or keys despite complete manual notes', function (?string $code) {
        if ($code === null) {
            unset($this->data['company_invoice_mentions']);
        } else {
            unset($this->data['company_invoice_mentions'][$code]);
        }
        expect(fn () => $this->builder->buildFromData($this->data, $this->address, frenchContext: $this->context))
            ->toThrow(ValidationException::class);
    })->with([null, 'PMT', 'PMD', 'AAB']);

    it('still rejects malformed incomplete duplicate or unknown manual notes', function (string $case) {
        $this->context['notes'] = match ($case) {
            'null' => null,
            'empty' => [],
            'incomplete' => array_slice($this->context['notes'], 1),
            'duplicate' => [...$this->context['notes'], $this->context['notes'][0]],
            'unknown' => [...$this->context['notes'], ['subject_code' => 'TXT', 'note' => 'Texte']],
            'property' => [array_merge($this->context['notes'][0], ['other' => 'Texte']), ...array_slice($this->context['notes'], 1)],
        };
        expect(fn () => $this->builder->buildFromData($this->data, $this->address, frenchContext: $this->context))
            ->toThrow(ValidationException::class);
    })->with(['null', 'empty', 'incomplete', 'duplicate', 'unknown', 'property']);

    it('preserves exact stored wording and accepts the stored character limit', function () {
        $this->data['company_invoice_mentions'] = ['PMT' => str_repeat('é', 2000), 'PMD' => "  Première ligne.\nDeuxième ligne.  ", 'AAB' => 'Sans escompte.'];
        $payload = $this->builder->buildFromData($this->data, $this->address);
        expect(array_column($payload['notes'], 'note', 'subject_code'))->toBe($this->data['company_invoice_mentions']);
    });

    it('keeps stored business process protection when manual mentions agree', function () {
        $this->context['business_process_type'] = 'B1';
        try {
            $this->builder->buildFromData($this->data, $this->address, frenchContext: $this->context);
            $this->fail('The stored business process must remain authoritative.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('french_context.business_process_type');
        }
    });
});
