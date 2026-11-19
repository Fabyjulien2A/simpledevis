<?php

use SimpleDevis\Tools\SuperPdp\AuthorizationCodeWeb as Web;
use SimpleDevis\Tools\SuperPdp\OAuthHttpTrace as Trace;

require_once dirname(__DIR__, 3).'/tools/support/SuperPdpAuthorizationCodeDiagnostic.php';
require_once dirname(__DIR__, 3).'/tools/support/SuperPdpOAuthHttpTrace.php';

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/oauth-trace-test-'.bin2hex(random_bytes(12));
    mkdir($this->directory, 0700);
    $this->revision = '0123456789abcdef';
    $this->server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_ORIGIN' => 'http://127.0.0.1:8765'];
    $this->record = function (array $server, array $post, array $before, array $after, string $sessionId,
        ?string $cookie, int $now, string $reason, ?string $revision = null): array {
        $trace = new Trace($this->directory, $now);
        $id = $trace->begin($server, $post, $before, $sessionId, $cookie, $revision ?? $this->revision, $now);
        $event = $trace->finish($after, $reason);

        return [$id, $event];
    };
});

afterEach(function () {
    $file = $this->directory.'/trace-state.json';
    if (is_file($file)) {
        unlink($file);
    }
    rmdir($this->directory);
});

