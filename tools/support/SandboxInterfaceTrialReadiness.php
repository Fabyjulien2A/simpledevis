<?php

declare(strict_types=1);

namespace SimpleDevis\Tools\SuperPdp;

use DateTimeImmutable;
use DateTimeZone;

/** Pure metadata assessment. No application, SQL, token decryption or transport. */
final class SandboxInterfaceTrialReadiness
{
    public const COLUMNS = [
        'electronic_invoices' => ['id', 'invoice_id', 'dispatch_key', 'local_reference', 'execution_mode', 'environment',
            'provider_company_id', 'provider_invoice_id', 'provider_binding_key', 'document_reference', 'cii_sha256',
            'status', 'last_error', 'sent_at', 'business_status', 'business_event_id', 'event_cursor', 'created_at', 'updated_at'],
        'electronic_invoice_attempts' => ['id', 'electronic_invoice_id', 'number', 'reference', 'status', 'cii_sha256',
            'claim_token', 'transport_started_at', 'finished_at', 'http_status', 'provider_invoice_id', 'error_code',
            'history', 'reconciliation', 'created_at', 'updated_at'],
        'electronic_invoice_statuses' => ['id', 'electronic_invoice_id', 'status', 'payload', 'event_key',
            'provider_event_id', 'payload_sha256', 'received_at'],
        'superpdp_connections' => ['id', 'company_id', 'environment', 'oauth_client_id', 'api_url', 'superpdp_company_id',
            'status', 'directory_identifier', 'access_token', 'refresh_token', 'access_token_expires_at'],
    ];

    public const UNIQUE_KEYS = [
        'invoices' => [['user_id', 'invoice_number']],
        'invoice_counters' => [['user_id', 'year']],
        'electronic_invoices' => [['dispatch_key'], ['local_reference'], ['provider_binding_key']],
        'electronic_invoice_attempts' => [['reference'], ['electronic_invoice_id', 'number']],
        'electronic_invoice_statuses' => [['event_key']],
    ];

    public const ENGINES = ['users', 'companies', 'clients', 'invoices', 'invoice_items', 'invoice_counters',
        'payments', 'credit_notes', 'superpdp_connections', 'electronic_invoices', 'electronic_invoice_attempts', 'electronic_invoice_statuses'];

    public static function schema(array $columns, array $unique, array $engines, array $foreignKeys): array
    {
        $missing = $missingKeys = $invalidEngines = $invalidForeignKeys = [];
        foreach (self::COLUMNS as $table => $required) {
            $missing[$table] = array_values(array_diff($required, array_keys($columns[$table] ?? [])));
        }
        foreach (self::UNIQUE_KEYS as $table => $keys) {
            foreach ($keys as $key) {
                if (! in_array($key, $unique[$table] ?? [], true)) {
                    $missingKeys[] = $table.'('.implode(',', $key).')';
                }
            }
        }
        foreach (self::ENGINES as $table) {
            if (strtolower($engines[$table] ?? '') !== 'innodb') {
                $invalidEngines[] = $table;
            }
        }
        foreach ([
            ['electronic_invoices', 'invoice_id', 'invoices', ['RESTRICT', 'NO ACTION']],
            ['electronic_invoice_attempts', 'electronic_invoice_id', 'electronic_invoices', ['RESTRICT', 'NO ACTION']],
            ['electronic_invoice_statuses', 'electronic_invoice_id', 'electronic_invoices', ['CASCADE']],
        ] as [$table, $column, $parent, $rules]) {
            $found = false;
            foreach ($foreignKeys as $key) {
                if ($key['TABLE_NAME'] === $table && $key['COLUMN_NAME'] === $column
                    && $key['REFERENCED_TABLE_NAME'] === $parent && $key['REFERENCED_COLUMN_NAME'] === 'id'
                    && in_array(strtoupper($key['DELETE_RULE']), $rules, true)) {
                    $found = true;
                }
            }
            if (! $found) {
                $invalidForeignKeys[] = $table.'.'.$column;
            }
        }

        return ['ready' => array_filter($missing) === [] && $missingKeys === [] && $invalidEngines === [] && $invalidForeignKeys === [],
            'missing_columns' => $missing, 'missing_unique_keys' => $missingKeys,
            'non_transactional_tables' => $invalidEngines, 'invalid_foreign_keys' => $invalidForeignKeys];
    }

    public static function parties(array $rows, string $clientId, int $now): array
    {
        $results = [];
        foreach ([3 => ['Burger Queen', 18172, '000000002'], 4 => ['Tricatel', 18171, '000000001']] as $id => [$name, $provider, $number]) {
            $matches = array_values(array_filter($rows, static fn ($row) => (int) $row['company_id'] === $id));
            $row = count($matches) === 1 ? $matches[0] : [];
            $binding = (int) ($row['user_id'] ?? 0) === $id && ($row['company_name'] ?? null) === $name
                && (int) ($row['superpdp_company_id'] ?? 0) === $provider
                && ($row['directory_identifier'] ?? null) === $number && ! empty($row['connection_id']);
            $context = $clientId !== '' && ($row['oauth_client_id'] ?? null) === $clientId
                && ($row['environment'] ?? null) === 'sandbox'
                && ($row['api_url'] ?? null) === 'https://api.superpdp.tech/v1.beta';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($row['access_token_expires_at'] ?? ''), new DateTimeZone('UTC'));
            $validDate = $date !== false && $date->format('Y-m-d H:i:s') === ($row['access_token_expires_at'] ?? null);
            $credentials = ($row['status'] ?? null) === 'connected'
                && (int) ($row['has_access_token'] ?? 0) === 1 && (int) ($row['has_refresh_token'] ?? 0) === 1;
            $margin = $validDate && $date->getTimestamp() > $now + 300;
            $checks = ['existing_binding' => $binding, 'oauth_context' => $context,
                'connected_credentials_present' => $credentials, 'token_margin_over_five_minutes' => $margin];
            $results[] = ['company_id' => $id, 'company_name' => $name, 'provider_company_id' => $provider,
                'connection_id' => empty($row['connection_id']) ? null : (int) $row['connection_id'],
                'expected_identity' => ['number_scheme' => 'sandbox', 'number' => $number],
                'local_metadata_ready' => ! in_array(false, $checks, true), 'checks' => $checks,
                'access_token_expires_at_utc' => $validDate ? $date->format('Y-m-d H:i:s') : null,
                'decryption' => 'not_checked', 'remote_identity_and_session' => 'not_checked', 'refresh_performed' => false];
        }

        return $results;
    }
}
