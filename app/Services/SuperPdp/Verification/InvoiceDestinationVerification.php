<?php

namespace App\Services\SuperPdp\Verification;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\FrenchDirectoryEntriesAnalysis;
use App\Services\SuperPdp\FrenchPeppolBt49Candidate;
use App\Services\SuperPdp\FrenchSellerIdentifiers;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\InvoiceRoutingQualification;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Rapports privés de destination, sans émission, modification de facture ou lecture des fiches actuelles. */
class InvoiceDestinationVerification
{
    public const ENDPOINT = 'https://api.superpdp.tech/v1.beta/french_directory/entries';

    public function __construct(
        private readonly InvoiceExportDataBuilder $source,
        private readonly InvoiceRoutingQualification $routing,
        private readonly FrenchDirectoryEntriesAnalysis $analysis,
        private readonly VerificationAuditStore $audit,
    ) {}

    public function verify(User $actor, Invoice $invoice): array
    {
        $stored = $this->owned($actor, $invoice);
        $report = ['schema_version' => 1, 'kind' => 'buyer_destination', 'id' => (string) Str::uuid(),
            'user_id' => $actor->id, 'invoice_id' => $stored->id, 'checked_at' => now()->toIso8601String(),
            'contract_version' => '1.39.0.beta', 'directory_scope' => 'french_directory',
            'emission_ready' => false, 'status' => 'unavailable', 'assessment' => 'unavailable',
            'reason' => 'frozen_data_unavailable', 'source' => 'not_called', 'verified' => false,
            'endpoint' => self::ENDPOINT, 'buyer_siren' => null, 'request' => null,
            'observed_at' => null, 'response_sha256' => null, 'source_sha256' => null,
            'party_snapshot_sha256' => is_string($stored->getRawOriginal('party_snapshot')) ? hash('sha256', $stored->getRawOriginal('party_snapshot')) : null,
            'emission_context_sha256' => is_string($stored->getRawOriginal('emission_context')) ? hash('sha256', $stored->getRawOriginal('emission_context')) : null];
        $gateway = null;
        try {
            $data = $this->frozenData($stored);
            $report = array_replace($report, $this->fingerprints($data));
            $siren = FrenchSellerIdentifiers::sirenFromSiret($data['buyer']['siret'] ?? '');
            $report['buyer_siren'] = $siren;
            $report['declared_address'] = InvoiceEmissionContext::resolve($data, [])['buyer_electronic_address'] ?? null;
            $this->simpleAddress($data);
            $demo = config('superpdp_verification.destination_demo');
            $responses = null;
            if ($demo !== null) {
                if (! app()->environment(['local', 'testing']) || ! is_string($demo)) {
                    throw new VerificationReadException('unavailable', 'demo_not_allowed');
                }
                $responses = DestinationDirectoryDemo::responses($demo, $siren);
            }
            $gateway = ReadOnlyQualificationGateway::forDestination($actor, $stored, $responses);
            $observation = $gateway->directory($siren);
            $report = array_replace($report, $this->observation($observation));
            // Conserver seulement les éléments de décision, jamais le corps HTTP intégral.
            $reply = ['data' => array_map(fn ($row) => [
                'company' => ['number' => $row['company']['number'], 'country' => $row['company']['country']],
                'identifier' => $row['identifier'], 'is_active' => $row['is_active'],
            ], $observation->data['data'])];
            if (array_key_exists('has_more', $observation->data)) {
                $reply['has_more'] = $observation->data['has_more'];
            }
            $report['directory_reply'] = $reply;
            $result = $this->assess($data, $reply);
            $report['assessment'] = $result['status'];
            $report['reason'] = $result['reason'];
            $report['destination'] = $result['destination'];
            $report['verified'] = $result['status'] === 'matched' && $observation->source === 'live';
            $report['status'] = $result['status'] === 'matched'
                ? ($report['verified'] ? 'qualified' : 'unavailable') : $result['status'];
        } catch (VerificationReadException $exception) {
            if ($observation = $gateway?->lastObservation()) {
                $report = array_replace($report, $this->observation($observation));
            }
            $report['reason'] = $exception->reason;
            $report['http_status'] = $exception->httpStatus;
            $report['status'] = $exception->status === 'ambiguous' ? 'ambiguous' : 'unavailable';
        } catch (InvalidArgumentException) {
            $report['status'] = 'incompatible';
            $report['reason'] = 'frozen_data_or_address_not_supported';
        }
        $fresh = $this->owned($actor, $stored);
        if (isset($report['source_sha256'])) {
            try {
                if ($this->fingerprints($this->frozenData($fresh)) !== array_intersect_key($report, $this->fingerprints($data))) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $report['status'] = 'unavailable';
                $report['reason'] = 'invoice_changed_during_verification';
                $report['verified'] = false;
            }
        }
        $report['audit_reference'] = $this->audit->storeDestination($actor, $fresh, $report);

        return $report;
    }

