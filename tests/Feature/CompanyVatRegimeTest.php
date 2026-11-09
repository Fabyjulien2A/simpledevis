<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Atelier de test', 'siret' => '73282932000074',
        'tva_number' => 'FR44732829320', 'vat_number' => 'FR44732829320',
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('saves either explicitly declared regime without changing the VAT number', function (string $regime) {
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), ['vat_regime' => $regime])
        ->assertRedirect(route('company.edit'))->assertSessionHasNoErrors();
    $company = $this->company->fresh();
    expect($company->vat_regime)->toBe($regime)
        ->and($company->tva_number)->toBe('FR44732829320')->and($company->vat_number)->toBe('FR44732829320');
})->with(['subject_to_vat', 'franchise']);

it('allows explicitly creating a company with either regime and no VAT number', function (string $regime) {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('company.update'), ['company_name' => 'Nouvel artisan', 'vat_regime' => $regime])
        ->assertSessionHasNoErrors();
    $company = $user->company()->sole();
    expect($company->vat_regime)->toBe($regime)->and($company->tva_number)->toBeNull()->and($company->vat_number)->toBeNull();
})->with(['subject_to_vat', 'franchise']);

it('allows changing the declared regime in either direction', function (string $before, string $after) {
    $this->company->update(['vat_regime' => $before]);
    $this->actingAs($this->owner)->post(route('company.update'), ['vat_regime' => $after])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBe($after)
        ->and($this->company->fresh()->vat_number)->toBe('FR44732829320');
})->with([['subject_to_vat', 'franchise'], ['franchise', 'subject_to_vat']]);

it('allows explicitly returning a declared regime to unspecified', function (mixed $value) {
    $this->company->update(['vat_regime' => 'franchise']);
    $this->actingAs($this->owner)->post(route('company.update'), ['vat_regime' => $value])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBeNull()
        ->and($this->company->fresh()->vat_number)->toBe('FR44732829320');
})->with([[''], [null]]);

it('preserves a declaration when another parameter is updated without the regime field', function () {
    $this->company->update(['vat_regime' => 'franchise']);
    $this->actingAs($this->owner)->post(route('company.update'), ['phone' => '0102030405'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBe('franchise')->and($this->company->fresh()->phone)->toBe('0102030405');
});

it('keeps historical profiles unspecified regardless of the VAT number', function (?string $number) {
    $this->company->update(['vat_number' => $number, 'tva_number' => $number]);
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()
        ->assertSee('value="" selected', false)->assertSee('Non renseigné');
    $this->post(route('company.update'), ['city' => 'Lyon'])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBeNull()->and($this->company->fresh()->city)->toBe('Lyon');
})->with([['FR44732829320'], [null]]);

it('rejects unknown or incorrectly typed regimes without changing the profile', function (mixed $regime) {
    $before = $this->company->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        'vat_regime' => $regime, 'company_name' => 'Ne pas enregistrer',
    ])->assertRedirect(route('company.edit'))->assertSessionHasErrors('vat_regime');
    expect($this->company->fresh()->getRawOriginal())->toBe($before);
})->with([['other'], ['SUBJECT_TO_VAT'], ['Franchise'], ['0'], [0], [false], [true], [['franchise']]]);

it('limits the model storage to the two regimes or null', function () {
    $this->company->vat_regime = 'other';
    expect(fn () => $this->company->save())->toThrow(QueryException::class);
});

it('shows the selected declaration and a separate nonblocking note for franchise with a VAT number', function () {
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        'vat_regime' => 'franchise', 'tva_number' => 'FR44732829320',
    ])->assertSessionHasNoErrors();
    $this->get(route('company.edit'))->assertOk()
        ->assertSee('value="franchise" selected', false)
        ->assertSee('Entreprise redevable de la TVA')
        ->assertSee('Franchise en base de TVA (déclarée par l’entreprise)')
        ->assertSee('Il n’est pas déduit de votre numéro de TVA.')
        ->assertSee('Vérifiez la cohérence avec votre situation fiscale. Le numéro a été conservé.');
    expect($this->company->fresh()->vat_number)->toBe('FR44732829320');
});

it('does not show the franchise consistency note when no number is recorded', function () {
    $this->company->update(['vat_regime' => 'franchise', 'tva_number' => null, 'vat_number' => null]);
    $this->actingAs($this->owner)->get(route('company.edit'))->assertOk()
        ->assertDontSee('Le numéro a été conservé.');
});

it('retains the chosen form option after another field fails validation without saving it', function () {
    $this->actingAs($this->owner)->from(route('company.edit'))->post(route('company.update'), [
        'vat_regime' => 'franchise', 'email' => 'invalid',
    ])->assertSessionHasErrors('email');
    $this->get(route('company.edit'))->assertOk()->assertSee('value="franchise" selected', false);
    expect($this->company->fresh()->vat_regime)->toBeNull();
});

it('does not infer or change a declaration when the VAT number changes or is cleared', function (string $number) {
    $this->company->update(['vat_regime' => 'subject_to_vat']);
    $this->actingAs($this->owner)->post(route('company.update'), ['tva_number' => $number])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBe('subject_to_vat');
})->with(['FR96552100554', '']);

it('isolates the regime declaration from another user company', function () {
    $other = User::factory()->create();
    $otherCompany = $other->company()->create(['company_name' => 'Autre artisan', 'vat_regime' => 'subject_to_vat']);
    $before = $otherCompany->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->post(route('company.update'), [
        'vat_regime' => 'franchise', 'user_id' => $other->id, 'company_id' => $otherCompany->id,
    ])->assertSessionHasNoErrors();
    expect($this->company->fresh()->vat_regime)->toBe('franchise')
        ->and($otherCompany->fresh()->getRawOriginal())->toBe($before);
});

it('requires authentication for regime declarations', function () {
    $this->post(route('company.update'), ['vat_regime' => 'franchise'])->assertRedirect(route('login'));
    expect($this->company->fresh()->vat_regime)->toBeNull();
});

it('adds a nullable constrained column without qualifying or changing existing companies', function () {
    $previousConnection = DB::getDefaultConnection();
    $connectionName = 'company_vat_regime_legacy_test';
    config(['database.connections.'.$connectionName => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    try {
        DB::setDefaultConnection($connectionName);
        Schema::clearResolvedInstance('db.schema');
        $migrationName = '2026_11_07_000001_add_vat_regime_to_companies_table.php';
        $paths = glob(database_path('migrations/*.php'));
        sort($paths);
        foreach ($paths as $path) {
            if (basename($path) < $migrationName) {
                (require $path)->up();
            }
        }
        expect(Schema::hasColumn('companies', 'vat_regime'))->toBeFalse();
        foreach (['FR44732829320', null] as $number) {
            User::factory()->create()->company()->create([
                'company_name' => 'Profil historique', 'vat_number' => $number, 'tva_number' => $number,
            ]);
        }
        $before = DB::table('companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        (require database_path('migrations/'.$migrationName))->up();
        $after = DB::table('companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        expect($after)->toBe(array_map(fn ($row) => [...$row, 'vat_regime' => null], $before));

        $column = collect(Schema::getColumns('companies'))->firstWhere('name', 'vat_regime');
        expect($column['nullable'])->toBeTrue()->and($column['default'])->toBeNull();
        $newCompany = User::factory()->create()->company()->create(['company_name' => 'Nouveau profil']);
        expect($newCompany->fresh()->vat_regime)->toBeNull();
    } finally {
        DB::setDefaultConnection($previousConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($connectionName);
        config(['database.connections.'.$connectionName => null]);
    }
});
