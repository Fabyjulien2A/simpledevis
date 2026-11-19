<?php

use App\Console\Commands\CreateSandboxTricatel;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Tests\Support\TricatelCreationDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->previousResolver = Model::getConnectionResolver();
    $this->memory = new TricatelCreationDatabase;
    $factory = $this->createMock(ConnectionFactory::class);
    $factory->method('make')->willReturn($this->memory);
    app()->instance('db.factory', $factory);
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql',
            'database' => 'fabyjulien_simpledevis_test', 'username' => 'fabyjulien_simpledevis_test']]]);
    SandboxDatabaseIsolation::install(app());
    DB::clearResolvedInstance('db');
    Model::setConnectionResolver(app('db'));
    Http::preventStrayRequests();
    $this->credentials = fn ($command) => $command
        ->expectsQuestion('Adresse email dédiée au compte Tricatel (saisie masquée)', 'tricatel@sandbox.invalid')
        ->expectsQuestion('Mot de passe du compte de test (12 caractères minimum)', 'Offline-test-password-938!')
        ->expectsQuestion('Confirmer le mot de passe', 'Offline-test-password-938!');
    $this->confirmed = fn () => ($this->credentials)($this->artisan('superpdp:sandbox-create-tricatel', ['--execute' => true]))
        ->expectsConfirmation('Confirmer la création d’un nouvel utilisateur et de Tricatel dans fabyjulien_simpledevis_test uniquement ?', 'yes');
});

afterEach(function () {
    Model::setConnectionResolver($this->previousResolver);
    Http::assertNothingSent();
});

it('does nothing by default or when confirmation is declined', function () {
    $before = $this->memory->rows;
    $this->artisan('superpdp:sandbox-create-tricatel')->assertExitCode(0);
    ($this->credentials)($this->artisan('superpdp:sandbox-create-tricatel', ['--execute' => true]))
        ->expectsConfirmation('Confirmer la création d’un nouvel utilisateur et de Tricatel dans fabyjulien_simpledevis_test uniquement ?', 'no')
        ->assertExitCode(0);
    expect($this->memory->rows)->toBe($before)->and($this->memory->operations)->toBe([]);
});

it('creates one separate owner and company with hashed password and null legal IDs only', function () {
    $before = $this->memory->rows;
    ($this->confirmed)()->doesntExpectOutputToContain('tricatel@sandbox.invalid')
        ->doesntExpectOutputToContain('Offline-test-password-938!')->assertExitCode(0);
    $user = $this->memory->rows['users'][4];
    $company = $this->memory->rows['companies'][4];
    expect($user['email'])->toBe('tricatel@sandbox.invalid')->and(Hash::check('Offline-test-password-938!', $user['password']))->toBeTrue()
        ->and($company['company_name'])->toBe('Tricatel')->and($company['user_id'])->toBe(4);
    foreach (['siret', 'vat_number', 'tva_number', 'legal_status', 'iban', 'bic', 'billing_electronic_address'] as $field) {
        expect($company)->toHaveKey($field)->and($company[$field])->toBeNull();
    }
    expect($this->memory->rows['users'][3])->toBe($before['users'][3])
        ->and($this->memory->rows['companies'][3])->toBe($before['companies'][3])
        ->and($this->memory->rows['superpdp_connections'])->toBe($before['superpdp_connections'])
        ->and($this->memory->rows['supplier_invoices'])->toBe([])
        ->and($this->memory->operations)->toContain('begin', 'commit', 'insert users', 'insert companies');
});

it('rejects duplicate email or Tricatel without changing existing rows', function (string $duplicate) {
    if ($duplicate === 'email') {
        $this->memory->rows['users'][4] = ['id' => 4, 'email' => 'tricatel@sandbox.invalid'];
    } else {
        $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 99, 'company_name' => 'Tricatel'];
    }
    $before = $this->memory->rows;
    ($this->confirmed)()->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)->and($this->memory->operations)->toContain('rollback')
        ->and($this->memory->operations)->not->toContain('insert users', 'insert companies');
})->with(['email', 'company']);

