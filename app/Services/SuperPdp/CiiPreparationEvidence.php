<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use RuntimeException;

/** Reuses immutable party evidence. No GET, refresh, inferred route or send permission. */
final class CiiPreparationEvidence
{
    public function __construct(private readonly VerificationAuditStore $audits) {}

    public function assess(User $actor, Invoice $invoice, array $data, SuperPdpConnection $connection, string $reference): array
    {
        $report = $this->audits->read($actor, $invoice, $reference);
        if (($report['status'] ?? null) !== 'passed' || ($report['expected_environment'] ?? null) !== 'sandbox'
            || ($report['expected_provider_company_id'] ?? null) !== $connection->superpdp_company_id
            || ($report['expected_oauth_client_id'] ?? null) !== $connection->oauth_client_id
            || ($report['expected_api_url'] ?? null) !== SandboxEmissionAuthorization::API_URL
            || ($report['source_sha256'] ?? null) !== self::hash($data)
            || ($report['party_snapshot_sha256'] ?? null) !== hash('sha256', $data['party_snapshot'])
            || ($report['emission_context_sha256'] ?? null) !== hash('sha256', $data['emission_context'])) {
            throw new RuntimeException('Party evidence does not match the historical invoice or sandbox company.');
        }
        $this->fresh($report['checked_at'] ?? null);
        $live = true;
        foreach (['buyer_destination', 'seller_identity', 'seller_session'] as $key) {
            $check = $report['checks'][$key] ?? [];
            $source = $check['source'] ?? null;
            if (($check['status'] ?? null) !== 'passed' || ! in_array($source, ['live', 'simulated', 'cached'], true)
                || ($check['verified'] ?? null) !== ($source === 'live')
                || ! is_string($check['response_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $check['response_sha256'])) {
                throw new RuntimeException('Complete matching party evidence required.');
            }
            $this->fresh($check['observed_at'] ?? null);
            $live = $live && $source === 'live';
        }

        return ['live' => $live, 'report' => $report];
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function fresh(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $value)
            || ($time = strtotime($value)) === false || $time > now()->timestamp || $time < now()->subMinutes(15)->timestamp) {
            throw new RuntimeException('Fresh party evidence required (maximum 15 minutes).');
        }
    }
}
