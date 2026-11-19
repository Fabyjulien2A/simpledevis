<?php

use App\Services\SuperPdp\CiiRemoteErrorFilter;
use Tests\TestCase;

uses(TestCase::class);

it('preserves contractual error codes and useful text while masking echoed values', function () {
    $body = ['code' => 1234, 'http_status_code' => 400,
        'message' => 'Invalid "seller.vat_identifier" for Private Company: "FR12345678901"; email buyer@example.test; Authorization: Bearer TOKEN-SECRET',
        'headers' => ['Authorization' => 'Bearer TOKEN-SECRET'], 'payload' => ['seller' => ['name' => 'Private Company']]];
    $result = CiiRemoteErrorFilter::extract(json_encode($body));
    expect($result['remote_error_format'])->toBe('http_ko')->and($result['remote_error_code'])->toBe(1234)
        ->and($result['remote_http_status_code'])->toBe(400)
        ->and($result['remote_error_message'])->toContain('Invalid', 'seller.vat_identifier')
        ->and(json_encode($result))->not->toContain('Private Company', 'FR12345678901', 'buyer@example.test', 'TOKEN-SECRET', 'headers', 'payload');
});

it('recognizes non contractual validation extensions without retaining rejected values', function () {
    $body = ['details' => [['loc' => ['body', 'lines', 0, 'invoiced_quantity'], 'type' => 'decimal_parsing',
        'msg' => 'Cannot parse "PRIVATE-INPUT" as decimal', 'input' => 'PRIVATE-INPUT']]];
    $result = CiiRemoteErrorFilter::extract(json_encode($body));
    expect($result['remote_error_format'])->toBe('json_error_extension')
        ->and($result['remote_error_diagnostic'])->toBe('non_contractual_error_extension')
        ->and($result['remote_validation_errors'][0]['field'])->toBe('body.lines.0.invoiced_quantity')
        ->and($result['remote_validation_errors'][0]['type'])->toBe('decimal_parsing')
        ->and($result['remote_validation_errors'][0]['input'])->toBe('[redacted]')
        ->and(json_encode($result))->not->toContain('PRIVATE-INPUT');
});

it('reports unrecognized responses without copying their contents', function ($body, $reason) {
    $result = CiiRemoteErrorFilter::extract($body);
    expect($result['remote_error_format'])->toBe('unrecognized')
        ->and($result['remote_error_diagnostic'])->toBe($reason)
        ->and(json_encode($result))->not->toContain('PRIVATE-INPUT');
})->with([
    ['<html>PRIVATE-INPUT</html>', 'json_error_object_not_recognized'],
    ['["PRIVATE-INPUT"]', 'json_error_object_not_recognized'],
    ['{"unknown":"PRIVATE-INPUT"}', 'json_error_fields_not_recognized'],
    [str_repeat('x', 65537), 'response_too_large_for_error_extraction'],
]);

it('retains mapped field names while masking unknown field identifiers and session values', function () {
    $body = ['message' => 'Validation failed', 'errors' => [
        'seller.name' => ['Invalid "PRIVATE-NAME"'],
        'person@example.test' => [['type' => 'invalid', 'value' => 'PRIVATE-NAME']],
        'session_id' => [['type' => 'forbidden', 'message' => 'session_id=SESSION-SECRET', 'value' => 'SESSION-SECRET']],
    ]];
    $result = CiiRemoteErrorFilter::extract(json_encode($body));
    expect(array_column($result['remote_validation_errors'], 'field'))->toBe(['seller.name', '[redacted_field]', 'session_id'])
        ->and(json_encode($result))->not->toContain('person@example.test', 'SESSION-SECRET', 'PRIVATE-NAME');
});

it('redacts short secrets and credentials present only in free text', function () {
    $result = CiiRemoteErrorFilter::extract(json_encode(['message' => 'Invalid password=x; authorization=abc; session_id=12',
        'password' => 'x', 'session_id' => '12']));
    expect($result['remote_error_message'])->toContain('Invalid')->not->toContain('password=x', 'abc', '12');
});
