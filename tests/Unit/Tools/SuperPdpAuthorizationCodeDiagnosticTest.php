<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use SimpleDevis\Tools\SuperPdp\AuthorizationCodeDiagnostic as Diagnostic;
use SimpleDevis\Tools\SuperPdp\AuthorizationCodeProfile as Profile;
use SimpleDevis\Tools\SuperPdp\AuthorizationCodeWeb as Web;
use SimpleDevis\Tools\SuperPdp\DiagnosticProfile;

require_once dirname(__DIR__, 3).'/tools/support/SuperPdpAuthorizationCodeDiagnostic.php';

// Plain PHPUnit TestCase: no Laravel, database, migrations, storage or native HTTP.
beforeEach(function () {
    $this->text = implode("\n", [
        'APP_ENV=sandbox', 'SUPERPDP_ENVIRONMENT=sandbox', 'APP_URL=http://127.0.0.1:8000',
        'SUPERPDP_CLIENT_ID=TEST-CLIENT', 'SUPERPDP_CLIENT_SECRET=SECRET-NEVER-PRINT',
        'SUPERPDP_API_URL='.DiagnosticProfile::API, 'SUPERPDP_TOKEN_URL='.DiagnosticProfile::TOKEN,
        'SUPERPDP_AUTHORIZE_URL='.Profile::AUTHORIZE, 'DB_CONNECTION=forbidden', 'DB_PASSWORD=DO-NOT-LOAD',
    ]);
    $this->profile = Profile::fromText($this->text);
    $this->session = [];
    $this->now = time();
    $this->url = $this->profile->begin($this->session, $this->now);
    $this->query = http_build_query(['state' => $this->session['pending']['state'], 'code' => 'CODE-NEVER-PRINT']);
    $this->tokens = ['access_token' => 'TOKEN-NEVER-PRINT', 'refresh_token' => 'REFRESH-NEVER-PRINT',
        'token_type' => 'Bearer', 'expires_in' => 900, 'company_id' => 18172];
    $this->remoteSession = ['client_id' => 'TEST-CLIENT', 'created_at' => '2026-11-12T10:00:00Z',
        'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'];
    $this->company = ['id' => 18172, 'created_at' => '2026-11-12T10:00:00Z', 'country' => 'FR',
        'env' => 'sandbox', 'number_scheme' => 'sandbox', 'number' => '000000002'];
    $this->response = fn ($data, $status = 200) => new Response($status, [], is_string($data) ? $data : json_encode($data));
    $this->run = function (array $overrides = []) {
        $handler = new MockHandler([
            ($this->response)($overrides['token'] ?? $this->tokens),
            ($this->response)($overrides['session'] ?? $this->remoteSession),
            ($this->response)($overrides['company'] ?? $this->company),
        ]);

        return Diagnostic::simulate($this->profile, $this->session, $this->query, $handler, $this->now);
    };
    $this->server = ['HTTP_HOST' => '127.0.0.1:8765', 'SERVER_PORT' => '8765', 'REMOTE_ADDR' => '127.0.0.1',
        'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_ORIGIN' => Profile::LOCAL];
});

