<?php

use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePartySnapshotException;

// Tests PHP purs : aucun TestCase Laravel, modèle, base, fichier ou transport.
beforeEach(function () {
    $this->seller = [
        'company_name' => 'Atelier fictif Élodie & Fils <SARL>',
        'name' => 'Ancienne dénomination fictive', 'user_name' => 'Nom utilisateur fourni',
        'legal_status' => 'SARL', 'siret' => '00123456700012',
        'vat_number' => null, 'tva_number' => 'FRXX001234567', 'vat_regime' => 'franchise',
        'address' => "  12, rue de l’Été\nBâtiment A  ", 'postal_code' => '00123',
        'city' => 'Ville fictive', 'country_code' => 'FR',
        'email' => 'vendeur@example.invalid', 'phone' => '+33 0 00 00 00 00',
        'iban' => 'FR00 0000 0000 0000 0000 0000 000', 'bic' => 'FICTFRXX',
        'payment_terms' => 'Paiement selon les conditions fournies.',
        'invoice_recovery_indemnity_text' => 'Mention PMT fictive : 40 €.',
        'invoice_late_payment_penalties_text' => "Mention PMD fictive.\nDeuxième ligne.",
        'invoice_early_payment_discount_text' => 'Mention AAB fictive : aucun escompte.',
        'logo' => 'logos/fictif-é.png',
    ];
    $this->buyer = [
        'company_name' => 'Client fictif « Démonstration »',
        'first_name' => 'Zoë', 'last_name' => 'D’Exemple', 'full_name' => 'Libellé fourni séparément',
        'client_type' => 'professional', 'siret' => '00012345600078', 'vat_number' => null,
        'address' => 'Adresse fictive & complément <non interprété>', 'postal_code' => '00001',
        'city' => 'Autre ville fictive', 'country_code' => 'FR',
        'email' => 'acheteur@example.invalid', 'phone' => null,
    ];
});

it('defines version one and captures all party fields including banking and French mentions exactly', function () {
    $snapshot = InvoicePartySnapshot::capture($this->seller, $this->buyer);
    expect(InvoicePartySnapshot::SCHEMA_VERSION)->toBe(1)
        ->and($snapshot->toArray())->toBe([
            'schema_version' => 1, 'seller' => $this->seller, 'buyer' => $this->buyer,
            'electronic_qualification' => ['status' => 'not_assessed'],
        ]);
});

it('keeps absent keys distinct from explicit null empty and whitespace values', function (string $party, mixed $value) {
    $seller = $buyer = [];
    if ($party === 'seller') {
        $seller['company_name'] = $value;
    } else {
        $buyer['company_name'] = $value;
    }
    $result = InvoicePartySnapshot::capture($seller, $buyer)->toArray();
    expect(array_key_exists('company_name', $result[$party]))->toBeTrue()
        ->and($result[$party]['company_name'])->toBe($value)
        ->and($result[$party])->not->toHaveKey('address')
        ->and($result[$party === 'seller' ? 'buyer' : 'seller'])->toBe([]);
})->with(['seller', 'buyer'])->with([[null], [''], [' '], ["\t\n"], ["\u{00A0}"]]);

it('accepts completely absent information without filling any party field', function () {
    $result = InvoicePartySnapshot::capture([], [])->toArray();
    expect($result['seller'])->toBe([])->and($result['buyer'])->toBe([])
        ->and($result)->not->toHaveKey('captured_at')
        ->and($result['electronic_qualification'])->toBe(['status' => 'not_assessed']);
});

it('does not resolve names VAT aliases fiscal regimes countries or legal mentions', function () {
    $seller = ['company_name' => null, 'user_name' => 'Nom explicite', 'siret' => '00123456700012', 'vat_number' => null, 'tva_number' => 'Autre valeur explicite', 'payment_terms' => 'PMT PMD AAB ne sont pas extraits.'];
    $buyer = ['first_name' => 'Prénom', 'last_name' => 'Nom'];
    $result = InvoicePartySnapshot::capture($seller, $buyer)->toArray();
    expect($result['seller'])->toBe($seller)->and($result['buyer'])->toBe($buyer)
        ->and($result['seller'])->not->toHaveKeys(['name', 'vat_regime', 'country_code', 'invoice_recovery_indemnity_text', 'siren', 'tax_registration_identifier'])
        ->and($result['buyer'])->not->toHaveKey('full_name');
});

it('captures unqualified textual declarations without pretending to validate their business meaning', function () {
    $seller = ['siret' => 'SIRET non qualifié', 'vat_regime' => 'Déclaration à vérifier', 'email' => 'Valeur à vérifier'];
    $result = InvoicePartySnapshot::capture($seller, [])->toArray();
    expect($result['seller'])->toBe($seller)
        ->and($result['electronic_qualification']['status'])->toBe('not_assessed');
});

it('produces identical arrays and JSON regardless of input key order', function () {
    $first = InvoicePartySnapshot::capture($this->seller, $this->buyer);
    $second = InvoicePartySnapshot::capture(array_reverse($this->seller, true), array_reverse($this->buyer, true));
    expect($second->toArray())->toBe($first->toArray())
        ->and(json_encode($second, JSON_THROW_ON_ERROR))->toBe(json_encode($first, JSON_THROW_ON_ERROR));
});

