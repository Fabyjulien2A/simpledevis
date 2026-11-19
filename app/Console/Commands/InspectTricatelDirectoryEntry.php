<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxInvoiceProfile;
use App\Services\SuperPdp\Verification\TricatelDirectoryEntryDiagnostic;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class InspectTricatelDirectoryEntry extends Command
{
    protected $signature = 'superpdp:inspect-tricatel-directory-entry
        {--entry-id= : Entry ID approved for this single GET, checked against local evidence}
        {--execute : Explicitly authorize at most one sandbox directory detail GET}';

    protected $description = 'Read only the evidenced Tricatel directory entry, without OAuth refresh';

    public function handle(TricatelDirectoryEntryDiagnostic $diagnostic): int
    {
        if (! $this->option('execute')) {
            $this->line('No SQL or API operation. Explicit --execute and --entry-id required.');
            return self::SUCCESS;
        }
        $previous = null;
        $restore = false;
        try {
            if (PHP_SAPI !== 'cli' || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $this->option('entry-id'))) {
                throw new RuntimeException;
            }
            SandboxDatabaseIsolation::assertConfiguredScope();
            $previous = auth()->user();
            $restore = true;
            $db = DB::connection(SandboxDatabaseIsolation::CONNECTION);
            if ($db->transactionLevel() !== 0) {
                throw new RuntimeException;
            }
            $db->statement('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            try {
                if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== SandboxDatabaseIsolation::DATABASE) {
                    throw new RuntimeException;
                }
                $actor = User::on(SandboxDatabaseIsolation::CONNECTION)->findOrFail(4);
                $owner = User::on(SandboxDatabaseIsolation::CONNECTION)->findOrFail(3);
                $invoice = Invoice::on(SandboxDatabaseIsolation::CONNECTION)->where('user_id', 3)->findOrFail(80);
                if (! SandboxInvoiceProfile::is($invoice)) {
                    throw new RuntimeException;
                }
                $company = Company::on(SandboxDatabaseIsolation::CONNECTION)->where('user_id', 4)->findOrFail(4);
                $connection = $company->superPdpConnection()->sole();
                $report = app(VerificationAuditStore::class)->read($owner, $invoice, TricatelDirectoryEntryDiagnostic::EVIDENCE);
                $entryId = $diagnostic->entryId($report);
                if ((string) $entryId !== $this->option('entry-id')) {
                    throw new RuntimeException;
                }
            } finally {
                $db->rollBack();
            }
            auth()->setUser($actor); // In memory only, no login or session write.
            $result = $diagnostic->read($actor, $company, $connection, $entryId, authorizeNetwork: true);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable) {
            // Never print response bodies, exception messages, headers or OAuth credentials.
            $this->error('Lecture refusee : preuve, contexte, jeton ou reponse indisponible/incompatible. Aucun retry ni renouvellement.');
            return self::FAILURE;
        } finally {
            if ($restore) {
                $previous !== null ? auth()->setUser($previous) : auth()->forgetUser();
            }
        }
    }
}