it('builds the exact sandbox consent with independent random state and RFC7636 S256 PKCE', function () {
    parse_str(parse_url($this->url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['response_type' => 'code', 'redirect_uri' => Profile::CALLBACK,
        'superpdp_company_number_scheme' => 'sandbox', 'superpdp_company_number' => '000000002',
        'scope' => '', 'code_challenge_method' => 'S256']);
    $verifier = $this->session['pending']['verifier'];
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and(strlen($verifier))->toBeGreaterThanOrEqual(43)->and($query)->not->toHaveKeys(['client_secret', 'code_verifier']);
    $old = $this->session['pending'];
    $this->profile->begin($this->session, $this->now);
    expect($this->session['pending']['state'])->not->toBe($old['state'])
        ->and($this->session['pending']['verifier'])->not->toBe($old['verifier']);
});

it('uses only token session company requests and returns a redacted diagnostic', function () {
    $seen = [];
    $handler = null;
    $handler = new MockHandler([($this->response)($this->tokens), ($this->response)($this->remoteSession), ($this->response)($this->company)],
        function () use (&$handler, &$seen) {
            $seen[] = [$handler->getLastRequest(), $handler->getLastOptions()];
        });
    $pending = $this->session['pending'];
    $result = Diagnostic::simulate($this->profile, $this->session, $this->query, $handler, $this->now);
    expect($result)->toMatchArray(['mode' => 'simulated', 'oauth' => 'passed', 'seller_session' => 'passed',
        'seller_identity' => 'passed', 'provider_company_id' => 18172, 'requests' => 3, 'error' => null]);
    expect(array_map(fn ($entry) => [$entry[0]->getMethod(), (string) $entry[0]->getUri()], $seen))->toBe([
        ['POST', DiagnosticProfile::TOKEN], ['GET', Profile::SESSION], ['GET', DiagnosticProfile::COMPANY],
    ]);
    parse_str((string) $seen[0][0]->getBody(), $form);
    expect($form)->toBe(['grant_type' => 'authorization_code', 'client_id' => 'TEST-CLIENT',
        'client_secret' => 'SECRET-NEVER-PRINT', 'redirect_uri' => Profile::CALLBACK, 'code' => 'CODE-NEVER-PRINT', 'code_verifier' => $pending['verifier']]);
    expect($seen[0][0]->hasHeader('Authorization'))->toBeFalse();
    foreach ($seen as $i => [$request, $options]) {
        expect($options['allow_redirects'])->toBeFalse()->and($options['verify'])->toBeTrue()
            ->and($options['debug'])->toBeFalse()->and($options['proxy'])->toBe('');
        if ($i > 0) {
            expect($request->getHeaderLine('Authorization'))->toBe('Bearer TOKEN-NEVER-PRINT')
                ->and((string) $request->getBody())->toBe('');
        }
    }
    expect($this->session)->toBe([])->and(json_encode($result))->not->toContain('NEVER-PRINT', 'TEST-CLIENT', $pending['state'], $pending['verifier']);
    $replay = Diagnostic::simulate($this->profile, $this->session, $this->query, new MockHandler([]), $this->now);
    expect($replay['requests'])->toBe(0)->and($replay['error'])->toBe('callback_invalid');
});

it('consumes invalid callbacks without choosing or using a transport', function (string $case) {
    $query = $this->query;
    match ($case) {
        'state' => $query = 'state=wrong&code=secret',
        'missing_state' => $query = 'code=secret',
        'duplicate' => $query .= '&code=second',
        'encoded_duplicate' => $query .= '&%63ode=second',
        'array' => $query .= '&code[]=second',
        'unknown' => $query .= '&unexpected=yes',
        'expired' => $this->session['pending']['created_at'] = $this->now - 601,
        'future' => $this->session['pending']['created_at'] = $this->now + 1,
        'config' => $this->session['pending']['profile'] = 'changed',
        'verifier' => $this->session['pending']['verifier'] = '',
        'empty_code' => $query = 'state='.$this->session['pending']['state'].'&code=',
        'injection' => $query .= '%0A',
        'oversize' => $query .= str_repeat('x', 16384),
    };
    $result = Diagnostic::simulate($this->profile, $this->session, $query, new MockHandler([]), $this->now);
    expect($result['requests'])->toBe(0)->and($result['error'])->toBe('callback_invalid')->and($this->session)->toBe([]);
})->with(['state', 'missing_state', 'duplicate', 'encoded_duplicate', 'array', 'unknown', 'expired', 'future', 'config', 'verifier', 'empty_code', 'injection', 'oversize']);

it('redacts denial descriptions and consumes consent denial', function () {
    $query = 'state='.$this->session['pending']['state'].'&error=access_denied&error_description=PRIVATE-DETAILS';
    $result = Diagnostic::simulate($this->profile, $this->session, $query, new MockHandler([]), $this->now);
    expect($result['error'])->toBe('consent_refused')->and($result['requests'])->toBe(0)
        ->and(json_encode($result))->not->toContain('PRIVATE')->and($this->session)->toBe([]);
});

it('keeps live execution disabled without explicit authorization', function () {
    $result = Diagnostic::live($this->profile, $this->session, $this->query);
    expect($result['error'])->toBe('live_disabled')->and($result['requests'])->toBe(0)->and($this->session)->toBe([]);
});

it('refuses unverified missing malformed and foreign sessions before reading company', function (string $field, mixed $value) {
    $this->remoteSession[$field] = $value;
    $result = ($this->run)();
    expect($result['seller_session'])->toBe('not_confirmed')->and($result['seller_identity'])->toBe('not_confirmed')
        ->and($result['requests'])->toBe(2)->and($result['provider_company_id'])->toBeNull();
})->with([
    ['client_id', 'OTHER'], ['created_at', null], ['created_at', 'invalid'],
    ['company_verification_status', 'needs_review'], ['company_verification_status', 'failed'], ['company_verification_status', null],
    ['company_verification_status', 'future_status'], ['user_identity_verification_status', 'needs_review'],
    ['user_identity_verification_status', 'failed'], ['user_identity_verification_status', 'not_verified'],
    ['user_identity_verification_status', null], ['user_identity_verification_status', 'future_status'],
]);

it('refuses any identity or token company binding mismatch', function (string $field, mixed $value) {
    $this->company[$field] = $value;
    $result = ($this->run)();
    expect($result['seller_identity'])->toBe('not_confirmed')->and($result['provider_company_id'])->toBeNull()
        ->and($result['error'])->toBe('company_mismatch')->and($result['requests'])->toBe(3);
})->with([
    ['id', 1], ['id', '18172'], ['id', 0], ['id', null], ['number', '000000001'], ['number', 2], ['number', '315143296'],
    ['number_scheme', 'fr_siren'], ['env', 'production'], ['env', null], ['country', 'BE'], ['created_at', null],
]);

it('discovers a supplier ID without assuming it from the number or address suffix', function () {
    unset($this->tokens['company_id']);
    $this->company['id'] = 987654;
    $result = ($this->run)();
    expect($result['error'])->toBeNull()->and($result['provider_company_id'])->toBe(987654);
});

it('does not require or persist refresh tokens to run a diagnostic', function () {
    unset($this->tokens['refresh_token']);
    $result = ($this->run)();
    expect($result['error'])->toBeNull()->and($result['refresh_token_present'])->toBeFalse()->and($this->session)->toBe([]);
});

it('rejects invalid token responses before session calls', function (string $field, mixed $value) {
    $this->tokens[$field] = $value;
    $result = ($this->run)();
    expect($result['requests'])->toBe(1)->and($result['oauth'])->toBe('not_confirmed')
        ->and($result['error'])->not->toBeNull();
})->with([['access_token', ''], ['access_token', "token\r\ninjection"], ['access_token', []], ['token_type', 'MAC'],
    ['expires_in', 0], ['expires_in', '900'], ['company_id', '18172'], ['company_id', null]]);

it('stops on HTTP redirects failures invalid JSON duplicate keys and oversized bodies without disclosure', function (int $phase, string $body, int $status) {
    $responses = [($this->response)($this->tokens), ($this->response)($this->remoteSession), ($this->response)($this->company)];
    $responses[$phase] = new Response($status, ['Location' => 'https://foreign.invalid'], $body);
    $result = Diagnostic::simulate($this->profile, $this->session, $this->query, new MockHandler($responses), $this->now);
    expect($result['requests'])->toBe($phase + 1)->and($result['error'])->not->toBeNull()
        ->and(json_encode($result))->not->toContain('PRIVATE', 'foreign.invalid', 'NEVER-PRINT');
})->with([
    [0, '{"error_description":"PRIVATE"}', 302], [0, '{"error_description":"PRIVATE"}', 401],
    [1, '{"error_description":"PRIVATE"}', 403], [2, '{"error_description":"PRIVATE"}', 503],
    [0, '{"access_token":"PRIVATE","access_token":"second"}', 200], [1, '[]', 200], [2, 'PRIVATE', 200], [2, str_repeat('x', 65537), 200],
]);

it('rejects production foreign and ambiguous profiles without loading database settings', function (string $case) {
    $text = match ($case) {
        'app' => str_replace('APP_ENV=sandbox', 'APP_ENV=production', $this->text),
        'provider' => str_replace('SUPERPDP_ENVIRONMENT=sandbox', 'SUPERPDP_ENVIRONMENT=production', $this->text),
        'authorize' => str_replace(Profile::AUTHORIZE, 'https://foreign.invalid/oauth2/authorize', $this->text),
        'token' => str_replace(DiagnosticProfile::TOKEN, 'https://foreign.invalid/oauth2/token', $this->text),
        'api' => str_replace(DiagnosticProfile::API, 'https://foreign.invalid/v1.beta', $this->text),
        'local' => str_replace('http://127.0.0.1:8000', 'http://localhost:8000', $this->text),
        'duplicate' => $this->text."\nSUPERPDP_AUTHORIZE_URL=".Profile::AUTHORIZE,
        'interpolation' => str_replace('SECRET-NEVER-PRINT', '${SECRET}', $this->text),
    };
    expect(fn () => Profile::fromText($text))->toThrow(RuntimeException::class);
})->with(['app', 'provider', 'authorize', 'token', 'api', 'local', 'duplicate', 'interpolation']);

it('rejects nonlocal hosts remote clients wrong ports and disabled routes', function (string $field, mixed $value) {
    $this->server[$field] = $value;
    expect(Web::allowed($this->server, true))->toBeFalse();
    [$status] = Web::handle($this->profile, $this->server, [], $this->session, true, new MockHandler([]));
    expect($status)->toBe(403);
})->with([['HTTP_HOST', 'localhost:8765'], ['HTTP_HOST', 'foreign.invalid'], ['REMOTE_ADDR', '192.0.2.1'], ['SERVER_PORT', '8000']]);

it('uses a local CSRF protected POST before redirecting and never renders credentials', function () {
    [$disabled] = Web::handle($this->profile, $this->server, [], $this->session);
    expect($disabled)->toBe(403);
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, [], $this->session, true, new MockHandler([]));
    expect($status)->toBe(200)->and($body)->toContain('method="post"')->not->toContain('SECRET-', 'TOKEN-');
    $csrf = $this->session['csrf'];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, $headers] = Web::handle($this->profile, $this->server, ['csrf' => $csrf], $this->session, true, new MockHandler([]));
    expect($status)->toBe(303)->and($headers['Location'])->toStartWith(Profile::AUTHORIZE.'?');
    [$replay] = Web::handle($this->profile, $this->server, ['csrf' => $csrf], $this->session, true, new MockHandler([]));
    expect($replay)->toBe(403);
});

