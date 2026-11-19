<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\Verification\TricatelPublicationVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** CLI seulement, sans route HTTP ni modification persistante de configuration. */
class VerifyTricatelPublication extends Command
{
    protected $signature = 'superpdp:verify-tricatel-publication
        {--diagnose : Lire les prerequis locaux seulement, sans API ni rapport enregistre}
        {--execute : Autoriser une seule verification reelle, au plus deux GET, sans renouvellement OAuth}';

    protected $description = 'Vérifier la publication de l’adresse Tricatel avec sa propre connexion OAuth sandbox';

    public function handle(): int
    {
        if ($this->option('diagnose') && $this->option('execute')) {
            $this->error('Choisir --diagnose ou --execute, jamais les deux. Aucune opération effectuée.');

            return self::FAILURE;
        }
        if (! $this->option('execute') && ! $this->option('diagnose')) {
            $this->line('Aucune lecture SQL, API ou écriture de rapport. Utiliser --env=sandbox --execute après autorisation explicite.');

            return self::SUCCESS;
        }
        if (PHP_SAPI !== 'cli') {
            return self::FAILURE;
        }
        $previousUser = null;
        $restoreAuth = false;
        $stage = 'sandbox_scope';
        try {
            SandboxDatabaseIsolation::assertConfiguredScope();
            $stage = 'local_authentication_context';
            $previousUser = auth()->user();
            $restoreAuth = true;
            $stage = 'sandbox_read_only_transaction';
            $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
            $db->statement('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            try {
                if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                    throw new \RuntimeException;
                }
                $stage = 'local_user_4';
                $actor = User::on(SandboxDatabaseIsolation::CONNECTION)->select(['id'])->findOrFail(4);
                $stage = 'local_company_4_owner_4';
                $company = Company::on(SandboxDatabaseIsolation::CONNECTION)->whereKey(4)->where('user_id', 4)->firstOrFail();
                $stage = 'tricatel_connection_selection';
                $connections = SuperPdpConnection::on(SandboxDatabaseIsolation::CONNECTION)->where('company_id', 4)->get();
                if ($connections->count() !== 1) {
                    throw new \RuntimeException;
                }
                $connection = $connections->sole();
            } finally {
                $db->rollBack();
            }
            // Authentification en mémoire seulement : aucun login, événement ou enregistrement de session.
            auth()->setUser($actor);
            $stage = 'read_only_oauth_prerequisites';
            if ($this->option('diagnose')) {
                $diagnostic = app(TricatelPublicationVerification::class)->diagnose($actor, $company, $connection);
                $this->line(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return $diagnostic['status'] === 'prerequisites_passed' ? self::SUCCESS : self::FAILURE;
            }
            $report = app(TricatelPublicationVerification::class)->verify($actor, $company, $connection, authorizeNetwork: true);
            $this->line($report['publication_confirmed'] ? 'Adresse publiée confirmée.' : 'Adresse publiée non confirmée.');
            $this->line('Routage depuis Burger Queen : non vérifié. Aucune émission.');
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['publication_confirmed'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->option('diagnose')) {
                $diagnostic = ['status' => 'unavailable', 'reason' => 'local_diagnostic_failed', 'stage' => $stage,
                    'api_called' => false, 'report_saved' => false, 'token_refreshed' => false];
                $databaseError = $exception;
                while ($databaseError !== null && ! $databaseError instanceof \PDOException) {
                    $databaseError = $databaseError->getPrevious();
                }
                if ($databaseError instanceof \PDOException) {
                    $state = $databaseError->errorInfo[0] ?? (string) $databaseError->getCode();
                    $number = $databaseError->errorInfo[1] ?? null;
                    $diagnostic['sqlstate'] = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) ? $state : null;
                    $diagnostic['driver_error_code'] = is_int($number) ? $number : null;
                }
                $this->line(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }
            $this->error('Vérification refusée ou rapport privé indisponible. Vérifier le sandbox isolé et la connexion existante de Tricatel. Aucun renouvellement ni retry automatique.');

            return self::FAILURE;
        } finally {
            if ($restoreAuth) {
                if ($previousUser !== null) {
                    auth()->setUser($previousUser);
                } else {
                    auth()->forgetUser();
                }
            }
        }
    }
}
