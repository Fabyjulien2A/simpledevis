<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->fields = [
        'invoice_recovery_indemnity_text',
        'invoice_late_payment_penalties_text',
        'invoice_early_payment_discount_text',
    ];
    $this->texts = array_combine($this->fields, [
        "Mention de recouvrement choisie par l’artisan.\nSeconde ligne.",
        'Conditions de pénalités saisies explicitement.',
        'Conditions d’escompte propres à cet artisan.',
    ]);
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Atelier de test', 'payment_terms' => 'Conditions historiques distinctes.',
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
});

dataset('company invoice mention fields', [
    'PMT' => 'invoice_recovery_indemnity_text',
    'PMD' => 'invoice_late_payment_penalties_text',
    'AAB' => 'invoice_early_payment_discount_text',
]);

it('saves and displays the three explicitly entered mentions without replacing payment terms', function () {
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), $this->texts)
        ->assertRedirect(route('company.edit'))->assertSessionHasNoErrors();
    expect($this->company->fresh()->only($this->fields))->toBe($this->texts)
        ->and($this->company->fresh()->payment_terms)->toBe('Conditions historiques distinctes.');
    $response = $this->get(route('company.edit'))->assertOk();
    foreach ($this->texts as $field => $text) {
        $response->assertSee('name="'.$field.'"', false)->assertSee($text);
    }
    $response->assertSee('Indemnité forfaitaire pour frais de recouvrement (PMT)')
        ->assertSee('Pénalités de retard (PMD)')->assertSee('Escompte pour paiement anticipé (AAB)')
        ->assertSee('juridiquement appropriée')->assertSee('ne modifie pas les PDF actuels.');
});

it('creates a company with explicit mentions', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('company.update'), ['company_name' => 'Nouvel atelier'] + $this->texts)
        ->assertSessionHasNoErrors();
    expect($user->company()->sole()->only($this->fields))->toBe($this->texts);
});

it('leaves new or historical settings empty without inferring mentions from payment terms', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('company.edit'))->assertOk()
        ->assertDontSee('40 € dus')->assertDontSee('10% du montant TTC.')->assertDontSee('Néant.');
    $this->post(route('company.update'), ['company_name' => 'Nouveau profil'])->assertSessionHasNoErrors();
    expect($user->company()->sole()->only($this->fields))->toBe(array_fill_keys($this->fields, null));
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()
        ->assertDontSee('40 € dus')->assertDontSee('10% du montant TTC.')->assertDontSee('Néant.');
    $this->post(route('company.update'), ['city' => 'Lyon'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->only($this->fields))->toBe(array_fill_keys($this->fields, null));
});

it('preserves omitted mentions when editing other company settings', function () {
    $this->company->update($this->texts);
    $this->actingAs($this->owner)->post(route('company.update'), [
        'company_name' => 'Nouveau nom', 'payment_terms' => 'Nouvelles conditions.',
    ])->assertSessionHasNoErrors();
    expect($this->company->fresh()->only($this->fields))->toBe($this->texts)
        ->and($this->company->fresh()->company_name)->toBe('Nouveau nom');
});

it('clears only the explicitly emptied mention', function (string $field, mixed $input) {
    $this->company->update($this->texts);
    $this->actingAs($this->owner)->post(route('company.update'), [$field => $input])->assertSessionHasNoErrors();
    expect($this->company->fresh()->only($this->fields))->toBe(array_replace($this->texts, [$field => null]));
})->with('company invoice mention fields')->with([[''], [null], ['   '], ["\t\n"]]);

it('accepts exactly 2000 unicode characters for each mention', function (string $field) {
    $text = str_repeat('é', 2000);
    $this->actingAs($this->owner)->post(route('company.update'), [$field => $text])->assertSessionHasNoErrors();
    expect($this->company->fresh()->getAttribute($field))->toBe($text);
})->with('company invoice mention fields');