it('refuses cross site starts and unknown routes including static file requests', function (string $method, string $uri, string $origin) {
    $this->session['csrf'] = 'csrf';
    $this->server = array_replace($this->server, ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTP_ORIGIN' => $origin]);
    [$status] = Web::handle($this->profile, $this->server, ['csrf' => 'csrf'], $this->session, true, new MockHandler([]));
    expect($status)->toBeGreaterThanOrEqual(400);
})->with([['POST', '/oauth/start', 'https://foreign.invalid'], ['GET', '/oauth/start', Profile::LOCAL],
    ['GET', '/.env.sandbox', Profile::LOCAL], ['GET', '/public/index.php', Profile::LOCAL], ['POST', '/oauth/callback', Profile::LOCAL]]);

it('handles callback through the dedicated HTTP path with an expurgated response', function () {
    $this->server['REQUEST_URI'] = '/oauth/callback?'.$this->query;
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, [], $this->session, true,
        new MockHandler([($this->response)($this->tokens), ($this->response)($this->remoteSession), ($this->response)($this->company)]));
    expect($status)->toBe(200)->and($headers['Content-Type'])->toContain('application/json')
        ->and(json_decode($body, true)['seller_identity'])->toBe('passed')->and($body)->not->toContain('NEVER-PRINT');
});

