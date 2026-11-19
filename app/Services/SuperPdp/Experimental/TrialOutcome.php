<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Services\SuperPdp\Emission\EmissionJson;
use Throwable;

/** Local interpretation of a future response; no HTTP operation. */
final class TrialOutcome
{
    public static function classify(?int $http, ?string $body, string $reference): array
    {
        $unknown = ['status' => 'unknown', 'http_status' => $http, 'provider_invoice_id' => null];
        try {
            $data = EmissionJson::object($body ?? '');
        } catch (Throwable) {
            return $unknown;
        }
        if ($http === 200 && is_int($data['id'] ?? null) && $data['id'] > 0
            && ($data['company_id'] ?? null) === 18172 && ($data['direction'] ?? null) === 'out'
            && ($data['external_id'] ?? null) === $reference
            && is_string($data['created_at'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T/', $data['created_at'])
            && strtotime($data['created_at']) !== false) {
            return ['status' => 'accepted', 'http_status' => 200, 'provider_invoice_id' => $data['id']];
        }
        if ($http === 400 && ($data['http_status_code'] ?? null) === 400
            && is_string($data['message'] ?? null) && trim($data['message']) !== ''
            && array_diff(array_keys($data), ['http_status_code', 'message', 'code']) === []
            && (! array_key_exists('code', $data) || is_int($data['code']))) {
            return ['status' => 'rejected', 'http_status' => 400, 'provider_invoice_id' => null];
        }

        return $unknown;
    }
}
