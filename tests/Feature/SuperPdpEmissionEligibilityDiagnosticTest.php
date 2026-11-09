<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $data = SuperPdpPayloadFixture::data();
    $this->user = User::factory()->create();
    $this->user->company()->create(array_diff_key($data['seller'], array_flip(['name', 'country_code'])) + ['company_name' => 'Atelier Martin', 'vat_regime' => 'subject_to_vat']);
    $notes = array_column(SuperPdpPayloadFixture::frenchContext()['notes'], 'note', 'subject_code');
    $this->user->company()->update([
        'invoice_recovery_indemnity_text' => $notes['PMT'],
        'invoice_late_payment_penalties_text' => $notes['PMD'],
        'invoice_early_payment_discount_text' => $notes['AAB'],
    ]);
    $client = $this->user->clients()->create(array_diff_key($data['buyer'], array_flip(['full_name', 'client_type'])) + [
        'client_type' => 'professional', 'company_name' => 'Client professionnel', 'siret' => '55210055400013',
    ]);
    $this->invoice = Invoice::create([
        'user_id' => $this->user->id, 'client_id' => $client->id,
        'french_business_process_type' => 'S1',
        'invoice_number' => $data['invoice_number'], 'date' => $data['date'], 'due_date' => $data['due_date'],
        'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
        ...$data['totals'],
    ]);
    $this->invoice->items()->create(array_diff_key($data['items'][0], ['id' => true]));
    $this->context = [
        'document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR', 'vat_regime' => 'subject_to_vat',
        'seller_electronic_address' => ['scheme' => '0225', 'value' => '732829320_ATELIER'],
        'buyer_electronic_address' => ['scheme' => '0225', 'value' => '552100554_ACHATS'],
        'french_context' => SuperPdpPayloadFixture::frenchContext(),
    ];
});

afterEach(function () {
    Http::assertNothingSent();
});

it('diagnoses fresh persisted data using only SELECTs without altering caller or storage', function () {
    $this->invoice->load('client', 'user.company', 'items', 'payments', 'creditNotes');
    $this->invoice->document_status = 'draft';
    $this->invoice->client->country_code = 'BE';
    $this->invoice->items[0]->unit_price_ht = '999.00';
    $caller = $this->invoice->toArray();
    $tables = ['invoices', 'invoice_items', 'clients', 'companies', 'payments', 'credit_notes'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $queries = [];
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Toute écriture est interdite pendant le diagnostic.');
        }
    });
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);

    expect($result['local_checks_passed'])->toBeFalse()->and($result['eligible'])->toBeFalse()
        ->and($this->invoice->toArray())->toBe($caller)->and($snapshot())->toBe($before)
        ->and($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect($query)->toMatch('/^\s*select\b/i');
    }
});

it('finds payments hidden by a stale empty relationship including zero or later payments', function (string $amount, string $date) {
    $this->invoice->load('payments');
    $this->invoice->payments()->create(['amount' => $amount, 'paid_at' => $date]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('invoice.payments.unsupported');
})->with([['0.00', '2026-11-01'], ['10.00', '2026-12-01']]);

it('distinguishes draft and issued credit notes from the storage', function (string $status, bool $expected) {
    $this->invoice->load('creditNotes');
    $this->invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001', 'date' => '2026-11-06',
        'status' => $status, 'reason' => 'Test', 'subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00',
    ]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(in_array('invoice.credit_notes.unsupported', array_column($result['blockers'], 'code'), true))->toBe($expected);
})->with([['issued', true], ['draft', false]]);

it('reports a missing seller profile without silently qualifying its identity', function () {
    $this->user->company()->delete();
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('seller.siret.missing', 'seller.address.missing');
});