    /** Le GET ne consulte que le stockage privé ; toutes les preuves sont revalidées. */
    public function latest(User $actor, Invoice $invoice): ?array
    {
        $stored = $this->owned($actor, $invoice);
        try {
            $report = $this->audit->latestDestination($actor, $stored);
            if ($report === null) {
                return null;
            }

            return $this->validate($stored, $report);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'reason' => 'report_unreadable', 'source' => 'not_called', 'verified' => false];
        }
    }

    private function validate(Invoice $invoice, array $report): array
    {
        $invalid = fn (string $reason, string $status = 'unavailable') => array_replace($report, [
            'status' => $status, 'reason' => $reason, 'verified' => false,
        ]);
        try {
            $data = $this->frozenData($invoice);
            $this->simpleAddress($data);
        } catch (InvalidArgumentException) {
            return $invalid('frozen_data_or_address_not_supported', 'incompatible');
        }
        foreach ($this->fingerprints($data) as $key => $expected) {
            if (($report[$key] ?? null) !== $expected) {
                return $invalid('invoice_source_mismatch');
            }
        }
        if (($report['contract_version'] ?? null) !== '1.39.0.beta' || ($report['directory_scope'] ?? null) !== 'french_directory'
            || ($report['endpoint'] ?? null) !== self::ENDPOINT) {
            return $invalid('report_context_mismatch');
        }
        $siren = FrenchSellerIdentifiers::sirenFromSiret($data['buyer']['siret']);
        if (($report['buyer_siren'] ?? null) !== $siren) {
            return $invalid('report_context_mismatch');
        }
        if (($report['source'] ?? null) === 'not_called') {
            return $invalid($report['reason'] ?? 'verification_not_performed', $report['status'] === 'incompatible' ? 'incompatible' : 'unavailable');
        }
        $observed = $report['observed_at'] ?? null;
        if (! is_string($observed) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $observed)) {
            return $invalid('invalid_observation_date');
        }
        try {
            $parsed = new DateTimeImmutable($observed);
            $checkedAt = $report['checked_at'] ?? null;
            if (! is_string($checkedAt)) {
                return $invalid('invalid_observation_date');
            }
            $checked = new DateTimeImmutable($checkedAt);
            if ($parsed->format('Y-m-d\TH:i:sP') !== $observed || $checked->format('Y-m-d\TH:i:sP') !== $checkedAt
                || $checked > $parsed) {
                return $invalid('invalid_observation_date');
            }
            $time = $parsed->getTimestamp();
        } catch (\Exception) {
            return $invalid('invalid_observation_date');
        }
        $validity = $this->validityMinutes() * 60;
        if ($time > now()->timestamp) {
            return $invalid('invalid_observation_date');
        }
        if (min($time, $checked->getTimestamp()) <= now()->timestamp - $validity) {
            return $invalid('evidence_expired', 'expired');
        }
        $request = $report['request'] ?? [];
        if (($request['method'] ?? null) !== 'GET' || ($request['endpoint'] ?? null) !== self::ENDPOINT
            || ($request['query'] ?? null) !== ['number' => $siren]
            || ! is_string($report['response_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $report['response_sha256'])) {
            return $invalid('report_context_mismatch');
        }
        if (($request['http_status'] ?? null) !== 200 || ! isset($report['directory_reply'])) {
            return $invalid($report['reason'] ?? 'api_unavailable', $report['status'] === 'ambiguous' ? 'ambiguous' : 'unavailable');
        }
        $result = $this->assess($data, $report['directory_reply']);
        $status = $result['status'];
        if (($report['assessment'] ?? null) !== $status || ($report['destination'] ?? null) !== $result['destination']
            || ($report['declared_address'] ?? null) !== (InvoiceEmissionContext::resolve($data, [])['buyer_electronic_address'] ?? null)) {
            return $invalid('report_context_mismatch');
        }
        $verified = $status === 'matched' && $report['source'] === 'live'
            && ($report['verified'] ?? null) === true && ($report['status'] ?? null) === 'qualified';

        return array_replace($report, ['status' => $status === 'matched' ? ($verified ? 'qualified' : 'unavailable') : $status,
            'reason' => $result['reason'], 'verified' => $verified, 'destination' => $result['destination']]);
    }

    public function validityMinutes(): int
    {
        $minutes = config('superpdp_verification.destination_validity_minutes');

        return is_int($minutes) && $minutes > 0 ? $minutes : 15;
    }

    private function assess(array $data, array $reply): array
    {
        $qualified = $this->routing->assess($data, $reply);
        $reason = $qualified['reason'];
        $status = $qualified['qualified_for_simulation'] ? 'matched' : match ($reason) {
            'no_active_destination' => $reply['data'] === [] ? 'absent' : 'inactive',
            'ambiguous_destinations', 'partial_response', 'incomplete_or_unknown_envelope' => 'ambiguous',
            default => 'incompatible',
        };
        // Ne pas transformer les doublons en une destination unique.
        if ($status === 'inactive') {
            $analysis = $this->analysis->analyze($qualified['buyer_siren'], $reply['data']);
            if (collect($analysis['lines'])->contains(fn ($line) => $line['classification'] !== 'inactive')) {
                $status = 'incompatible';
            }
        }

        return ['status' => $status, 'reason' => $reason, 'destination' => $qualified['destination']];
    }

    private function frozenData(Invoice $invoice): array
    {
        // Refuser l'historique avant le builder, qui conserve un repli de lecture classique.
        if (! is_string($invoice->getRawOriginal('party_snapshot')) || ! is_string($invoice->getRawOriginal('emission_context'))) {
            throw new InvalidArgumentException('Sources figées absentes.');
        }

        return InvoiceElectronicPartyData::resolve($this->source->buildElectronic($invoice), true);
    }

    private function simpleAddress(array $data): void
    {
        if (($data['buyer']['country_code'] ?? null) !== 'FR' || ($data['buyer']['client_type'] ?? null) !== 'professional') {
            throw new InvalidArgumentException;
        }
        $context = InvoiceEmissionContext::resolve($data, []);
        $address = $context['buyer_electronic_address'] ?? [];
        if (($address['scheme'] ?? null) !== '0225' || ! is_string($address['value'] ?? null)) {
            throw new InvalidArgumentException;
        }
        FrenchPeppolBt49Candidate::fromIdentifier('0225:'.$address['value'], FrenchSellerIdentifiers::sirenFromSiret($data['buyer']['siret'] ?? ''));
    }

    private function fingerprints(array $data): array
    {
        return ['source_sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
            'emission_context_sha256' => hash('sha256', $data['emission_context'])];
    }

    private function observation(VerificationObservation $observation): array
    {
        return ['source' => $observation->source, 'response_sha256' => $observation->sha256,
            'observed_at' => $observation->observedAt, 'request' => $observation->request];
    }

    private function owned(User $actor, Invoice $invoice): Invoice
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        abort_unless($stored->isIssued(), 404);

        return $stored;
    }
}
