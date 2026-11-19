<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** A browser secret is only useful with the exact durable server grant and fresh evidence. */
final readonly class HttpEmissionConfirmation
{
    public function __construct(private User $actor, private string $uuid,
        #[\SensitiveParameter] private string $token,
        private SandboxEmissionPrerequisites $prerequisites,
        private SandboxEmissionAuthorization $authorization) {}

    public function check(ElectronicInvoice $record, ElectronicInvoiceAttempt $attempt, Invoice $invoice, bool $confirmed = false): void
    {
        $grant = self::grant($attempt);
        $approvedUuid = $this->authorization->approval()['attempt_reference'] ?? null;
        if ($grant === null || ! $this->actor->exists || $invoice->user_id !== $this->actor->id
            || $record->invoice_id !== $invoice->id || $attempt->electronic_invoice_id !== $record->id
            || $grant['expires_at'] <= now()->timestamp || $grant['attempt_reference'] !== $this->uuid
            || $attempt->reference !== $this->uuid || ($approvedUuid !== null && $approvedUuid !== $this->uuid)
            || $grant['user_id'] !== $this->actor->id || $grant['invoice_id'] !== $invoice->id
            || $grant['cii_sha256'] !== $attempt->cii_sha256 || $record->cii_sha256 !== $attempt->cii_sha256
            || ! hash_equals($grant['token_sha256'], hash('sha256', $this->token))
            || ! hash_equals($grant['binding'], $this->prerequisites->binding($this->actor, $invoice, $record->document_reference))
            || ($confirmed && ! collect($attempt->history)->contains(fn ($entry) => ($entry['reason'] ?? null) === 'http_confirmed'
                && ($entry['attempt_reference'] ?? null) === $this->uuid))) {
            throw ValidationException::withMessages(['emission' => 'Confirmation expirée ou prérequis modifiés.']);
        }
    }

    /** Recheck at the SDK boundary too, including after the durable transport claim. */
    public function checkTransport(int $userId, int $invoiceId, string $documentReference, string $uuid): void
    {
        if ($this->actor->id !== $userId || $this->uuid !== $uuid) {
            throw ValidationException::withMessages(['emission' => 'Tentative HTTP étrangère.']);
        }
        $invoice = Invoice::findOrFail($invoiceId);
        $record = ElectronicInvoice::where('invoice_id', $invoiceId)->where('document_reference', $documentReference)->sole();
        $attempt = $record->attempts()->where('reference', $uuid)->firstOrFail();
        $this->check($record, $attempt, $invoice, true);
    }

    public static function grant(ElectronicInvoiceAttempt $attempt): ?array
    {
        foreach ($attempt->history ?? [] as $entry) {
            if (($entry['reason'] ?? null) === 'http_authorized') {
                return $entry;
            }
        }

        return null;
    }
}