it('preserves the form POST Origin while suppressing callback referrers and permitting only the intended consent redirect', function () {
    $home = Web::navigationHeaders('/');
    expect($home['Referrer-Policy'])->toBe('same-origin')
        ->and($home['Content-Security-Policy'])->toBe("default-src 'none'; form-action 'self' ".Profile::AUTHORIZE."; frame-ancestors 'none'; base-uri 'none'");
    expect(Web::navigationHeaders('/oauth/start')['Content-Security-Policy'])->toBe($home['Content-Security-Policy']);
    foreach (['/oauth/callback?code=PRIVATE&state=PRIVATE', '/unknown', '/?unexpected=1'] as $uri) {
        $headers = Web::navigationHeaders($uri);
        expect($headers['Referrer-Policy'])->toBe('no-referrer')
            ->and($headers['Content-Security-Policy'])->not->toContain('https://', 'PRIVATE');
    }
});

it('still refuses null missing and foreign Origin with an otherwise valid form nonce', function (?string $origin) {
    Web::handle($this->profile, $this->server, [], $this->session, true, new MockHandler([]));
    $csrf = $this->session['csrf'];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    $this->server['HTTP_ORIGIN'] = $origin;
    [$status, $headers] = Web::handle($this->profile, $this->server, ['csrf' => $csrf], $this->session, true, new MockHandler([]));
    expect($status)->toBe(403)->and($headers)->not->toHaveKey('Location');
})->with([['null'], [null], ['https://foreign.invalid']]);

