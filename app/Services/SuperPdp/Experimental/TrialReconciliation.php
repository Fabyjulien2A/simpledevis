<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Services\SuperPdp\Verification\VerificationObservation;
use RuntimeException;

/** Positive reconciliation only. Consumes fresh read receipts, never issues a request. */
final class TrialReconciliation
{
    public static function positive(
        TrialPackage $package,
        string $attemptReference,
        VerificationObservation $metadata,
        VerificationObservation $original,
    ): int {
        foreach ([$metadata, $original] as $receipt) {
            TrialGate::fresh($receipt->observedAt);
            if ($receipt->source !== 'live') {
                throw new RuntimeException('Fresh live reconciliation receipts required.');
            }
        }
        $result = TrialOutcome::classify(200, json_encode($metadata->data, JSON_THROW_ON_ERROR), $attemptReference);
        $xml = $original->data['xml'] ?? null;
        if ($result['status'] !== 'accepted' || ! is_string($xml) || $xml !== $package->xml
            || $original->sha256 !== hash('sha256', $xml)
            || ! hash_equals($package->manifest['cii_sha256'], hash('sha256', $xml))) {
            throw new RuntimeException('No positive match; outcome stays unknown and replay stays forbidden.');
        }

        return $result['provider_invoice_id'];
    }
}