it('rolls back the user when company insertion fails', function () {
    $this->memory->failCompany = true;
    $before = $this->memory->rows;
    ($this->confirmed)()->doesntExpectOutputToContain('tricatel@sandbox.invalid')
        ->doesntExpectOutputToContain('Offline-test-password-938!')
        ->doesntExpectOutputToContain('Sensitive SQL')->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)->and($this->memory->operations)->toContain('insert users', 'rollback')
        ->and($this->memory->operations)->not->toContain('commit');
});

it('refuses a wrong physical database account grants or storage engine before writes', function (string $failure) {
    match ($failure) {
        'database' => $this->memory->selectedDatabase = 'production',
        'account' => $this->memory->authenticatedUser = 'production_user@localhost',
        'grants' => $this->memory->grants[] = 'GRANT ALL PRIVILEGES ON *.* TO test_user',
        'engine' => $this->memory->engine = 'MyISAM',
        'lock' => $this->memory->exclusive = false,
    };
    $before = $this->memory->rows;
    ($this->confirmed)()->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)->and($this->memory->operations)->not->toContain('begin', 'insert users', 'insert companies');
})->with(['database', 'account', 'grants', 'engine', 'lock']);

it('rejects production or changed sandbox configuration before connection or prompting', function (string $failure) {
    match ($failure) {
        'environment' => $this->app->instance('env', 'production'),
        'config' => config(['database.connections.superpdp_sandbox.database' => 'production']),
    };
    $this->artisan('superpdp:sandbox-create-tricatel', ['--execute' => true])->assertExitCode(1);
    expect($this->memory->operations)->toBe([]);
})->with(['environment', 'config']);

it('refuses noninteractive execution', function () {
    $this->artisan('superpdp:sandbox-create-tricatel', ['--execute' => true, '--no-interaction' => true])->assertExitCode(1);
    expect($this->memory->operations)->toBe([]);
});

it('never reuses or duplicates a previously created test account on a second invocation', function () {
    ($this->confirmed)()->assertExitCode(0);
    $before = $this->memory->rows;
    ($this->confirmed)()->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)->and($this->memory->rows['users'])->toHaveCount(2)
        ->and($this->memory->rows['companies'])->toHaveCount(2);
});

it('masks all credential prompts without an echo fallback and defaults final confirmation to no', function () {
    $command = new CreateSandboxTricatel;
    $command->setLaravel(app());
    $input = new ArrayInput(['--execute' => true]);
    $input->setInteractive(true);
    $output = new BufferedOutput;
    $style = Mockery::mock(OutputStyle::class.'[askQuestion]', [$input, $output]);
    $answers = ['tricatel@sandbox.invalid', 'Offline-test-password-938!', 'Offline-test-password-938!'];
    $style->shouldReceive('askQuestion')->times(4)->andReturnUsing(function ($question) use (&$answers) {
        if ($question instanceof ConfirmationQuestion) {
            expect($question->getDefault())->toBeFalse();

            return false;
        }
        expect($question->isHidden())->toBeTrue()->and($question->isHiddenFallback())->toBeFalse();

        return array_shift($answers);
    });
    expect($command->run($input, $style))->toBe(0)->and($this->memory->operations)->toBe([])
        ->and($output->fetch())->not->toContain('tricatel@sandbox.invalid', 'Offline-test-password-938!');
});