it('requires the cookie backed CSRF session and rejects changed or extra form fields before creating state', function (string $case) {
    $this->session = [];
    [$status, $headers, $html] = Web::handle($this->profile, $this->server, [], $this->session, true, new MockHandler([]));
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match);
    expect($status)->toBe(200)->and($match[1])->toBe($this->session['csrf']);
    $post = ['csrf' => $match[1]];
    match ($case) {
        'cookie_missing' => $this->session = [],
        'nonce_changed' => $post['csrf'] = 'wrong',
        'nonce_missing' => $post = [],
        'extra_field' => $post['unexpected'] = 'value',
        'array' => $post['csrf'] = [$match[1]],
    };
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, $headers] = Web::handle($this->profile, $this->server, $post, $this->session, true, new MockHandler([]));
    expect($status)->toBe(403)->and($headers)->not->toHaveKey('Location')
        ->and($this->session)->not->toHaveKey('pending');
    if ($case === 'cookie_missing') {
        expect($this->session)->not->toHaveKey('csrf');
    } else {
        expect($this->session['csrf'])->toBe($match[1]);
    }
})->with(['cookie_missing', 'nonce_changed', 'nonce_missing', 'extra_field', 'array']);

it('preserves the legitimate form after a refused POST and consumes it exactly once on acceptance', function (bool $inspect, string $case) {
    $this->session = [];
    Web::handle($this->profile, $this->server, [], $this->session, true, null, $inspect);
    $nonce = $this->session['csrf'];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    $post = ['csrf' => $nonce];
    match ($case) {
        'null_origin' => $this->server['HTTP_ORIGIN'] = 'null',
        'missing_origin' => $this->server['HTTP_ORIGIN'] = null,
        'foreign_origin' => $this->server['HTTP_ORIGIN'] = 'https://foreign.invalid',
        'nonce_changed' => $post['csrf'] = 'wrong',
        'nonce_missing' => $post = [],
        'nonce_array' => $post['csrf'] = [$nonce],
        'extra_field' => $post['unexpected'] = 'value',
    };
    $mock = new MockHandler([]);
    [$refused, $headers] = Web::handle($this->profile, $this->server, $post, $this->session, true, $mock, $inspect);
    expect($refused)->toBe(403)->and($headers)->not->toHaveKey('Location')
        ->and($this->session['csrf'])->toBe($nonce)->and($this->session)->not->toHaveKey('pending');

    $this->server['HTTP_ORIGIN'] = Profile::LOCAL;
    [$accepted] = Web::handle($this->profile, $this->server, ['csrf' => $nonce], $this->session, true, $mock, $inspect);
    expect($accepted)->toBe($inspect ? 200 : 303)->and($this->session)->not->toHaveKey('csrf');
    [$replayed, $headers] = Web::handle($this->profile, $this->server, ['csrf' => $nonce], $this->session, true, $mock, $inspect);
    expect($replayed)->toBe(403)->and($headers)->not->toHaveKey('Location')->and($mock->getLastRequest())->toBeNull();
})->with([false, true])->with(['null_origin', 'missing_origin', 'foreign_origin', 'nonce_changed', 'nonce_missing', 'nonce_array', 'extra_field']);

