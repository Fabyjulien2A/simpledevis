<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/** One-off local fixture only: no OAuth, HTTP, invoice, notification or production SQL. */
final class CreateSandboxTricatel extends Command
{
    protected $signature = 'superpdp:sandbox-create-tricatel
        {--execute : Permit creation only after hidden credential entry and explicit confirmation}
        {--diagnostic : Read-only sandbox SQL audit; no credentials requested, transaction, lock or creation}';

    protected $description = 'Create a separate test user and Tricatel in the isolated sandbox database only';

    private string $stage = 'scope.configuration';

    public function handle(): int
    {
        if ($this->option('diagnostic')) {
            if ($this->option('execute')) {
                $this->error('Employer --diagnostic sans --execute. Aucune opération effectuée.');

                return self::FAILURE;
            }
            try {
                $this->assertScope();
                $this->stage = 'database.connection';
                $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
                $dispatcher = $db->getEventDispatcher();
                $db->disableQueryLog();
                $db->flushQueryLog();
                $db->unsetEventDispatcher();
                try {
                    $this->preflight($db);
                    $this->stage = 'database.constraints';
                    $this->auditConstraints($db);
                } finally {
                    if ($dispatcher !== null) {
                        $db->setEventDispatcher($dispatcher);
                    }
                }
                $this->line(json_encode(['status' => 'passed', 'mode' => 'read_only',
                    'checks' => ['isolated_configuration', 'physical_database', 'authenticated_account', 'exclusive_grants', 'innodb_tables', 'required_columns_and_constraints'],
                    'creation' => 'not_attempted', 'historical_failure' => 'not_recorded'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            } catch (Throwable $exception) {
                $this->showDiagnostic($exception);

                return self::FAILURE;
            }
        }
        if (! $this->option('execute')) {
            $this->line('Fermé : aucune connexion SQL ni création. Utiliser --env=sandbox --execute puis confirmer dans le terminal.');

            return self::SUCCESS;
        }
        try {
            $this->assertScope();
            if (! $this->input->isInteractive()) {
                throw new RuntimeException('interactive_required');
            }
            // No CLI credential options, no echo fallback, no email in confirmation/output.
            $email = $this->secret('Adresse email dédiée au compte Tricatel (saisie masquée)', false);
            $password = $this->secret('Mot de passe du compte de test (12 caractères minimum)', false);
            $confirmation = $this->secret('Confirmer le mot de passe', false);
            if (! is_string($email) || ! is_string($password) || ! is_string($confirmation)) {
                throw new RuntimeException('credentials_invalid');
            }
            $email = mb_strtolower(trim($email));
            if (Validator::make(['email' => $email, 'password' => $password], [
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'password' => ['required', 'string', 'min:12', 'max:72'],
            ])->fails() || strlen($password) > 72 || ! hash_equals($password, $confirmation)) {
                throw new RuntimeException('credentials_invalid');
            }
            if (! $this->confirm('Confirmer la création d’un nouvel utilisateur et de Tricatel dans fabyjulien_simpledevis_test uniquement ?', false)) {
                $this->line('Annulé : aucune connexion SQL ni écriture.');

                return self::SUCCESS;
            }
            [$userId, $companyId] = $this->createFixture($email, $password);
            $this->info("Création sandbox terminée : utilisateur ID {$userId}, entreprise Tricatel ID {$companyId}. Identifiants légaux NULL. Aucune connexion SUPER PDP ni facture créée.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // No report(), logger(), exception chain, input value or SQL binding is printed.
            $message = match ($exception->getMessage()) {
                'interactive_required' => 'Un terminal interactif et une confirmation explicite sont requis.',
                'credentials_invalid' => 'Identifiants refusés : vérifier le format email, la longueur du mot de passe et sa confirmation.',
                'duplicate' => 'Création refusée : l’adresse est déjà utilisée ou une entreprise Tricatel existe déjà. Aucune donnée existante n’est modifiée.',
                'busy' => 'Une création Tricatel est déjà en cours. Aucune création concurrente autorisée.',
                default => 'Création refusée : vérifier le profil sandbox, la base et le compte dédiés, leurs droits limités, les tables transactionnelles et les contraintes. Aucune erreur SQL brute n’est affichée.',
            };
            $this->error($message);
            $this->showDiagnostic($exception);

            return self::FAILURE;
        } finally {
            unset($email, $password, $confirmation);
        }
    }

    private function assertScope(): void
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        if (PHP_SAPI !== 'cli' || ! app()->bound(SandboxDatabaseIsolation::class)
            || ! app('db') instanceof SandboxDatabaseManager
            || config('database.connections.superpdp_sandbox.username') !== SandboxDatabaseIsolation::DATABASE) {
            throw new RuntimeException('sandbox_required');
        }
        app(SandboxDatabaseIsolation::class)->assertCurrent();
    }

    private function createFixture(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password): array
    {
        $this->assertScope();
        $this->stage = 'database.connection';
        $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('external_transaction');
        }
        $dispatcher = $db->getEventDispatcher();
        $db->disableQueryLog();
        $db->flushQueryLog();
        $db->unsetEventDispatcher(); // Prevent query listeners from recording input bindings.
        $locked = false;
        try {
            $this->preflight($db);
            $this->stage = 'database.constraints';
            $this->auditConstraints($db);
            $this->stage = 'database.creation_lock';
            $lockName = 'simpledevis_test:create_tricatel';
            $locked = (int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->acquired === 1;
            if (! $locked) {
                throw new RuntimeException('busy');
            }

            return User::withoutEvents(fn () => $db->transaction(function () use ($email, $password) {
                $this->assertScope();
                $this->stage = 'creation.burger_queen';
                $burgerQueen = Company::on(SandboxDatabaseIsolation::CONNECTION)->whereKey(3)->firstOrFail();
                if ($burgerQueen->company_name !== 'Burger Queen' || ! $burgerQueen->user_id) {
                    throw new RuntimeException('burger_queen_scope');
                }
                $this->stage = 'creation.duplicates';
                if (User::on(SandboxDatabaseIsolation::CONNECTION)->where('email', $email)->first()
                    || Company::on(SandboxDatabaseIsolation::CONNECTION)->where('company_name', 'Tricatel')->first()) {
                    throw new RuntimeException('duplicate');
                }
                $this->stage = 'creation.user';
                $user = User::on(SandboxDatabaseIsolation::CONNECTION)->create([
                    'name' => 'Tricatel — utilisateur de test sandbox', 'email' => $email, 'password' => $password,
                ]); // User's existing hashed cast supplies password hashing.
                if ($user->id === $burgerQueen->user_id
                    || Company::on(SandboxDatabaseIsolation::CONNECTION)->where('user_id', $user->id)->first()) {
                    throw new RuntimeException('owner_scope');
                }
                $this->assertScope();
                $this->stage = 'creation.company';
                $company = Company::on(SandboxDatabaseIsolation::CONNECTION)->create([
                    'user_id' => $user->id, 'company_name' => 'Tricatel', 'country_code' => 'FR',
                    'siret' => null, 'vat_number' => null, 'tva_number' => null,
                    'legal_status' => null, 'iban' => null, 'bic' => null, 'billing_electronic_address' => null,
                ]);
                if ($company->id === 3 || Company::on(SandboxDatabaseIsolation::CONNECTION)->where('user_id', $user->id)->get()->count() !== 1) {
                    throw new RuntimeException('owner_scope');
                }

                return [$user->id, $company->id];
            }, 1)); // Single transaction, no automatic retry, no authentication/Registered event.
        } finally {
            try {
                if ($locked) {
                    try {
                        $db->selectOne('SELECT RELEASE_LOCK(?) AS released', ['simpledevis_test:create_tricatel']);
                    } catch (Throwable) {
                        // Closing our dedicated connection also releases its advisory lock.
                        // Do not misreport an already committed creation or mask a rollback.
                        $db->disconnect();
                    }
                }
            } finally {
                if ($dispatcher !== null) {
                    $db->setEventDispatcher($dispatcher);
                }
            }
        }
    }

    private function preflight(Connection $db): void
    {
        $this->stage = 'database.identity';
        $session = $db->selectOne('SELECT DATABASE() AS selected_database, CURRENT_USER() AS authenticated_user');
        if ($session->selected_database !== SandboxDatabaseIsolation::DATABASE) {
            throw new RuntimeException('database_mismatch');
        }
        if (explode('@', $session->authenticated_user, 2)[0] !== SandboxDatabaseIsolation::DATABASE) {
            throw new RuntimeException('account_mismatch');
        }
        $this->stage = 'database.grants';
        $grants = $db->select('SHOW GRANTS FOR CURRENT_USER');
        if ($grants === []) {
            throw new RuntimeException('grants_unresolved');
        }
        foreach ($grants as $row) {
            $grant = array_values((array) $row)[0] ?? '';
            if (! is_string($grant) || ! preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $grant, $match)) {
                throw new RuntimeException('grants_unresolved');
            }
            // SHOW GRANTS may escape literal underscores in a database name.
            // Decode only that escape; do not accept another schema or '%' wildcard.
            $target = str_replace(['`', '\\_'], ['', '_'], trim($match[2]));
            $hasGrantOption = preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $grant) === 1
                || preg_match('/(?:\A|,)\s*GRANT OPTION\s*(?:,|\z)/i', trim($match[1])) === 1;
            // Alwaysdata may retain delegation on this one exact sandbox schema.
            // Accept its presence only here; this command never exercises delegation.
            if ($hasGrantOption && $target !== SandboxDatabaseIsolation::DATABASE.'.*') {
                throw new RuntimeException('grant_option_outside_sandbox');
            }
            if (! ((strtoupper(trim($match[1])) === 'USAGE' && $target === '*.*')
                || $target === SandboxDatabaseIsolation::DATABASE.'.*')) {
                throw new RuntimeException('grants_outside_sandbox');
            }
        }
        $this->stage = 'database.engines';
        $tables = $db->select("SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'companies')");
        $engines = [];
        foreach ($tables as $table) {
            $engines[$table->table_name] = strtoupper($table->engine ?? '');
        }
        if (count($engines) !== 2 || ($engines['users'] ?? null) !== 'INNODB' || ($engines['companies'] ?? null) !== 'INNODB') {
            throw new RuntimeException('transactional_tables_required');
        }
    }

    private function auditConstraints(Connection $db): void
    {
        $columns = $db->select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'companies')");
        $schema = [];
        foreach ($columns as $column) {
            $schema[$column->table_name][$column->column_name] = $column->is_nullable;
        }
        foreach (['users' => ['id', 'name', 'email', 'password', 'created_at', 'updated_at'],
            'companies' => ['id', 'user_id', 'company_name', 'country_code', 'siret', 'vat_number', 'tva_number', 'legal_status', 'iban', 'bic', 'billing_electronic_address', 'created_at', 'updated_at']] as $table => $required) {
            foreach ($required as $column) {
                if (! isset($schema[$table][$column])) {
                    throw new RuntimeException('required_column_missing');
                }
            }
        }
        foreach (['siret', 'vat_number', 'tva_number', 'legal_status', 'iban', 'bic', 'billing_electronic_address'] as $column) {
            if ($schema['companies'][$column] !== 'YES') {
                throw new RuntimeException('legal_column_not_nullable');
            }
        }
        $indexes = $db->select("SELECT INDEX_NAME AS index_name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        $unique = [];
        foreach ($indexes as $index) {
            if ((int) $index->non_unique === 0) {
                $unique[$index->index_name][] = $index->column_name;
            }
        }
        if (! in_array(['email'], $unique, true)) {
            throw new RuntimeException('email_unique_missing');
        }
        $foreignKeys = $db->select("SELECT COLUMN_NAME AS column_name, REFERENCED_TABLE_SCHEMA AS referenced_schema, REFERENCED_TABLE_NAME AS referenced_table, REFERENCED_COLUMN_NAME AS referenced_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
        foreach ($foreignKeys as $key) {
            if ($key->referenced_schema === SandboxDatabaseIsolation::DATABASE && $key->referenced_table === 'users' && $key->referenced_column === 'id') {
                return;
            }
        }
        throw new RuntimeException('owner_foreign_key_missing');
    }

    private function showDiagnostic(Throwable $exception): void
    {
        $known = [
            'database_mismatch' => 'La base sélectionnée ne correspond pas à la base sandbox attendue. Vérifier le profil isolé ; ne pas modifier les identifiants automatiquement.',
            'account_mismatch' => 'Le compte SQL authentifié ne correspond pas au compte dédié attendu. Vérifier le compte utilisé sans afficher son secret.',
            'grants_unresolved' => 'Les privilèges ne sont pas interprétables ou comportent une délégation interdite. Faire vérifier les droits du seul compte sandbox.',
            'grant_option_outside_sandbox' => 'Une capacité de délégation vise une portée globale ou différente de la base sandbox exacte. Création interdite ; conserver le contrôle et faire examiner les droits du compte.',
            'grants_outside_sandbox' => 'Un privilège vise une autre base ou une portée globale interdite. Corriger les droits côté administration ; ne pas assouplir le contrôle.',
            'transactional_tables_required' => 'users ou companies est absent ou non InnoDB. Vérifier le schéma sandbox avant toute création.',
            'required_column_missing' => 'Une colonne nécessaire à la création manque dans users ou companies. Comparer le schéma sandbox aux migrations existantes sans migration automatique.',
            'legal_column_not_nullable' => 'Un identifiant légal ne peut pas recevoir NULL dans le schéma actuel. Faire examiner uniquement cette contrainte sandbox.',
            'email_unique_missing' => 'La contrainte unique sur users.email est absente. Faire vérifier le schéma sandbox avant toute création.',
            'owner_foreign_key_missing' => 'La relation companies.user_id vers users.id de la même base manque. Faire vérifier la contrainte de propriété.',
            'burger_queen_scope' => 'L’entreprise locale 3 ne correspond pas au scénario Burger Queen attendu. Vérifier le rattachement existant.',
            'owner_scope' => 'Le contrôle d’unicité ou de séparation du propriétaire a échoué ; la transaction doit être annulée.',
            'duplicate' => 'Un utilisateur avec cet email ou une entreprise Tricatel existe déjà. Ne pas réutiliser ou écraser ces lignes.',
            'busy' => 'Le verrou de création est déjà détenu. Attendre la fin de l’autre opération.',
        ];
        $reason = array_key_exists($exception->getMessage(), $known) ? $exception->getMessage() : 'step_failed';
        $driverCode = $exception instanceof QueryException || $exception instanceof \PDOException
            ? ($exception->errorInfo[1] ?? null) : null;
        if ($driverCode === null && ($exception instanceof QueryException || $exception instanceof \PDOException)
            && ctype_digit((string) $exception->getCode())) {
            $driverCode = (int) $exception->getCode();
        }
        $safeDriver = in_array((int) $driverCode, [1044, 1045, 1048, 1054, 1062, 1142, 1146, 1364, 1406, 1452, 2002, 2003], true) ? (int) $driverCode : null;
        $sqlActions = [
            1044 => 'Accès à la base refusé : faire vérifier les droits du seul compte sandbox.',
            1045 => 'Authentification SQL refusée : faire vérifier le compte dédié sans afficher ni changer automatiquement ses identifiants.',
            1048 => 'Une colonne obligatoire refuse NULL : examiner les contraintes de la table sandbox.',
            1054 => 'Une colonne attendue est absente : comparer le schéma sandbox aux migrations existantes.',
            1062 => 'Une contrainte unique refuse un doublon : ne pas réutiliser ni écraser une ligne existante.',
            1142 => 'Une permission SQL requise est absente : faire vérifier uniquement les droits sandbox.',
            1146 => 'Une table requise est absente : vérifier le schéma sandbox sans migration automatique.',
            1364 => 'Une colonne obligatoire sans valeur par défaut manque à l’insertion : examiner le schéma sandbox.',
            1406 => 'Une valeur dépasse la taille de colonne : examiner le schéma sans afficher la valeur.',
            1452 => 'La clé étrangère de propriété est invalide : vérifier le lien utilisateur–entreprise.',
            2002 => 'La connexion SQL n’a pas pu être établie : vérifier l’accès réseau/socket au serveur sandbox ; aucun contrôle des privilèges ou du schéma n’a encore abouti.',
            2003 => 'Le serveur SQL sandbox n’est pas joignable : vérifier l’accès réseau avant tout contrôle du schéma.',
        ];
        $this->line(json_encode(['status' => 'failed', 'stage' => $this->stage, 'reason' => $reason,
            'error_type' => $exception instanceof QueryException ? 'QueryException' : ($exception instanceof \PDOException ? 'PDOException' : ($exception instanceof RuntimeException ? 'RuntimeException' : 'Throwable')),
            'mysql_error_code' => $safeDriver,
            'explanation_and_action' => $known[$reason] ?? $sqlActions[$safeDriver] ?? 'L’étape indiquée a échoué. Vérifier le profil, les permissions ou le schéma sandbox ; aucun message brut, requête, email ou secret n’est affiché.',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