it('uses the stored taxable regime without requiring manual context', function () {
    unset($this->context['vat_regime']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()->and($result['eligible'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable', 'invoice.party_snapshot.missing']);

    $source = app(InvoiceExportDataBuilder::class);
    expect($source->buildElectronic($this->invoice)['company_vat_regime'])->toBe('subject_to_vat')
        ->and($source->build($this->invoice))->not->toHaveKey('company_vat_regime');
});

it('retains franchise BT32 checks with the stored regime and no manual regime', function () {
    unset($this->context['vat_regime']);
    $this->user->company()->update(['vat_regime' => 'franchise', 'vat_number' => null, 'tva_number' => null]);
    $this->invoice->items()->update(['vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE']);
    $this->invoice->update(['total_tva' => '0.00', 'total_ttc' => '60.00']);
    $diagnostic = app(SuperPdpEmissionEligibilityDiagnostic::class);

    $result = $diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->not->toContain('seller.tax_registration_identifier.missing', 'context.vat_regime.missing');
    $this->context['tax_registration_identifier'] = '732829320';
    expect($diagnostic->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeFalse();
    $this->context['tax_registration_identifier'] = '999999999';
    expect(array_column($diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))
        ->toContain('seller.tax_registration_identifier.invalid');
});

it('blocks historical null regimes rather than inferring from numbers or lines', function (?string $vat, string $category) {
    $this->user->company()->update(['vat_regime' => null, 'vat_number' => $vat, 'tva_number' => $vat]);
    if ($category === 'E') {
        $this->invoice->items()->update(['vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE']);
        $this->invoice->update(['total_tva' => '0.00', 'total_ttc' => '60.00']);
    }
    unset($this->context['vat_regime']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', 'context.vat_regime.missing');
    expect($blocker['category'])->toBe('missing_data')
        ->and($blocker['message'])->toBe('Régime de TVA non renseigné dans les paramètres de votre entreprise.')
        ->and($result['local_checks_passed'])->toBeFalse();
})->with([['FR44732829320', 'S'], [null, 'S'], [null, 'E']]);

it('reports explicit contradictions and still checks the stored regime', function (string $stored, string $supplied, string $expectedCode) {
    $this->user->company()->update(['vat_regime' => $stored, 'vat_number' => null, 'tva_number' => null]);
    $this->context['vat_regime'] = $supplied;
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('tax.regime.context_conflict', $expectedCode);
})->with([
    ['subject_to_vat', 'franchise', 'seller.vat_number.missing'],
    ['franchise', 'subject_to_vat', 'tax.franchise.line_invalid'],
]);

it('does not let a manual regime replace an unspecified company profile', function () {
    $this->user->company()->update(['vat_regime' => null]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('context.vat_regime.missing', 'tax.regime.context_conflict')
        ->and($this->user->company()->sole()->vat_regime)->toBeNull();
});

it('rereads the company regime without using dirty cached relations or writing', function () {
    $this->invoice->load('user.company');
    $this->user->company()->update(['vat_regime' => 'franchise', 'vat_number' => null, 'tva_number' => null]);
    $this->invoice->user->company->vat_regime = 'subject_to_vat';
    $caller = $this->invoice->toArray();
    $stored = DB::table('companies')->where('user_id', $this->user->id)->first();
    unset($this->context['vat_regime']);
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture pendant le diagnostic du régime.');
        }
    });
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('tax.franchise.line_invalid')
        ->not->toContain('context.vat_regime.missing', 'tax.regime.context_conflict', 'seller.vat_number.missing', 'seller.tax_registration_identifier.missing');
    expect($this->invoice->toArray())->toBe($caller)
        ->and((array) DB::table('companies')->where('user_id', $this->user->id)->first())->toBe((array) $stored);
});

it('keeps company regimes isolated between invoice owners on the detail page', function () {
    $other = User::factory()->create();
    $other->company()->create(['company_name' => 'Autre artisan', 'vat_regime' => 'franchise']);
    $otherClient = $other->clients()->create(['first_name' => 'Autre client']);
    $otherInvoice = Invoice::create([
        'user_id' => $other->id, 'client_id' => $otherClient->id, 'date' => '2026-11-07',
        'document_status' => 'draft', 'status' => 'non_payee', 'amount_paid' => '0.00',
        'subtotal_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00',
    ]);
    $this->actingAs($other)->get(route('invoices.show', $this->invoice))->assertForbidden();
    $this->get(route('invoices.show', $otherInvoice))->assertOk()->assertViewHas('emissionDiagnostic', function ($result) {
        expect(array_column($result['blockers'], 'code'))->toContain('seller.tax_registration_identifier.missing')
            ->not->toContain('context.vat_regime.missing');

        return true;
    });
    $this->actingAs($this->user)->get(route('invoices.show', $otherInvoice))->assertForbidden();
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertViewHas('emissionDiagnostic', function ($result) {
        expect(array_column($result['blockers'], 'code'))->not->toContain('seller.tax_registration_identifier.missing', 'context.vat_regime.missing');

        return true;
    });
});

dataset('persisted French mentions', [
    'PMT' => ['PMT', 'invoice_recovery_indemnity_text'],
    'PMD' => ['PMD', 'invoice_late_payment_penalties_text'],
    'AAB' => ['AAB', 'invoice_early_payment_discount_text'],
]);

it('passes the exact stored mentions to the shared builder with only an explicit process', function (string $mode) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => $mode]);
    $texts = [
        'PMT' => "Texte de recouvrement propre à cet artisan.\nDeuxième ligne.",
        'PMD' => 'Conditions de pénalités choisies explicitement.',
        'AAB' => 'Conditions d’escompte saisies par l’entreprise.',
    ];
    $this->user->company()->update([
        'invoice_recovery_indemnity_text' => $texts['PMT'],
        'invoice_late_payment_penalties_text' => $texts['PMD'],
        'invoice_early_payment_discount_text' => $texts['AAB'],
    ]);
    $this->context['french_context'] = ['business_process_type' => $mode];
    $source = app(InvoiceExportDataBuilder::class);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($source->buildElectronic($this->invoice))]);
    expect($source->buildElectronic($this->invoice)['company_invoice_mentions'])->toBe($texts)
        ->and($source->build($this->invoice))->not->toHaveKey('company_invoice_mentions');
    $realBuilder = app(SuperPdpInvoicePayloadBuilder::class);
    $builder = $this->createMock(SuperPdpInvoicePayloadBuilder::class);
    $builder->expects($this->once())->method('buildFromData')->willReturnCallback(
        function ($data, $seller, $buyer, $frenchContext) use ($texts, $mode, $realBuilder) {
            expect($frenchContext)->toBe([
                'business_process_type' => $mode,
                'notes' => array_map(fn ($code) => ['subject_code' => $code, 'note' => $texts[$code]], ['PMT', 'PMD', 'AAB']),
            ]);

            return $realBuilder->buildFromData($data, $seller, $buyer, $frenchContext);
        }
    );
    $diagnostic = new SuperPdpEmissionEligibilityDiagnostic($source, app(InvoiceFiscalDataPreparer::class), $builder);
    $before = $this->context;
    $result = $diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['eligible'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable'])
        ->and($this->context)->toBe($before)->and($result)->not->toHaveKey('payload');
})->with(['B1', 'S1', 'M1']);