it('keeps the newest form usable when an older tab submits a stale nonce', function (bool $inspect) {
    $this->session = [];
    Web::handle($this->profile, $this->server, [], $this->session, true, null, $inspect);
    $old = $this->session['csrf'];
    Web::handle($this->profile, $this->server, [], $this->session, true, null, $inspect);
    $fresh = $this->session['csrf'];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status] = Web::handle($this->profile, $this->server, ['csrf' => $old], $this->session, true, null, $inspect);
    expect($status)->toBe(403)->and($this->session['csrf'])->toBe($fresh)
        ->and($this->session)->not->toHaveKey('pending');
    [$status] = Web::handle($this->profile, $this->server, ['csrf' => $fresh], $this->session, true, null, $inspect);
    expect($status)->toBe($inspect ? 200 : 303)->and($this->session)->not->toHaveKey('csrf');
})->with([false, true]);

it('creates state only after the exact nonce from the HTML is posted with the same session and local origin', function () {
    $this->session = [];
    [, , $html] = Web::handle($this->profile, $this->server, [], $this->session, true, new MockHandler([]));
    expect($this->session)->not->toHaveKey('pending');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match);
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    $mock = new MockHandler([]);
    [$status, $headers] = Web::handle($this->profile, $this->server, ['csrf' => $match[1]], $this->session, true, $mock);
    expect($status)->toBe(303)->and($this->session)->toHaveKey('pending')->not->toHaveKey('csrf')
        ->and($mock->getLastRequest())->toBeNull();
    parse_str(parse_url($headers['Location'], PHP_URL_QUERY), $query);
    expect($query['state'])->toBe($this->session['pending']['state']);
});

it('observes real start inputs without creating state redirecting or selecting any HTTP transport', function () {
    $this->session = [];
    [, , $html] = Web::handle($this->profile, $this->server, [], $this->session, true, null, true);
    expect($html)->toContain('Tester le formulaire local (sans OAuth)');
    $post = ['csrf' => $this->session['csrf']];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, $post, $this->session, true, null, true);
    $result = json_decode($body, true);
    expect($status)->toBe(200)->and($headers)->not->toHaveKey('Location')
        ->and($result)->toMatchArray(['source' => 'local_diagnostic', 'mode' => 'start_inspection',
            'accepted' => true, 'failed_checks' => [], 'provider_requests' => 0])
        ->and($this->session)->not->toHaveKeys(['csrf', 'pending'])->and($body)->not->toContain($post['csrf']);
});

it('reports the exact failed start predicate using only safe classifications', function (string $case, string $failed) {
    $this->session = ['csrf' => 'PRIVATE-NONCE'];
    $post = ['csrf' => 'PRIVATE-NONCE'];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    match ($case) {
        'method' => $this->server['REQUEST_METHOD'] = 'GET',
        'uri' => $this->server['REQUEST_URI'] .= '?PRIVATE-QUERY',
        'null_origin' => $this->server['HTTP_ORIGIN'] = 'null',
        'missing_origin' => $this->server['HTTP_ORIGIN'] = null,
        'foreign_origin' => $this->server['HTTP_ORIGIN'] = 'https://PRIVATE-ORIGIN.invalid',
        'session' => $this->session = [],
        'form' => $post = [],
        'mismatch' => $post['csrf'] = 'PRIVATE-WRONG',
        'extra' => $post['PRIVATE-FIELD'] = 'PRIVATE-VALUE',
    };
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, $post, $this->session, true, null, true);
    $result = json_decode($body, true);
    expect($status)->toBe(403)->and($headers)->not->toHaveKey('Location')
        ->and($result['failed_checks'])->toContain($failed)->and($result['accepted'])->toBeFalse()
        ->and($body)->not->toContain('PRIVATE', 'SECRET', 'TOKEN')->and($result['provider_requests'])->toBe(0);
})->with([
    ['method', 'method_post'], ['uri', 'uri_exact'], ['null_origin', 'origin_expected'],
    ['missing_origin', 'origin_expected'], ['foreign_origin', 'origin_expected'],
    ['session', 'session_csrf_present'], ['form', 'submitted_csrf_present'],
    ['mismatch', 'csrf_matches'], ['extra', 'fields_expected'],
]);