it('correlates GET and POST without persisting session identifiers cookies CSRF or arbitrary fields', function () {
    $nonce = str_repeat('a', 64);
    [$id, $get] = ($this->record)($this->server, [], [], ['csrf' => $nonce], 'PRIVATE-SESSION-ID', null, 100, 'form_created');
    $server = array_replace($this->server, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/oauth/start']);
    [, $post] = ($this->record)($server, ['diagnostic_id' => $id, 'csrf' => $nonce, 'PRIVATE-FIELD' => 'PRIVATE-SECRET'],
        ['csrf' => $nonce], [], 'PRIVATE-SESSION-ID', 'PRIVATE-SESSION-ID', 101, 'unexpected_form_fields');
    expect($get['cookie_present'])->toBeFalse()->and($get['nonce_server_after'])->toBeTrue()
        ->and($post)->toMatchArray(['diagnostic_id' => $id, 'session_continuity' => 'same', 'cookie_present' => true,
            'incoming_cookie_retained' => true, 'nonce_server_present' => true, 'nonce_submitted_present' => true,
            'csrf_matches' => true, 'form_revision_matches' => true, 'request_order' => 2]);
    $text = file_get_contents($this->directory.'/trace-state.json');
    expect($text)->not->toContain('PRIVATE-', $nonce, 'csrf|', 'Cookie', 'Authorization');
    expect(array_keys($post))->toBe(['at', 'request_order', 'diagnostic_id', 'previous_form_id', 'method', 'route',
        'code_revision', 'form_revision_matches', 'cookie_present', 'incoming_cookie_retained', 'session_continuity',
        'nonce_server_present', 'nonce_submitted_present', 'csrf_matches', 'nonce_server_after', 'reason']);
});

it('distinguishes a missing or replaced cookie from a wiped nonce in the same session', function (string $case) {
    [$id] = ($this->record)($this->server, [], [], ['csrf' => 'PRIVATE-CSRF'], 'PRIVATE-ORIGINAL', null, 100, 'form_created');
    $same = $case === 'nonce_wiped';
    $cookie = match ($case) { 'missing' => null, 'rejected' => 'PRIVATE-ORIGINAL', default => 'PRIVATE-OTHER' };
    if ($same) {
        $cookie = 'PRIVATE-ORIGINAL';
    }
    $server = array_replace($this->server, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/oauth/start']);
    [, $event] = ($this->record)($server, ['diagnostic_id' => $id, 'csrf' => 'PRIVATE-CSRF'], [], [],
        $same ? 'PRIVATE-ORIGINAL' : 'PRIVATE-OTHER', $cookie, 101, 'form_missing_or_already_consumed');
    expect($event['session_continuity'])->toBe($same ? 'same' : 'different')
        ->and($event['cookie_present'])->toBe($cookie !== null)->and($event['nonce_server_present'])->toBeFalse()
        ->and($event['csrf_matches'])->toBeFalse();
    if ($case === 'rejected') {
        expect($event['incoming_cookie_retained'])->toBeFalse();
    }
})->with(['missing', 'replaced', 'rejected', 'nonce_wiped']);

it('identifies an intervening GET and a different code revision without recording the nonces', function () {
    [$first] = ($this->record)($this->server, [], [], ['csrf' => 'OLD'], 'PRIVATE-ID', null, 100, 'form_created');
    [$second, $get] = ($this->record)($this->server, [], ['csrf' => 'OLD'], ['csrf' => 'NEW'], 'PRIVATE-ID', 'PRIVATE-ID', 101, 'form_created');
    expect($first)->not->toBe($second)->and($get['previous_form_id'])->toBe($first);
    $server = array_replace($this->server, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/oauth/start']);
    [, $post] = ($this->record)($server, ['diagnostic_id' => $first, 'csrf' => 'OLD'], ['csrf' => 'NEW'], ['csrf' => 'NEW'],
        'PRIVATE-ID', 'PRIVATE-ID', 102, 'form_stale_or_changed', 'fedcba9876543210');
    expect($post)->toMatchArray(['previous_form_id' => $second, 'session_continuity' => 'same',
        'csrf_matches' => false, 'form_revision_matches' => false, 'nonce_server_after' => true]);
});

it('never reflects an unknown diagnostic identifier or request URI', function () {
    $server = array_replace($this->server, ['REQUEST_URI' => '/PRIVATE?code=SECRET']);
    [, $event] = ($this->record)($server, ['diagnostic_id' => 'SECRET-USER-INPUT'], [], [], 'PRIVATE-ID', null, 100, 'other_local_request');
    expect($event['diagnostic_id'])->toBeNull()->and($event['route'])->toBe('other')
        ->and(file_get_contents($this->directory.'/trace-state.json'))->not->toContain('SECRET', 'PRIVATE');
});

it('expires events and correlation references after thirty minutes', function () {
    [$id] = ($this->record)($this->server, [], [], [], 'PRIVATE-ID', null, 100, 'form_created');
    $server = array_replace($this->server, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/oauth/start']);
    [, $event] = ($this->record)($server, ['diagnostic_id' => $id], [], [], 'PRIVATE-ID', 'PRIVATE-ID', 100 + Trace::TTL, 'form_missing_or_already_consumed');
    $state = json_decode(file_get_contents($this->directory.'/trace-state.json'), true);
    expect($state['forms'])->toBeEmpty()->and($state['events'])->toHaveCount(1)
        ->and($event['diagnostic_id'])->toBeNull()->and($event['session_continuity'])->toBe('unknown');
});

it('decorates only with generated metadata and leaves all OAuth validation fields unchanged', function () {
    $id = str_repeat('b', 32);
    $html = '<form><input name="csrf" value="PRIVATE-CSRF"></form>';
    expect(Trace::decorate($html, $id, $this->revision))->toContain('name="diagnostic_id" value="'.$id.'"', 'PRIVATE-CSRF', $this->revision);
    $post = Trace::oauthPost(['diagnostic_id' => $id, 'csrf' => 'PRIVATE-CSRF', 'unexpected' => 'value']);
    expect($post)->toBe(['csrf' => 'PRIVATE-CSRF', 'unexpected' => 'value']);
    $server = array_replace($this->server, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/oauth/start']);
    expect(Web::startObservation($server, $post, ['csrf' => 'PRIVATE-CSRF'])['reason'])->toBe('unexpected_form_fields');
    $server['HTTP_ORIGIN'] = 'null';
    expect(Web::startObservation($server, ['csrf' => 'PRIVATE-CSRF'], ['csrf' => 'PRIVATE-CSRF'])['reason'])->toBe('origin_invalid');
});

it('bounds the number of events even under sustained traffic', function () {
    for ($i = 0; $i < 520; $i++) {
        ($this->record)($this->server, [], [], [], 'PRIVATE-ID', null, 100, 'form_created');
    }
    $state = json_decode(file_get_contents($this->directory.'/trace-state.json'), true);
    expect($state['events'])->toHaveCount(512)->and(count($state['forms']))->toBeLessThanOrEqual(257)
        ->and($state['sequence'])->toBe(520);
});