it('reports a precise blocker for each missing or blank stored mention', function (string $code, string $column, ?string $value) {
    $this->user->company()->update([$column => $value]);
    unset($this->context['french_context']['notes']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', 'company.mentions.'.$code.'.missing');
    expect($blocker['field'])->toBe('company.'.$column)->and($blocker['category'])->toBe('missing_data')
        ->and($blocker['message'])->toContain($code, 'paramètres de votre entreprise')
        ->and($result['local_checks_passed'])->toBeFalse()
        ->and(count($result['blockers']))->toBe(3);
})->with('persisted French mentions')->with([[null], [''], ['   '], ["\t\r\n"], ["\u{00A0}\u{202F}"]]);

it('does not replace missing stored mentions with manual texts', function (string $code, string $column) {
    $this->user->company()->update([$column => null]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('company.mentions.'.$code.'.missing', 'company.mentions.'.$code.'.context_conflict')
        ->and($result['local_checks_passed'])->toBeFalse()
        ->and($this->user->company()->sole()->getAttribute($column))->toBeNull();
})->with('persisted French mentions');

it('blocks contradictory manual texts and preserves the stored source', function (string $code, string $column, mixed $text) {
    $index = array_search($code, ['PMT', 'PMD', 'AAB'], true);
    $this->context['french_context']['notes'][$index]['note'] = $text;
    $before = $this->user->company()->sole()->getRawOriginal();
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', 'company.mentions.'.$code.'.context_conflict');
    expect($blocker['category'])->toBe('invalid_data')->and($blocker['field'])->toBe('french_context.notes.'.$code)
        ->and($blocker['message'])->toContain('contredit', 'ne remplace pas le profil')
        ->and($result['local_checks_passed'])->toBeFalse()
        ->and($this->user->company()->sole()->getRawOriginal())->toBe($before);
})->with('persisted French mentions')->with([['Autre mention explicite.'], [''], [null]]);

it('accepts coherent explicit mentions independently of their order', function () {
    $this->context['french_context']['notes'] = array_reverse($this->context['french_context']['notes']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable', 'invoice.party_snapshot.missing']);
});

it('does not infer a process from complete stored mentions or invoice lines', function (mixed $frenchContext) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => null]);
    $this->context['french_context'] = $frenchContext;
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'field'))->toContain('french_business_process_type')
        ->and(array_column($result['blockers'], 'code'))->not->toContain('company.mentions.PMT.missing', 'company.mentions.PMD.missing', 'company.mentions.AAB.missing');
})->with([[null], [[]], [['business_process_type' => 'B2']]]);

