<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** No HTTP route, SQL invoice, token refresh, persistent config change or emission operation. */
final class SuperPdpSandboxDocumentaryTest extends Command
{
    protected $signature = 'superpdp:sandbox-documentary-test
        {--execute : Explicitly authorize the two documentary API calls for this invocation}
        {--date= : Fictional scenario date (YYYY-MM-DD)}
        {--due-date= : Fictional scenario due date (YYYY-MM-DD)}';

    protected $description = 'CLI-only Burger Queen → Tricatel sandbox conversion and validation; never emission';

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli') {
            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $this->line('No SQL or API operation performed. Use --env=sandbox --execute --date=YYYY-MM-DD --due-date=YYYY-MM-DD only after network authorization.');

            return self::SUCCESS;
        }
        try {
            SandboxDatabaseIsolation::assertConfiguredScope();
            $date = $this->option('date');
            $dueDate = $this->option('due-date');
            foreach ([$date, $dueDate] as $value) {
                if (! is_string($value) || ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) === false
                    || $parsed->format('Y-m-d') !== $value) {
                    throw new RuntimeException;
                }
            }
            if ($dueDate < $date || ! app()->environment('sandbox') || config('app.env') !== 'sandbox'
                || config('services.superpdp.environment') !== 'sandbox'
                || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
                || config('database.default') !== 'superpdp_sandbox'
                || config('database.connections.superpdp_sandbox.database') !== 'fabyjulien_simpledevis_test'
                || config('database.connections.superpdp_sandbox.username') !== 'fabyjulien_simpledevis_test') {
                throw new RuntimeException;
            }

            // Read-only database transaction, rolled back before the documentary API calls.
            $db = DB::connection('superpdp_sandbox');
            $db->statement('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            try {
                if ($db->selectOne('SELECT DATABASE() AS selected_database')->selected_database !== 'fabyjulien_simpledevis_test') {
                    throw new RuntimeException;
                }
                $user = User::on('superpdp_sandbox')->select(['id'])->findOrFail(3);
                $company = Company::on('superpdp_sandbox')->whereKey(3)->where('user_id', 3)->firstOrFail();
                $connection = SuperPdpConnection::on('superpdp_sandbox')->whereKey(1)->where('company_id', 3)->firstOrFail();
            } finally {
                $db->rollBack();
            }
            // Set only the in-process guard. No login event, session save or remember token.
            auth()->setUser($user);
            $context = SandboxEmissionContext::forCompany($company);
            $scenario = SandboxInvoiceScenario::forContext($context, $company, $date, $dueDate);
            $gateway = SandboxCiiPreparationGateway::forScenario($context, $company, $connection, $scenario, authorizeNetwork: true);
            $result = app(InvoiceCiiPreparation::class)->convertSandboxScenario($context, $company, $scenario, $gateway);
            // Only statuses and hashes; no XML, personal data, tokens or response bodies.
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'documentary_test_completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Sandbox documentary test refused: check explicit dates, isolated profile and existing OAuth connection. No automatic retry or token refresh.');

            return self::FAILURE;
        }
    }
}