it('preserves Unicode special characters line endings and leading zeroes through JSON', function () {
    $this->seller['address'] = "\"Texte\" & <élément> \\ /\r\n\t\u{00A0}😀";
    $snapshot = InvoicePartySnapshot::capture($this->seller, $this->buyer);
    $decoded = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBe($snapshot->toArray())
        ->and($decoded['seller']['siret'])->toBe('00123456700012')
        ->and($decoded['buyer']['postal_code'])->toBe('00001');
});

it('serializes empty parties as objects and distinguishes missing fields from JSON null', function () {
    expect(json_encode(InvoicePartySnapshot::capture([], []), JSON_THROW_ON_ERROR))->toBe(
        '{"schema_version":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}'
    );
    $decoded = json_decode(json_encode(InvoicePartySnapshot::capture(['vat_number' => null], []), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    expect(property_exists($decoded->seller, 'vat_number'))->toBeTrue()
        ->and($decoded->seller->vat_number)->toBeNull()
        ->and(property_exists($decoded->seller, 'tva_number'))->toBeFalse();
});

it('does not mutate caller values and cannot be changed through returned data', function () {
    $before = [$this->seller, $this->buyer];
    $snapshot = InvoicePartySnapshot::capture($this->seller, $this->buyer);
    expect([$this->seller, $this->buyer])->toBe($before);
    $this->seller['company_name'] = 'Modification ultérieure';
    $array = $snapshot->toArray();
    $array['buyer']['address'] = 'Autre adresse';
    $jsonData = $snapshot->jsonSerialize();
    $jsonData['seller']->company_name = 'Modification de la copie JSON';
    expect($snapshot->toArray()['seller'])->toBe($before[0])
        ->and($snapshot->toArray()['buyer'])->toBe($before[1]);
});

it('refuses unknown fields including electronic addresses routing and version overrides', function (string $party, string $field) {
    $values = [$field => 'Valeur non prise en charge'];
    expect(fn () => InvoicePartySnapshot::capture($party === 'seller' ? $values : [], $party === 'buyer' ? $values : []))
        ->toThrow(InvalidArgumentException::class, 'non pris en charge');
})->with(['seller', 'buyer'])->with(['electronic_address', 'bt34', 'bt49', 'routing_verified', 'is_active', 'schema_version', 'access_token', 'unexpected_field']);

it('refuses incompatible values without casting or including supplied data in errors', function (string $party, mixed $value) {
    $values = ['company_name' => $value];
    try {
        InvoicePartySnapshot::capture($party === 'seller' ? $values : [], $party === 'buyer' ? $values : []);
        $this->fail('Only UTF-8 strings or null belong to this contract.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toBe('Chaque valeur de capture '.($party === 'seller' ? 'vendeur' : 'acheteur').' doit être une chaîne UTF-8 ou null.');
    }
})->with(['seller', 'buyer'])->with([[42], [1.5], [false], [true], [[]], [new stdClass], ["\xB1"]]);

it('refuses numeric field keys instead of treating party data as a list', function () {
    expect(fn () => InvoicePartySnapshot::capture(['Valeur sans nom de champ'], []))
        ->toThrow(InvalidArgumentException::class);
});

it('restores version one from raw JSON without changing absent or null fields', function () {
    $original = InvoicePartySnapshot::capture($this->seller, $this->buyer);
    $restored = InvoicePartySnapshot::fromJson(json_encode($original, JSON_THROW_ON_ERROR));
    expect($restored->toArray())->toBe($original->toArray())
        ->and(InvoicePartySnapshot::fromJson(json_encode(InvoicePartySnapshot::capture([], []), JSON_THROW_ON_ERROR))->toArray()['seller'])->toBe([]);
});

it('rejects absent invalid or unknown versioned JSON without exposing its contents', function (mixed $json, string $reason) {
    try {
        InvoicePartySnapshot::fromJson($json);
        $this->fail('The stored contract must be validated.');
    } catch (InvoicePartySnapshotException $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->blocker()['field'])->toBe('party_snapshot')
            ->and($exception->getMessage())->not->toContain('CONFIDENTIEL');
    }
})->with([
    [null, 'missing'], [false, 'invalid'], [[], 'invalid'], ['', 'invalid'], ['null', 'invalid'], ['[]', 'invalid'], ['{}', 'invalid'],
    ['{"schema_version":3,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'unsupported_version'],
    ['{"schema_version":3}', 'unsupported_version'],
    ['{"schema_version":"1","seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1.0,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":[],"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":{},"buyer":null,"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":{"access_token":"CONFIDENTIEL"},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":{"siret":123},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"verified"}}', 'invalid'],
    ['{"schema_version":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed","routing_verified":true}}', 'invalid'],
    ['{"schema_version":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"},"extra":1}', 'invalid'],
    ['{"schema_version":2,"schema_version":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"schema_\u0076ersion":1,"seller":{},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
    ['{"schema_version":1,"seller":{"name":"Premier","name":"Second"},"buyer":{},"electronic_qualification":{"status":"not_assessed"}}', 'invalid'],
]);