it('rereads mentions after editing or clearing the profile without trusting cached or dirty relations', function (string $code, string $column) {
    unset($this->context['french_context']['notes']);
    $this->invoice->load('user.company');
    $this->user->company()->update([$column => null]);
    $this->invoice->user->company->setAttribute($column, 'Valeur locale à ignorer.');
    $caller = $this->invoice->toArray();
    $before = DB::table('companies')->where('user_id', $this->user->id)->first();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture pendant le diagnostic des mentions.');
        }
    });
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('company.mentions.'.$code.'.missing')
        ->and($this->invoice->toArray())->toBe($caller)
        ->and((array) DB::table('companies')->where('user_id', $this->user->id)->first())->toBe((array) $before);
})->with('persisted French mentions');

it('keeps mentions isolated by persisted invoice owner regardless of the current user or dirty owner', function () {
    $other = User::factory()->create();
    $other->company()->create(['company_name' => 'Autre atelier']);
    $otherClient = $other->clients()->create(['first_name' => 'Autre client']);
    $otherInvoice = $this->invoice->replicate();
    $otherInvoice->fill(['user_id' => $other->id, 'client_id' => $otherClient->id])->save();
    $this->actingAs($other);
    $this->invoice->load('user.company');
    $this->invoice->user_id = $other->id;
    $this->invoice->setRelation('user', $other->load('company'));
    $source = app(InvoiceExportDataBuilder::class);
    expect($source->buildElectronic($this->invoice)['company_invoice_mentions']['PMT'])->not->toBeNull()
        ->and($source->buildElectronic($otherInvoice)['company_invoice_mentions'])->toBe(['PMT' => null, 'PMD' => null, 'AAB' => null]);
    $this->get(route('invoices.show', $this->invoice->id))->assertForbidden();
    $this->get(route('invoices.show', $otherInvoice))->assertOk()->assertViewHas('emissionDiagnostic', function ($result) {
        expect(array_column($result['blockers'], 'code'))->toContain('company.mentions.PMT.missing', 'company.mentions.PMD.missing', 'company.mentions.AAB.missing');

        return true;
    });
    $this->actingAs($this->user)->get(route('invoices.show', $otherInvoice))->assertForbidden();
    $this->get(route('invoices.show', $this->invoice->id))->assertOk()
        ->assertSee('La présence des mentions enregistrées dans votre entreprise ne valide pas leur contenu juridique.')
        ->assertDontSee('Renseignez la mention PMT')->assertDontSee('Renseignez la mention PMD')->assertDontSee('Renseignez la mention AAB');
});

it('does not fall back to payment terms or PDF texts when the owner has no company', function () {
    $this->user->company()->delete();
    unset($this->context['french_context']['notes']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('company.mentions.PMT.missing', 'company.mentions.PMD.missing', 'company.mentions.AAB.missing');
    expect(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice)['company_invoice_mentions'])
        ->toBe(['PMT' => null, 'PMD' => null, 'AAB' => null]);
});

it('builds a real invoice context from each stored process without manual French context', function (string $mode) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => $mode]);
    unset($this->context['french_context']);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice))]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    $source = app(InvoiceExportDataBuilder::class);
    expect($result['local_checks_passed'])->toBeTrue()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable'])
        ->and($source->buildElectronic($this->invoice)['french_business_process_type'])->toBe($mode)
        ->and($source->build($this->invoice))->not->toHaveKey('french_business_process_type');
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'],
    );
    expect($payload['process_control']['business_process_type'])->toBe($mode);
})->with(['B1', 'S1', 'M1']);