it('rejects invalid credentials before confirmation or any SQL connection', function (string $email, string $password, string $repeat) {
    $this->artisan('superpdp:sandbox-create-tricatel', ['--execute' => true])
        ->expectsQuestion('Adresse email dédiée au compte Tricatel (saisie masquée)', $email)
        ->expectsQuestion('Mot de passe du compte de test (12 caractères minimum)', $password)
        ->expectsQuestion('Confirmer le mot de passe', $repeat)
        ->doesntExpectOutputToContain($password)->assertExitCode(1);
    expect($this->memory->operations)->toBe([]);
})->with([
    ['invalid-email', 'Offline-test-password-938!', 'Offline-test-password-938!'],
    ['tricatel@sandbox.invalid', 'short', 'short'],
    ['tricatel@sandbox.invalid', 'Offline-test-password-938!', 'Different-confirmation-938!'],
]);

it('audits the sandbox using only read queries without passwords locks transactions or creation', function () {
    $before = $this->memory->rows;
    expect(Artisan::call('superpdp:sandbox-create-tricatel', ['--diagnostic' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['status'])->toBe('passed')->and($report['mode'])->toBe('read_only')
        ->and($this->memory->rows)->toBe($before);
    foreach ($this->memory->operations as $query) {
        expect($query)->toMatch('/\A(?:SELECT|SHOW)\b/')->not->toContain('GET_LOCK', 'RELEASE_LOCK');
    }
});

it('accepts literal escaped underscores in SHOW GRANTS without expanding database privileges', function () {
    $this->memory->grants = ['GRANT USAGE ON *.* TO test_user',
        'GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test`.* TO test_user'];
    ($this->confirmed)()->assertExitCode(0);
    expect($this->memory->rows['companies'][4]['company_name'])->toBe('Tricatel');
});

it('identifies the exact failed read-only control without leaking account or SQL values', function (string $failure, string $stage, string $reason) {
    match ($failure) {
        'database' => $this->memory->selectedDatabase = 'PRIVATE_DATABASE',
        'account' => $this->memory->authenticatedUser = 'PRIVATE_ACCOUNT@PRIVATE_HOST',
        'grants' => $this->memory->grants[] = 'GRANT ALL PRIVILEGES ON `PRIVATE_DATABASE`.* TO PRIVATE_ACCOUNT IDENTIFIED BY PASSWORD PRIVATE_HASH',
        'wildcard' => $this->memory->grants[] = 'GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test%`.* TO test_user',
        'global' => $this->memory->grants[] = 'GRANT SELECT ON *.* TO test_user',
        'grant_option' => $this->memory->grants[] = 'GRANT USAGE ON *.* TO test_user WITH GRANT OPTION',
        'engine' => $this->memory->engine = 'MyISAM',
        'column' => $this->memory->missingColumn = true,
        'nullable' => $this->memory->legalNullable = false,
        'unique' => $this->memory->uniqueEmail = false,
        'foreign' => $this->memory->ownerForeignKey = false,
    };
    $before = $this->memory->rows;
    expect(Artisan::call('superpdp:sandbox-create-tricatel', ['--diagnostic' => true]))->toBe(1);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['stage'])->toBe($stage)->and($report['reason'])->toBe($reason)
        ->and($this->memory->rows)->toBe($before)
        ->and($this->memory->operations)->not->toContain('begin', 'insert users', 'insert companies')
        ->and($output)->not->toContain('PRIVATE_DATABASE', 'PRIVATE_ACCOUNT', 'PRIVATE_HOST', 'PRIVATE_HASH');
})->with([
    ['database', 'database.identity', 'database_mismatch'], ['account', 'database.identity', 'account_mismatch'],
    ['grants', 'database.grants', 'grants_outside_sandbox'], ['wildcard', 'database.grants', 'grants_outside_sandbox'],
    ['global', 'database.grants', 'grants_outside_sandbox'], ['engine', 'database.engines', 'transactional_tables_required'],
    ['grant_option', 'database.grants', 'grant_option_outside_sandbox'],
    ['column', 'database.constraints', 'required_column_missing'], ['nullable', 'database.constraints', 'legal_column_not_nullable'],
    ['unique', 'database.constraints', 'email_unique_missing'], ['foreign', 'database.constraints', 'owner_foreign_key_missing'],
]);

it('refuses execute combined with diagnostic before any connection', function () {
    $this->artisan('superpdp:sandbox-create-tricatel', ['--diagnostic' => true, '--execute' => true])->assertExitCode(1);
    expect($this->memory->operations)->toBe([]);
});

it('reports a connection driver code even when PDO supplies no errorInfo without printing its message', function () {
    $this->memory->failConnection = true;
    expect(Artisan::call('superpdp:sandbox-create-tricatel', ['--diagnostic' => true]))->toBe(1);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['stage'])->toBe('database.identity')->and($report['mysql_error_code'])->toBe(2002)
        ->and($output)->not->toContain('PRIVATE_ACCOUNT', 'PRIVATE_HASH');
});