it('pauses even a valid callback while inspecting the browser start request', function () {
    $this->server['REQUEST_URI'] = '/oauth/callback?'.$this->query;
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, [], $this->session, true, null, true);
    expect($status)->toBe(423)->and($headers)->not->toHaveKey('Location')
        ->and(json_decode($body, true))->toMatchArray(['error' => 'oauth_paused', 'provider_requests' => 0])
        ->and($this->session)->not->toHaveKey('pending')->and($body)->not->toContain('CODE-', 'NEVER-PRINT');
});

it('requires explicit process configuration to leave the default observation mode', function () {
    foreach ([null, '', 'observe'] as $mode) {
        expect(Web::inspectionMode($mode))->toBeTrue();
    }
    expect(Web::inspectionMode('oauth'))->toBeFalse();
    foreach (['live', 'production', 'OAUTH', '1'] as $mode) {
        expect(fn () => Web::inspectionMode($mode))->toThrow(RuntimeException::class);
    }
});

it('reproduces a consumed form with its session intact and requires a new GET without weakening CSRF', function () {
    $this->session = [];
    [, , $html] = Web::handle($this->profile, $this->server, [], $this->session, true, null, true);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $firstForm);
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, , $body] = Web::handle($this->profile, $this->server, ['csrf' => $firstForm[1]], $this->session, true, null, true);
    expect($status)->toBe(200)->and(json_decode($body, true)['accepted'])->toBeTrue();
    [$status, , $body] = Web::handle($this->profile, $this->server, ['csrf' => $firstForm[1]], $this->session, true, null, true);
    $refused = json_decode($body, true);
    expect($status)->toBe(403)->and($refused['checks']['session_csrf_present'])->toBeFalse()
        ->and($refused['reason'])->toBe('form_missing_or_already_consumed')
        ->and($refused['next_action'])->toBe('reload_local_form_then_submit_once');
    $this->server['REQUEST_METHOD'] = 'GET';
    $this->server['REQUEST_URI'] = '/';
    [, , $html] = Web::handle($this->profile, $this->server, [], $this->session, true, null, true);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $secondForm);
    expect($secondForm[1])->not->toBe($firstForm[1]);
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, , $body] = Web::handle($this->profile, $this->server, ['csrf' => $secondForm[1]], $this->session, true, null, true);
    expect($status)->toBe(200)->and(json_decode($body, true)['accepted'])->toBeTrue()
        ->and(json_decode($body, true)['provider_requests'])->toBe(0);
});

it('explains a consumed form in OAuth mode without relaxing any check or calling a provider', function () {
    $this->session = [];
    $this->server['REQUEST_METHOD'] = 'POST';
    $this->server['REQUEST_URI'] = '/oauth/start';
    [$status, $headers, $body] = Web::handle($this->profile, $this->server, ['csrf' => 'PRIVATE-OLD-NONCE'], $this->session, true);
    expect($status)->toBe(403)->and($headers)->not->toHaveKey('Location')
        ->and(json_decode($body, true))->toMatchArray(['error' => 'request_refused',
            'reason' => 'form_missing_or_already_consumed', 'next_action' => 'reload_local_form_then_submit_once', 'provider_requests' => 0])
        ->and($body)->not->toContain('PRIVATE-OLD-NONCE');
});