it('blocks absent and invalid stored processes even with a valid manual context', function (?string $stored, string $code, string $message) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice))]);
    // Simuler une donnee corrompue, uniquement dans cette connexion SQLite en memoire.
    DB::statement('PRAGMA ignore_check_constraints = ON');
    try {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => $stored]);
    } finally {
        DB::statement('PRAGMA ignore_check_constraints = OFF');
    }
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', $code);
    expect($blocker['field'])->toBe('french_business_process_type')->and($blocker['message'])->toBe($message)
        ->and($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('routing.verification_unavailable', 'invoice.business_process_type.context_conflict');
    try {
        app(SuperPdpInvoicePayloadBuilder::class)->build(
            $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
        );
        $this->fail('Un cadre manuel ne doit pas remplacer le stockage.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['french_business_process_type'])->toContain($message);
    }
})->with([
    [null, 'invoice.business_process_type.missing', 'Cadre de facturation non renseigné'],
    ['B2', 'invoice.business_process_type.invalid', 'Cadre de facturation enregistré invalide : B1, S1 ou M1 attendu.'],
    ['s1', 'invoice.business_process_type.invalid', 'Cadre de facturation enregistré invalide : B1, S1 ou M1 attendu.'],
]);

it('rejects a conflicting manual process without mutating either source', function (mixed $manual) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice))]);
    $this->context['french_context']['business_process_type'] = $manual;
    $before = $this->invoice->fresh()->getRawOriginal();
    $contextBefore = $this->context;
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('invoice.business_process_type.context_conflict')
        ->and($result['local_checks_passed'])->toBeFalse();
    try {
        app(SuperPdpInvoicePayloadBuilder::class)->build(
            $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
        );
        $this->fail('Le contexte contradictoire doit être refusé.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['french_context.business_process_type'][0])->toContain('contredit', 'ne remplace pas');
    }
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)->and($this->context)->toBe($contextBefore);
})->with([['B1'], ['M1'], ['S1 '], [null], [''], [['S1']]]);

it('rereads the stored process despite a stale dirty invoice and uses SELECTs only', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => 'M1']);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice))]);
    $this->invoice->french_business_process_type = 'B1';
    $caller = $this->invoice->getAttributes();
    unset($this->context['french_context']);
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture autorisée pendant la préparation.');
        }
    });
    expect(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeTrue();
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'],
    );
    expect($payload['process_control']['business_process_type'])->toBe('M1')
        ->and($this->invoice->getAttributes())->toBe($caller)
        ->and($this->invoice->fresh()->french_business_process_type)->toBe('M1');
});

it('keeps stored processes isolated by invoice owner even with a dirty owner or another signed in user', function () {
    $other = User::factory()->create();
    $client = $other->clients()->create(['first_name' => 'Autre client']);
    $otherInvoice = $this->invoice->replicate();
    $otherInvoice->fill(['user_id' => $other->id, 'client_id' => $client->id, 'french_business_process_type' => 'B1'])->save();
    $this->actingAs($other);
    $this->invoice->user_id = $other->id;
    $this->invoice->french_business_process_type = 'B1';
    $source = app(InvoiceExportDataBuilder::class);
    expect($source->buildElectronic($this->invoice)['french_business_process_type'])->toBe('S1')
        ->and($source->buildElectronic($otherInvoice)['french_business_process_type'])->toBe('B1');
    $this->get(route('invoices.show', $this->invoice->id))->assertForbidden();
    $this->get(route('invoices.show', $otherInvoice))->assertOk()->assertViewHas('emissionDiagnostic', fn ($result) => ! in_array('invoice.business_process_type.missing', array_column($result['blockers'], 'code'), true));
    $this->actingAs($this->user)->get(route('invoices.show', $otherInvoice))->assertForbidden();
});

it('never infers a missing process from line descriptions VAT rates or the seller regime', function (string $regime, string $category, string $description) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => null]);
    $this->user->company()->update(['vat_regime' => $regime]);
    $this->invoice->items()->update(['description' => $description, 'vat_category' => $category, 'tva_rate' => $category === 'E' ? '0.00' : '20.00']);
    unset($this->context['french_context'], $this->context['vat_regime']);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('invoice.business_process_type.missing', 'routing.verification_unavailable')
        ->and($result['local_checks_passed'])->toBeFalse()
        ->and($this->invoice->fresh()->french_business_process_type)->toBeNull();
})->with([
    ['subject_to_vat', 'S', 'Vente de biens'],
    ['subject_to_vat', 'S', 'Prestation de services'],
    ['franchise', 'E', 'Biens et services mixtes'],
]);
