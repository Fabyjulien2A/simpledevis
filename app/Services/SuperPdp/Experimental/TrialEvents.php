<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Services\SuperPdp\CiiPreparationEvidence;
use RuntimeException;

/** Offline projection of explicitly fetched outgoing events. No GET, polling or payments. */
final class TrialEvents
{
    private array $events = [];

    private ?int $cursor = null;

    public function __construct(private readonly int $providerInvoiceId)
    {
        if ($providerInvoiceId <= 0) {
            throw new RuntimeException('Positive outgoing provider invoice ID required.');
        }
    }

    public function query(): array
    {
        return array_filter(['invoice_id' => $this->providerInvoiceId, 'limit' => 100,
            'starting_after_id' => $this->cursor], static fn ($value) => $value !== null);
    }

    public function applyPage(array $page): array
    {
        if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > 1048576
            || ! is_array($page['data'] ?? null) || ! array_is_list($page['data']) || ! is_bool($page['has_after'] ?? null)
            || count($page['data']) > 1000
            || ($page['has_after'] && $page['data'] === [])) {
            throw new RuntimeException('Complete event page required.');
        }
        $events = $this->events;
        $last = 0;
        foreach ($page['data'] as $event) {
            if (! is_array($event) || ! is_int($event['id'] ?? null) || $event['id'] <= $last
                || ($event['invoice_id'] ?? null) !== $this->providerInvoiceId
                || ! is_string($event['status_code'] ?? null)
                || ! preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9:_-]{1,100}\z/D', $event['status_code'])
                || ! is_string($event['created_at'] ?? null) || strtotime($event['created_at']) === false) {
                throw new RuntimeException('Malformed, unordered or foreign event refused.');
            }
            $last = $event['id'];
            if (isset($events[$last]) && CiiPreparationEvidence::hash($events[$last]) !== CiiPreparationEvidence::hash($event)) {
                throw new RuntimeException('Contradictory event duplicate; entire page refused.');
            }
            $events[$last] = $event;
        }
        // Commit only after the entire page passes. Future runner must persist before the next GET.
        ksort($events);
        $this->events = $events;
        if ($last > ($this->cursor ?? 0)) {
            $this->cursor = $last;
        }
        $codes = array_values(array_unique(array_column($events, 'status_code')));

        return ['events' => array_values($events), 'cursor' => $this->cursor, 'has_after' => $page['has_after'],
            'deposit_accepted_is_delivery' => false,
            'transmitted_to_access_point' => in_array('api:sent', $codes, true),
            'french_received_event' => in_array('fr:202', $codes, true),
            'remote_acknowledgment' => in_array('api:acknowledged', $codes, true),
            'remote_acceptance' => in_array('api:accepted', $codes, true),
            'asynchronous_failure' => array_intersect($codes, ['api:invalid', 'api:rejected', 'fr:213', 'fr:501']) !== [],
            // api:received is described as reception mode; never infer Tricatel receipt from it.
            'recipient_business_receipt' => 'not_proven_by_deposit_acceptance'];
    }
}