it('accepts delegation strictly on the sandbox schema without using it', function (string $grant) {
    $this->memory->grants = ['GRANT USAGE ON *.* TO test_user', $grant];
    $before = $this->memory->rows;
    expect(Artisan::call('superpdp:sandbox-create-tricatel', ['--diagnostic' => true]))->toBe(0);
    expect($this->memory->rows)->toBe($before);
    ($this->confirmed)()->assertExitCode(0);
    expect($this->memory->rows['companies'][4]['company_name'])->toBe('Tricatel')
        ->and($this->memory->rows['companies'][3])->toBe($before['companies'][3]);
    foreach ($this->memory->operations as $operation) {
        expect($operation)->not->toMatch('/\A\s*(?:GRANT|REVOKE|ALTER USER|CREATE USER)\b/i');
    }
})->with([
    'Alwaysdata escaped schema' => ['GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test`.* TO test_user WITH GRANT OPTION'],
    'exact schema' => ['GRANT ALL PRIVILEGES ON `fabyjulien_simpledevis_test`.* TO test_user WITH GRANT OPTION'],
    'explicit privilege spelling' => ['GRANT SELECT, INSERT, GRANT OPTION ON `fabyjulien\_simpledevis\_test`.* TO test_user'],
]);

it('refuses global foreign wildcard or table-level delegation before any creation', function (string $grant) {
    $this->memory->grants[] = $grant;
    $before = $this->memory->rows;
    expect(Artisan::call('superpdp:sandbox-create-tricatel', ['--diagnostic' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['reason'])->toBe('grant_option_outside_sandbox');
    ($this->confirmed)()->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)
        ->and($this->memory->operations)->not->toContain('begin', 'insert users', 'insert companies');
})->with([
    ['GRANT ALL PRIVILEGES ON *.* TO test_user WITH GRANT OPTION'],
    ['GRANT USAGE ON *.* TO test_user WITH GRANT OPTION'],
    ['GRANT ALL PRIVILEGES ON `another_database`.* TO test_user WITH GRANT OPTION'],
    ['GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test%`.* TO test_user WITH GRANT OPTION'],
    ['GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test_backup`.* TO test_user WITH GRANT OPTION'],
    ['GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test`.`users` TO test_user WITH GRANT OPTION'],
    ['GRANT GRANT OPTION ON *.* TO test_user'],
]);

it('keeps engine constraint and duplicate guards with scoped delegation present', function (string $failure) {
    $this->memory->grants[] = 'GRANT ALL PRIVILEGES ON `fabyjulien\_simpledevis\_test`.* TO test_user WITH GRANT OPTION';
    match ($failure) {
        'engine' => $this->memory->engine = 'MyISAM',
        'constraint' => $this->memory->uniqueEmail = false,
        'duplicate' => $this->memory->rows['companies'][4] = ['id' => 4, 'user_id' => 99, 'company_name' => 'Tricatel'],
        'rollback' => $this->memory->failCompany = true,
    };
    $before = $this->memory->rows;
    ($this->confirmed)()->assertExitCode(1);
    expect($this->memory->rows)->toBe($before)->and($this->memory->operations)->not->toContain('commit');
})->with(['engine', 'constraint', 'duplicate', 'rollback']);
