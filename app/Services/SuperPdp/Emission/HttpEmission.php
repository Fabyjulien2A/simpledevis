<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxDatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** HTTP adapter only. OutgoingEmission remains the sole reservation/dispatch engine. */
final class HttpEmission
{
    public function __construct(private readonly OutgoingEmission $outgoing,
        private readonly EmissionTransport $transport,
        private readonly SandboxEmissionPrerequisites $prerequisites,
        private readonly SandboxEmissionAuthorization $authorization) {}

    public function available(): bool
    {
        return $this->simulated() || self::sandboxAvailable();
    }

    public function simulated(): bool
    {
        return config('superpdp_emission.http_simulation_enabled') === true
            && app()->environment('testing') && config('app.env') === 'testing'
            && config('database.default') === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:'
            && ! $this->transport instanceof SandboxSdkEmissionTransport
            && $this->transport->mode() === 'test';
    }

    private static function sandboxAvailable(): bool
    {
        if (config('superpdp_emission.http_sandbox_enabled') !== true
            || ! app()->environment('sandbox') || config('app.env') !== 'sandbox'
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
            || ! app()->bound(SandboxDatabaseIsolation::class)) {
            return false;
        }
        try {
            SandboxDatabaseIsolation::assertConfiguredScope();

            return app('db') instanceof SandboxDatabaseManager;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function assertSandboxRuntime(): void
    {
        if (! self::sandboxAvailable()) {
            throw ValidationException::withMessages(['emission' => 'Transport HTTP sandbox fermé.']);
        }
    }

    private function engine(User $actor, Invoice $invoice, string $reference, ?HttpEmissionConfirmation $confirmation = null): OutgoingEmission
    {
        return $this->simulated() ? $this->outgoing
            : app(SandboxEmissionFactory::class)->forHttpInvoice($actor, $invoice, $reference, $confirmation);
    }

    public function reserve(User $actor, Invoice $invoice, string $reference): array
    {
        $this->assertAvailable();
        $this->assertIsolatedInvoice($invoice);
        $binding = $this->prerequisites->binding($actor, $invoice, $reference);
        $approval = $this->authorization->approval();
        $attempt = $this->engine($actor, $invoice, $reference)->reserve($actor, $invoice, $reference);

        return $invoice->getConnection()->transaction(function () use ($invoice, $actor, $attempt, $binding, $approval) {
            $attempt = $attempt->newQuery()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            // One authorization per UUID. Repeated reservation cannot extend its lifetime.
            $existing = HttpEmissionConfirmation::grant($attempt);
            if ($existing !== null || $attempt->status !== 'pending' || $attempt->transport_started_at !== null) {
                return ['attempt' => $attempt, 'token' => null, 'expires_at' => $existing['expires_at'] ?? null];
            }
            $token = Str::random(64);
            $expires = min(now()->addMinutes(5)->timestamp, $approval['expires_at']);
            $attempt->history = [...$attempt->history, [
                'status' => 'pending', 'reason' => 'http_authorized', 'at' => now()->toIso8601String(),
                'attempt_reference' => $attempt->reference, 'user_id' => $actor->id, 'invoice_id' => $invoice->id,
                'cii_sha256' => $attempt->cii_sha256, 'binding' => $binding,
                'token_sha256' => hash('sha256', $token), 'expires_at' => $expires,
            ]];
            $attempt->save();

            return ['attempt' => $attempt, 'token' => $token, 'expires_at' => $expires];
        });
    }

    public function confirm(User $actor, Invoice $invoice, string $uuid, string $token): ElectronicInvoiceAttempt
    {
        $this->assertAvailable();
        $this->assertIsolatedInvoice($invoice);
        abort_unless($actor->exists && $invoice->user_id === $actor->id, 403);
        // Scope the browser UUID through the owned invoice; never accept a registry ID.
        $record = ElectronicInvoice::on($invoice->getConnectionName())->where('invoice_id', $invoice->id)->sole();
        $attempt = $record->attempts()->where('reference', $uuid)->firstOrFail();
        $grant = HttpEmissionConfirmation::grant($attempt);
        abort_unless($grant !== null && $token !== '' && hash_equals($grant['token_sha256'], hash('sha256', $token)), 403);
        $confirmation = new HttpEmissionConfirmation($actor, $uuid, $token, $this->prerequisites, $this->authorization);
        $engine = $this->engine($actor, $invoice, $record->document_reference, $confirmation);
        $engine->recoverInterrupted($actor, $record, $attempt->id);

        return $engine->dispatch($actor, $record, $attempt->id,
            function ($locked, $current, $stored) use ($confirmation, $uuid) {
                $confirmation->check($locked, $current, $stored);
                $current->history = [...$current->history, ['status' => 'pending', 'reason' => 'http_confirmed',
                    'attempt_reference' => $uuid, 'at' => now()->toIso8601String()]];
                $current->save();
            });
    }

    /** Local recovery only; no dispatch, reconciliation or network lookup. */
    public function review(User $actor, Invoice $invoice): ElectronicInvoiceAttempt
    {
        $this->assertAvailable();
        $this->assertIsolatedInvoice($invoice);
        abort_unless($actor->exists && $invoice->user_id === $actor->id, 403);
        $record = ElectronicInvoice::on($invoice->getConnectionName())->where('invoice_id', $invoice->id)->sole();
        $attempt = $record->attempts()->orderByDesc('number')->firstOrFail();

        return $this->engine($actor, $invoice, $record->document_reference)->recoverInterrupted($actor, $record, $attempt->id);
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['emission' => 'Transport HTTP fermé. Aucun dépôt effectué.']);
        }
    }

    private function assertIsolatedInvoice(Invoice $invoice): void
    {
        if (! $this->simulated()) {
            self::assertSandboxRuntime();
            abort_unless($invoice->getConnection()->getName() === SandboxDatabaseIsolation::CONNECTION
                && $invoice->getConnection()->getDatabaseName() === SandboxDatabaseIsolation::DATABASE, 403);

            return;
        }
        abort_unless($invoice->getConnection()->getDriverName() === 'sqlite'
            && $invoice->getConnection()->getDatabaseName() === ':memory:', 403);
    }
}
