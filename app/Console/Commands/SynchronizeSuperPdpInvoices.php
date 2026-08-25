<?php

namespace App\Console\Commands;

use App\Models\SuperPdpConnection;
use App\Services\SuperPdp\SuperPdpInvoiceService;
use Illuminate\Console\Command;
use Throwable;

class SynchronizeSuperPdpInvoices extends Command
{
    /**
     * Nom de la commande Artisan.
     */
    protected $signature = 'superpdp:sync-invoices';

    /**
     * Description affichée dans php artisan list.
     */
    protected $description =
        'Synchronise automatiquement les factures reçues depuis SUPER PDP';

    public function handle(
        SuperPdpInvoiceService $invoiceService
    ): int {
        $connections = SuperPdpConnection::query()
            ->where('status', 'connected')
            ->whereNotNull('access_token')
            ->get();

        if ($connections->isEmpty()) {
            $this->info(
                'Aucune connexion SUPER PDP active.'
            );

            return self::SUCCESS;
        }

        $totalSynchronized = 0;
        $failedConnections = 0;

        foreach ($connections as $connection) {
            try {
                $count = $invoiceService->synchronize(
                    $connection
                );

                $totalSynchronized += $count;

                $this->info(
                    sprintf(
                        'Entreprise %s : %d facture(s) synchronisée(s).',
                        $connection->company_id,
                        $count
                    )
                );
            } catch (Throwable $exception) {
                $failedConnections++;

                report($exception);

                logger()->error(
                    'Échec de la synchronisation automatique SUPER PDP.',
                    [
                        'connection_id' => $connection->id,
                        'company_id' => $connection->company_id,
                        'exception' => $exception->getMessage(),
                    ]
                );

                $this->error(
                    sprintf(
                        'Entreprise %s : synchronisation échouée.',
                        $connection->company_id
                    )
                );
            }
        }

        $this->newLine();

        $this->info(
            sprintf(
                'Synchronisation terminée : %d facture(s), %d échec(s).',
                $totalSynchronized,
                $failedConnections
            )
        );

        return $failedConnections > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}