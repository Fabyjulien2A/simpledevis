<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\ElectronicInvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Registre transactionnel sortant. Aucun parcours de production n'est activé. */
final class OutgoingEmission
{
    public function __construct(private readonly EmissionPrerequisites $gate, private readonly EmissionTransport $transport) {}

    public function reserve(User $actor, Invoice $invoice, string $reference, bool $retryRejected = false): ElectronicInvoiceAttempt
    {
        EmissionDocument::assertReference($reference, $actor->id, $invoice->id);
        $this->assertTestTransport();
        $this->outsideTransaction($invoice);

        return $invoice->getConnection()->transaction(function () use ($actor, $invoice, $reference, $retryRejected) {
            $stored = $this->lockedInvoice($actor, $invoice);
            $document = $this->gate->authorize($actor, $stored, $reference);
            $this->matchDocument($actor, $stored, $document, $reference);
            $record = ElectronicInvoice::on($stored->getConnectionName())->where('dispatch_key', $this->key($stored))->lockForUpdate()->first();
            if (! $record) {
                if (ElectronicInvoice::on($stored->getConnectionName())->where('invoice_id', $stored->id)->exists()) {
                    $this->deny('historique électronique existant à examiner avant toute nouvelle émission');
                }
                $record = new ElectronicInvoice;
                $record->setConnection($stored->getConnectionName());
                $record->forceFill([
                    'invoice_id' => $stored->id, 'dispatch_key' => $this->key($stored),
                    'local_reference' => (string) Str::uuid(), 'execution_mode' => 'test',
                    'environment' => $document->environment, 'provider_company_id' => $document->companyId,
                    'document_reference' => $reference, 'cii_sha256' => $document->sha256, 'status' => 'pending',
                ])->save();
            }
            $this->matchRecord($record, $document);
            $latest = $record->attempts()->orderByDesc('number')->lockForUpdate()->first();
            if ($latest) {
                if ($latest->status !== 'rejected' || ! $retryRejected) {
                    return $latest; // Double clic : même tentative, jamais un nouveau dépôt.
                }
                if ($record->provider_invoice_id !== null) {
                    $this->deny('identifiant distant déjà associé');
                }
            }
            $attempt = $record->attempts()->create([
                'number' => ($latest?->number ?? 0) + 1, 'reference' => (string) Str::uuid(),
                'status' => 'pending', 'cii_sha256' => $document->sha256,
                'history' => [['status' => 'pending', 'reason' => 'reserved', 'at' => now()->toIso8601String()]],
            ]);
            $record->forceFill(['status' => 'pending', 'last_error' => null])->save();

            return $attempt;
        });
    }

    public function dispatch(User $actor, ElectronicInvoice $record, int $attemptId): ElectronicInvoiceAttempt
    {
        $this->assertTestTransport();
        $invoice = $this->invoiceFor($record);
        $this->outsideTransaction($invoice);
        $claim = $this->withRecord($actor, $record, function ($locked, $stored) use ($actor, $attemptId) {
            $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'pending' || $attempt->transport_started_at !== null) {
                return ['attempt' => $attempt];
            }
            if ((int) $locked->attempts()->max('number') !== $attempt->number) {
                $this->deny('tentative périmée');
            }
            try {
                $document = $this->gate->authorize($actor, $stored, $locked->document_reference);
                $this->matchDocument($actor, $stored, $document, $locked->document_reference);
                $this->matchRecord($locked, $document);
            } catch (\PDOException $exception) {
                // QueryException hérite de PDOException. Un deadlock peut avoir déjà
                // annulé la transaction côté serveur : ne plus écrire dans ce callback.
                throw $exception;
            } catch (Throwable) {
                $this->transition($locked, $attempt, 'rejected', 'local_precondition_failed');

                return ['attempt' => $attempt]; // Refus local certain, aucun appel commencé.
            }
            $token = (string) Str::uuid();
            $attempt->forceFill(['claim_token' => $token, 'transport_started_at' => now(),
                'history' => [...$attempt->history, ['status' => 'pending', 'reason' => 'transport_claimed', 'at' => now()->toIso8601String()]],
            ])->save();

            return ['attempt' => $attempt, 'token' => $token, 'document' => $document];
        });
        if (! isset($claim['token'])) {
            return $claim['attempt'];
        }
        // La réservation/prise en charge est COMMITÉE avant l'appel. Aucun retry ni lease réutilisable.
        try {
            $response = $this->transport->send($claim['document'], $claim['attempt']->reference);
            $outcome = $this->classify($response, $claim['document']->companyId, $claim['attempt']->reference);
        } catch (Throwable) {
            $outcome = ['status' => 'unknown', 'error' => 'transport_uncertain', 'http' => null, 'remote' => null];
        }