it('rejects invalid mentions without saving any profile change', function (string $field, mixed $input) {
    $this->company->update($this->texts);
    $before = $this->company->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        $field => $input, 'company_name' => 'Ne pas enregistrer',
    ])->assertRedirect(route('company.edit'))->assertSessionHasErrors($field);
    expect($this->company->fresh()->getRawOriginal())->toBe($before);
})->with('company invoice mention fields')->with([[str_repeat('é', 2001)], [42], [false], [true], [['texte']]]);

it('redisplays submitted mentions including an explicit blank after another validation error', function (string $field, string $input) {
    $this->company->update($this->texts);
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        $field => $input, 'email' => 'invalid',
    ])->assertSessionHasErrors('email');
    $this->get(route('company.edit'))->assertOk()->assertSee('>'.$input.'</textarea>', false);
    expect($this->company->fresh()->only($this->fields))->toBe($this->texts);
})->with('company invoice mention fields')->with(['Texte saisi à conserver', '']);

it('escapes stored mention markup in the form', function () {
    $text = '</textarea><script>alert(1)</script>';
    $this->company->update(array_fill_keys($this->fields, $text));
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()->assertSee($text)->assertDontSee($text, false);
});

it('isolates reading and writing mentions between users even with forged identifiers', function () {
    $other = User::factory()->create();
    $otherTexts = array_fill_keys($this->fields, 'Mention privée de l’autre artisan.');
    $otherCompany = $other->company()->create(['company_name' => 'Autre artisan'] + $otherTexts);
    $before = $otherCompany->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()->assertDontSee('Mention privée');
    $this->post(route('company.update'), $this->texts + [
        'user_id' => $other->id, 'company_id' => $otherCompany->id, 'id' => $otherCompany->id,
    ])->assertSessionHasNoErrors();
    expect($this->company->fresh()->only($this->fields))->toBe($this->texts)
        ->and($otherCompany->fresh()->getRawOriginal())->toBe($before);
    $this->actingAs($other)->get(route('company.edit'))->assertOk()->assertSee('Mention privée')
        ->assertDontSee($this->texts[$this->fields[0]]);
});

it('requires authentication to read or write the mentions', function () {
    $before = $this->company->fresh()->getRawOriginal();
    $this->get(route('company.edit'))->assertRedirect(route('login'));
    $this->post(route('company.update'), $this->texts)->assertRedirect(route('login'));
    expect($this->company->fresh()->getRawOriginal())->toBe($before);
});

it('adds nullable mentions without changing historical rows or copying legacy texts', function () {
    $previousConnection = DB::getDefaultConnection();
    $connectionName = 'company_invoice_mentions_legacy_test';
    config(['database.connections.'.$connectionName => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    try {
        DB::setDefaultConnection($connectionName);
        Schema::clearResolvedInstance('db.schema');
        $migrationName = '2026_11_07_000002_add_french_invoice_mentions_to_companies_table.php';
        $paths = glob(database_path('migrations/*.php'));
        sort($paths);
        foreach ($paths as $path) {
            if (basename($path) < $migrationName) {
                (require $path)->up();
            }
        }
        User::factory()->create()->company()->create([
            'company_name' => 'Profil historique', 'payment_terms' => 'Texte historique à ne pas recopier.',
        ]);
        $before = DB::table('companies')->get()->map(fn ($row) => (array) $row)->all();
        (require database_path('migrations/'.$migrationName))->up();
        $after = DB::table('companies')->get()->map(fn ($row) => (array) $row)->all();
        expect($after)->toBe(array_map(fn ($row) => [...$row, ...array_fill_keys($this->fields, null)], $before));
        foreach ($this->fields as $field) {
            $column = collect(Schema::getColumns('companies'))->firstWhere('name', $field);
            expect($column['nullable'])->toBeTrue()->and($column['default'])->toBeNull();
        }
    } finally {
        DB::setDefaultConnection($previousConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($connectionName);
        config(['database.connections.'.$connectionName => null]);
    }
});
