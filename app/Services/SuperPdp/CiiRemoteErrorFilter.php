<?php

namespace App\Services\SuperPdp;

/** Bounded JSON error extraction. Request values are used in memory for redaction only. */
final class CiiRemoteErrorFilter
{
    public static function values(mixed $data, int $minimumLength = 3): array
    {
        $values = [];
        $walk = static function ($item) use (&$walk, &$values, $minimumLength) {
            if (is_array($item)) {
                foreach ($item as $value) {
                    $walk($value);
                }
            } elseif (is_scalar($item) && strlen((string) $item) >= $minimumLength) {
                $values[] = (string) $item;
            }
        };
        $walk($data);
        usort($values, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return array_values(array_unique($values));
    }

    public static function extract(string $body, array $requestValues = []): array
    {
        $unknown = static fn ($reason) => ['remote_error_format' => 'unrecognized',
            'remote_error_message' => null, 'remote_error_diagnostic' => $reason];
        if (strlen($body) > 65536) {
            return $unknown('response_too_large_for_error_extraction');
        }
        $data = json_decode($body, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data) || array_is_list($data)) {
            return $unknown('json_error_object_not_recognized');
        }
        // Secret/input/payload/header values may also be echoed inside a message.
        $extraValues = [];
        $walk = static function ($row) use (&$walk, &$extraValues) {
            if (! is_array($row)) {
                return;
            }
            foreach ($row as $key => $value) {
                if (preg_match('/token|secret|password|authorization|session|payload|headers|request|input|value|actual|received|context|email|phone|address|name|identifier|iban/i', (string) $key)) {
                    $extraValues = array_merge($extraValues, self::values($value, 1));
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($data);
        $values = array_unique(array_merge($requestValues, $extraValues));
        usort($values, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $message = $data['message'] ?? $data['detail'] ?? $data['error'] ?? null;
        if (is_array($message)) {
            $message = $message['message'] ?? null;
        }
        $contract = is_int($data['http_status_code'] ?? null) && is_string($data['message'] ?? null);
        $errors = [];
        $append = static function ($row, $field = null) use (&$errors, $values) {
            if (count($errors) >= 30) {
                return;
            }
            if (is_string($row)) {
                $row = ['message' => $row];
            }
            if (! is_array($row)) {
                return;
            }
            $path = $row['field'] ?? $row['path'] ?? $row['loc'] ?? $field;
            if (is_array($path)) {
                $path = implode('.', array_filter($path, static fn ($v) => is_string($v) || is_int($v)));
            }
            $error = [];
            if (is_string($path)) {
                $error['field'] = self::field($path) ?? '[redacted_field]';
            }
            $type = $row['type'] ?? $row['code'] ?? $row['keyword'] ?? null;
            if (is_int($type)) {
                $error['type'] = $type;
            } elseif (is_string($type)) {
                $error['type'] = self::message($type, $values);
            }
            if (is_string($row['message'] ?? $row['msg'] ?? null)) {
                $error['message'] = self::message($row['message'] ?? $row['msg'], $values);
            }
            foreach (['value', 'input', 'rejected_value', 'invalid_value', 'actual', 'received'] as $key) {
                if (array_key_exists($key, $row)) {
                    $error[$key] = '[redacted]';
                }
            }
            if ($error !== []) {
                $errors[] = $error;
            }
        };
        foreach (['errors', 'details', 'validation_errors', 'detail'] as $key) {
            $rows = $data[$key] ?? null;
            if (! is_array($rows)) {
                continue;
            }
            if (isset($rows['field']) || isset($rows['path']) || isset($rows['loc']) || isset($rows['message'])) {
                $append($rows);
            } else {
                foreach ($rows as $field => $row) {
                    if (is_array($row) && array_is_list($row)) {
                        foreach ($row as $item) {
                            $append($item, is_string($field) ? $field : null);
                        }
                    } else {
                        $append($row, is_string($field) ? $field : null);
                    }
                }
            }
        }
        if (isset($data['field']) || isset($data['path'])) {
            $append($data);
        }
        if (! $contract && ! is_string($message) && $errors === []) {
            return $unknown('json_error_fields_not_recognized');
        }
        $result = ['remote_error_format' => $contract ? 'http_ko' : 'json_error_extension',
            'remote_error_message' => is_string($message) ? self::message($message, $values) : null];
        foreach (['code' => 'remote_error_code', 'http_status_code' => 'remote_http_status_code'] as $key => $target) {
            if (is_int($data[$key] ?? null)) {
                $result[$target] = $data[$key];
            }
        }
        if ($errors !== []) {
            $result['remote_validation_errors'] = $errors;
        }
        if (! $contract) {
            $result['remote_error_diagnostic'] = 'non_contractual_error_extension';
        }
        return $result;
    }

    private static function field(string $path): ?string
    {
        static $names;
        if ($names === null) {
            $names = array_fill_keys(['body', 'query', 'from', 'to', 'access_token', 'refresh_token', 'client_secret',
                'password', 'authorization', 'session_id', 'email', 'phone'], true);
            $schema = json_decode(file_get_contents(resource_path('schemas/superpdp/1.39.0.beta/en-invoice.schema.json')), true);
            $walk = static function ($node) use (&$walk, &$names) {
                if (! is_array($node)) {
                    return;
                }
                foreach (array_keys($node['properties'] ?? []) as $name) {
                    $names[$name] = true;
                }
                foreach ($node as $value) {
                    if (is_array($value)) {
                        $walk($value);
                    }
                }
            };
            $walk($schema);
        }
        if (strlen($path) > 200 || ! preg_match('~\A[\w.$/\[\]-]+\z~D', $path)) {
            return null;
        }
        foreach (preg_split('~[.$/\[\]-]+~', $path, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (! isset($names[$part]) && ! preg_match('/\A[0-9]{1,4}\z/D', $part)) {
                return null;
            }
        }
        return $path;
    }

    private static function message(string $text, array $values): string
    {
        if (strlen($text) > 8192) {
            return '[redacted: message too long]';
        }
        $text = preg_replace('~(?:Bearer\s+\S+)|(?:\b(?:access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|password|session[_ -]?id|authorization)\s*[:=]\s*[^,;\r\n]+)~i', '[redacted]', $text);
        // Mask complete echoed values before protecting field names: a company
        // name can itself contain a schema word such as "seller".
        foreach ($values as $value) {
            $text = strlen($value) < 3
                ? preg_replace('~(?<![a-zA-Z0-9_])'.preg_quote($value, '~').'(?![a-zA-Z0-9_])~i', '[redacted]', $text)
                : str_ireplace($value, '[redacted]', $text);
        }
        // Protect schema field names, so rejected values never replace the field name.
        $fields = [];
        $text = preg_replace_callback('~[a-zA-Z_][a-zA-Z0-9_.\[\]/-]*~', static function ($m) use (&$fields) {
            if (self::field($m[0]) === null) {
                return $m[0];
            }
            $key = "\x01".strtr(base_convert((string) count($fields), 10, 4), ['0' => '!', '1' => '~', '2' => '^', '3' => ':'])."\x02";
            $fields[$key] = $m[0];
            return $key;
        }, str_replace(["\x01", "\x02"], '', $text));
        $text = preg_replace('~\{.*\}|\[\s*["{].*\]~s', '[redacted]', $text);
        $text = preg_replace('~(?:Bearer\s+\S+)|(?:\b(?:access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|password|session[_ -]?id|authorization)\s*[:=]\s*[^,;\r\n]+)~i', '[redacted]', $text);
        $text = preg_replace('~https?://\S+|[\w.+-]+@[\w.-]+\.[a-z]{2,}|\beyJ[A-Za-z0-9_.-]+|\b[A-Za-z0-9_-]{20,}\b~i', '[redacted]', $text);
        $text = preg_replace_callback('~(["\x27`])([^"\x27`\r\n]*)\1~', static fn ($m) =>
            isset($fields[$m[2]]) ? $m[2] : '[redacted]', $text);
        $text = preg_replace('~\+?[0-9][0-9 .()/-]{3,}[0-9]|\b[A-Z]{2}[0-9]{5,}\b~i', '[redacted]', $text);
        $text = strtr($text, $fields);
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text), 0, 1500);
    }
}