        try {
            return $this->withRecord($actor, $record, function ($locked) use ($attemptId, $claim, $outcome) {
                $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
                if ($attempt->claim_token !== $claim['token'] || $attempt->status !== 'pending') {
                    return $attempt;
                }
                if ($outcome['remote'] !== null && ! $this->bindRemote($locked, $outcome['remote'])) {
                    $outcome = ['status' => 'unknown', 'error' => 'remote_binding_conflict', 'http' => $outcome['http'], 'remote' => null];
                }
                $this->transition($locked, $attempt, $outcome['status'], $outcome['error'], $outcome['http'], $outcome['remote']);

                return $attempt;
            });
        } catch (Throwable) {
            // Une réponse connue dont l'écriture a échoué reste incertaine localement.
            return $this->withRecord($actor, $record, function ($locked) use ($attemptId, $claim) {
                $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
                if ($attempt->claim_token === $claim['token'] && $attempt->status === 'pending') {
                    $this->transition($locked, $attempt, 'unknown', 'result_persistence_uncertain');
                }

                return $attempt;
            });
        }
    }

    /** Un crash après la prise en charge ne remet JAMAIS la tentative dans la file d'envoi. */
    public function recoverInterrupted(User $actor, ElectronicInvoice $record, int $attemptId): ElectronicInvoiceAttempt
    {
        $this->assertTestTransport();

        return $this->withRecord($actor, $record, function ($locked) use ($attemptId) {
            $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status === 'pending' && $attempt->transport_started_at !== null
                && $attempt->transport_started_at->lte(now()->subMinutes(5))) {
                $this->transition($locked, $attempt, 'unknown', 'interrupted_dispatch');
            }

            return $attempt;
        });
    }

    /** Seule une preuve POSITIVE permet de résoudre l'inconnu, jamais l'absence dans une liste. */
    public function reconcile(User $actor, ElectronicInvoice $record, int $attemptId, int $remoteId): ElectronicInvoiceAttempt
    {
        $this->assertTestTransport();
        $invoice = $this->invoiceFor($record);
        $this->outsideTransaction($invoice);
        $snapshot = $this->withRecord($actor, $record, function ($locked) use ($attemptId) {
            $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();

            return ['record' => $locked, 'attempt' => $attempt];
        });
        $attempt = $snapshot['attempt'];
        if ($attempt->status !== 'unknown') {
            return $attempt;
        }
        try {
            $remote = $this->transport->lookup($remoteId);
            $valid = $this->remoteMatches($remote, $snapshot['record']->provider_company_id, $attempt->reference)
                && $remote['id'] === $remoteId
                && hash_equals($attempt->cii_sha256, hash('sha256', $this->transport->original($remoteId)));
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            return $attempt;
        }

        return $this->withRecord($actor, $record, function ($locked) use ($attemptId, $remoteId) {
            $attempt = $locked->attempts()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'unknown' || ! $this->bindRemote($locked, $remoteId)) {
                return $attempt;
            }
            $attempt->reconciliation = ['result' => 'positive_remote_match', 'remote_id' => $remoteId,
                'cii_sha256' => $attempt->cii_sha256, 'at' => now()->toIso8601String(), 'mode' => 'test'];
            $this->transition($locked, $attempt, 'accepted', 'reconciled_positive', null, $remoteId);

            return $attempt;
        });
    }

    /** Une page par appel ; le curseur ne progresse qu'après validation de TOUTE la page. */
    public function syncEvents(User $actor, ElectronicInvoice $record): array
    {
        $this->assertTestTransport();
        $this->outsideTransaction($this->invoiceFor($record));
        $snapshot = $this->withRecord($actor, $record, fn ($locked) => $locked);
        if ($snapshot->status !== 'accepted' || $snapshot->provider_invoice_id === null) {
            $this->deny('dépôt non associé à un identifiant distant');
        }
        $page = $this->transport->events($snapshot->provider_invoice_id, $snapshot->event_cursor);
        if (! is_array($page['data'] ?? null) || ! array_is_list($page['data']) || ! is_bool($page['has_after'] ?? null)
            || ($page['has_after'] && $page['data'] === [])) {
            $this->deny('page d’événements incomplète');
        }
        $previous = $snapshot->event_cursor ?? 0;
        foreach ($page['data'] as $event) {
            if (! is_array($event) || ! is_int($event['id'] ?? null) || $event['id'] <= $previous) {
                $this->deny('ordre des événements invalide');
            }
            $previous = $event['id'];
        }
        $count = $this->withRecord($actor, $record, function ($locked) use ($actor, $page, $previous) {
            $count = $this->applyEvents($actor, $locked, $page['data']);
            $locked->event_cursor = max($locked->event_cursor ?? 0, $previous);
            $locked->save();

            return $count;
        });

        return ['processed' => $count, 'has_after' => $page['has_after']];
    }

    public function applyEvents(User $actor, ElectronicInvoice $record, array $events): int
    {
        $this->assertTestTransport();
        if (! array_is_list($events)) {
            $this->deny('liste d’événements attendue');
        }

        return $this->withRecord($actor, $record, function ($locked) use ($events) {
            if ($locked->status !== 'accepted' || $locked->provider_invoice_id === null) {
                $this->deny('dépôt non accepté');
            }
            $count = 0;
            foreach ($events as $event) {
                $event = $this->validateEvent($event, $locked->provider_invoice_id);
                $key = $locked->environment.':'.$locked->provider_company_id.':'.$event['id'];
                $hash = hash('sha256', $this->canonical($event));
                $existing = ElectronicInvoiceStatus::on($locked->getConnectionName())->where('event_key', $key)->first();
                if ($existing) {
                    if ($existing->electronic_invoice_id !== $locked->id || ! hash_equals($existing->payload_sha256, $hash)) {
                        $this->deny('événement dupliqué contradictoire');
                    }
                } else {
                    $status = new ElectronicInvoiceStatus;
                    $status->setConnection($locked->getConnectionName());
                    $status->forceFill(['electronic_invoice_id' => $locked->id, 'event_key' => $key,
                        'provider_event_id' => $event['id'], 'status' => $event['status_code'], 'payload' => $event,
                        'payload_sha256' => $hash, 'received_at' => now()])->save();
                    $count++;
                }
                if (str_starts_with($event['status_code'], 'fr:') && $event['id'] > ($locked->business_event_id ?? 0)) {
                    $locked->business_status = $event['status_code'];
                    $locked->business_event_id = $event['id'];
                }
            }
            $locked->save();

            return $count;
        });
    }

    private function classify(array $response, int $companyId, string $reference): array
    {
        $http = is_int($response['status'] ?? null) && $response['status'] >= 100 && $response['status'] <= 599 ? $response['status'] : null;
        $unknown = ['status' => 'unknown', 'error' => 'ambiguous_response', 'http' => $http, 'remote' => null];
        try {
            $body = EmissionJson::object($response['body']);
        } catch (Throwable) {
            return $unknown;
        }
        if ($http === 200 && $this->remoteMatches($body, $companyId, $reference)) {
            return ['status' => 'accepted', 'error' => null, 'http' => 200, 'remote' => $body['id']];
        }
        if ($http === 400 && ($body['http_status_code'] ?? null) === 400 && is_string($body['message'] ?? null)
            && trim($body['message']) !== ''
            && ! array_key_exists('id', $body) && ! array_key_exists('company_id', $body)
            && (! array_key_exists('code', $body) || is_int($body['code']))) {
            return ['status' => 'rejected', 'error' => 'provider_bad_request', 'http' => 400, 'remote' => null];
        }

        return $unknown;
    }

    private function remoteMatches(array $remote, int $companyId, string $reference): bool
    {
        return is_int($remote['id'] ?? null) && $remote['id'] > 0
            && ($remote['company_id'] ?? null) === $companyId && ($remote['direction'] ?? null) === 'out'
            && ($remote['external_id'] ?? null) === $reference
            && is_string($remote['created_at'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $remote['created_at'])
            && strtotime($remote['created_at']) !== false;
    }

    private function bindRemote(ElectronicInvoice $record, int $id): bool
    {
        $key = $record->environment.':'.$record->provider_company_id.':'.$id;
        if (($record->provider_invoice_id !== null && $record->provider_invoice_id !== $id)
            || $record->newQuery()->where('provider_binding_key', $key)->whereKeyNot($record->id)->exists()) {
            return false;
        }
        $record->provider_invoice_id = $id;
        $record->provider_binding_key = $key;

        return true;
    }

    private function transition(ElectronicInvoice $record, ElectronicInvoiceAttempt $attempt, string $status, ?string $reason, ?int $http = null, ?int $remoteId = null): void
    {
        $attempt->forceFill(['status' => $status, 'error_code' => $reason, 'http_status' => $http,
            'provider_invoice_id' => $remoteId, 'finished_at' => now(),
            'history' => [...$attempt->history, ['status' => $status, 'reason' => $reason, 'at' => now()->toIso8601String()]],
        ])->save();
        $record->forceFill(['status' => $status, 'last_error' => $reason])->save();
        if ($status === 'accepted') {
            $record->forceFill(['sent_at' => $record->sent_at ?? now()])->save();
        }
    }

    private function withRecord(User $actor, ElectronicInvoice $record, callable $operation): mixed
    {
        $invoice = $this->invoiceFor($record);

        return $invoice->getConnection()->transaction(function () use ($actor, $record, $invoice, $operation) {
            $stored = $this->lockedInvoice($actor, $invoice);
            $locked = ElectronicInvoice::on($stored->getConnectionName())->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->invoice_id !== $stored->id || $locked->dispatch_key !== $this->key($stored) || $locked->execution_mode !== 'test') {
                $this->deny('registre sortant non autorisé');
            }

            return $operation($locked, $stored);
        });
    }

    private function invoiceFor(ElectronicInvoice $record): Invoice
    {
        $stored = $record->newQuery()->findOrFail($record->id);

        return Invoice::on($stored->getConnectionName())->findOrFail($stored->invoice_id);
    }

    private function lockedInvoice(User $actor, Invoice $invoice): Invoice
    {
        User::on($invoice->getConnectionName())->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $stored = $invoice->newQuery()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        if (! $stored->isIssued()) {
            $this->deny('facture non définitive');
        }

        return $stored;
    }

    private function matchDocument(User $actor, Invoice $invoice, EmissionDocument $doc, string $reference): void
    {
        EmissionDocument::assertReference($reference, $actor->id, $invoice->id);
        if ($doc->userId !== $actor->id || $doc->invoiceId !== $invoice->id || $doc->reference !== $reference
            || $doc->mode !== 'validated_private_document' || ! hash_equals($doc->sha256, hash('sha256', $doc->xml))) {
            $this->deny('document privé non concordant');
        }
    }

    private function matchRecord(ElectronicInvoice $record, EmissionDocument $doc): void
    {
        if ($record->execution_mode !== 'test' || $record->environment !== $doc->environment
            || $record->provider_company_id !== $doc->companyId || $record->document_reference !== $doc->reference
            || ! hash_equals($record->cii_sha256, $doc->sha256)) {
            $this->deny('document ou contexte différent de la réservation');
        }
    }

    private function validateEvent(mixed $event, int $remoteId): array
    {
        if (! is_array($event) || ! is_int($event['id'] ?? null) || $event['id'] <= 0
            || ($event['invoice_id'] ?? null) !== $remoteId || ! is_string($event['status_code'] ?? null) || strlen($event['status_code']) > 120
            || ! preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9:_-]{1,100}\z/D', $event['status_code'])
            || ! is_string($event['status_text'] ?? null) || mb_strlen($event['status_text']) > 2000
            || ! is_string($event['created_at'] ?? null) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $event['created_at'])
            || strtotime($event['created_at']) === false) {
            $this->deny('événement sortant invalide ou étranger');
        }

        return array_intersect_key($event, array_flip(['id', 'invoice_id', 'status_code', 'created_at', 'status_text', 'details', 'data', 'attachments']));
    }

    private function canonical(mixed $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }
                $item = array_map($sort, $item);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function key(Invoice $invoice): string
    {
        return 'superpdp:'.$invoice->id;
    }

    private function assertTestTransport(): void
    {
        if (! app()->environment('testing') || $this->transport->mode() !== 'test') {
            $this->deny('transport applicatif fermé');
        }
    }

    private function outsideTransaction(Invoice $invoice): void
    {
        if ($invoice->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('Une transaction externe empêcherait la réservation durable avant l’appel.');
        }
    }

    private function deny(string $reason): never
    {
        throw ValidationException::withMessages(['emission' => 'Dépôt bloqué : '.$reason.'.']);
    }
}
