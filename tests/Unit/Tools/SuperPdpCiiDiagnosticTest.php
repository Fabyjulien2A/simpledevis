<?php

use App\Services\SuperPdp\CiiDocumentChecks;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Saloon\Config;
use SimpleDevis\Tools\SuperPdp\CiiDiagnostic;
use SimpleDevis\Tools\SuperPdp\CiiProbeFailure;
use SimpleDevis\Tools\SuperPdp\CiiProbeHandler;
use SimpleDevis\Tools\SuperPdp\CiiProbeInput;
use SimpleDevis\Tools\SuperPdp\CiiProbeProfile;
use SimpleDevis\Tools\SuperPdp\CiiProbeStorage;
use Tests\Support\CapturedSandboxCii;

require_once dirname(__DIR__, 3).'/tools/support/SuperPdpCiiDiagnostic.php';

beforeEach(function () {
    $this->previousApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);
    Config::allowStrayRequests(); // All SDK sends end at an in-memory Guzzle MockHandler.
    $this->directory = sys_get_temp_dir().'/sd-cii-unit-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    $this->storage = new CiiProbeStorage($this->directory);
    $this->xml = CapturedSandboxCii::xml();
    $this->conversion = fn (?string $xml = null) => new Response(200, ['Content-Type' => 'application/xml'], $xml ?? $this->xml);
    $this->report = ['data' => [[
        'file_name' => 'invoice.xml', 'file_size' => strlen($this->xml), 'is_valid' => true,
        'duration' => 1, 'format' => CiiDocumentChecks::NS, 'conformance_level' => 'test-double',
        'subreports' => [['validator' => 'test-double', 'checks_count' => 1, 'failures' => [], 'messages' => []]],
    ]]];
    $this->validation = fn (?array $report = null) => new Response(200, ['Content-Type' => 'application/json'], json_encode($report ?? $this->report));
});

afterEach(function () {
    Config::allowStrayRequests();
    Facade::setFacadeApplication($this->previousApplication);
    // Delete only files/directories below the unique temporary root of this test.
    foreach (glob($this->directory.'/*') as $path) {
        if (is_dir($path)) {
            foreach (glob($path.'/*') as $file) {
                unlink($file);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($this->directory);
});

it('checks the genuine builder and contract offline without booting Laravel or changing the resolver', function () {
    $resolver = Model::getConnectionResolver();
    $result = CiiDiagnostic::check();
    expect($result)->toMatchArray(['mode' => 'offline', 'requests' => 0, 'local_payload' => 'passed',
        'conversion' => 'not_attempted', 'validation' => 'not_attempted', 'ready_for_emission' => false]);
    expect(Facade::getFacadeApplication())->toBeNull()->and(Model::getConnectionResolver())->toBe($resolver);
    $payload = CiiProbeInput::payload();
    expect($payload['totals']['total_with_vat'])->toBe('120.00')
        ->and($payload['seller']['electronic_address']['value'])->toBe('vendeur-fictif@example.invalid');
});

it('uses the real SDK exact two public requests and preserves document bytes with simulated provenance', function () {
    $requests = [];
    $handler = null;
    $handler = new MockHandler([($this->conversion)(), ($this->validation)()], function () use (&$handler, &$requests) {
        $requests[] = $handler->getLastRequest();
    });
    $result = CiiDiagnostic::simulate($handler, $this->storage);
    expect($result)->toMatchArray(['mode' => 'simulated', 'conversion' => 'simulated_success', 'local_cii_consistency' => 'passed',
        'validation' => 'simulated_pass', 'requests' => 2, 'ready_for_emission' => false]);
    expect(count($requests))->toBe(2)->and((string) $requests[0]->getUri())->toBe(CiiProbeInput::CONVERT)
        ->and((string) $requests[1]->getUri())->toBe(CiiProbeInput::VALIDATE);
    foreach ($requests as $request) {
        expect($request->getMethod())->toBe('POST')->and($request->hasHeader('Authorization'))->toBeFalse();
    }
    expect((string) $requests[1]->getBody())->toContain('name="file_name"; filename="invoice.xml"', $this->xml);
    $artifact = $result['artifact_directory'];
    expect(file_get_contents($artifact.'/document.test.cii.xml'))->toBe($this->xml);
    $manifest = json_decode(file_get_contents($artifact.'/manifest.json'), true);
    expect($manifest)->toMatchArray(['mode' => 'simulated', 'ready_for_emission' => false, 'official_archive' => false,
        'cii_sha256' => hash('sha256', $this->xml), 'validation_report_content_persisted' => false]);
    expect(Facade::getFacadeApplication())->toBeNull();
});

it('refuses conversion HTTP errors redirects and wrong response types without a validation call', function (int $status, string $type) {
    $handler = new MockHandler([new Response($status, ['Content-Type' => $type], 'SECRET-REMOTE-ERROR')]);
    $result = CiiDiagnostic::simulate($handler, $this->storage);
    expect($result['requests'])->toBe(1)->and($result['conversion'])->toBe('not_confirmed')
        ->and($result['validation'])->toBe('not_attempted')->and(json_encode($result))->not->toContain('SECRET-REMOTE-ERROR');
})->with([[400, 'application/json'], [401, 'application/json'], [403, 'application/json'], [500, 'application/xml'],
    [302, 'application/xml'], [200, 'text/html'], [200, 'application/json']]);

it('rejects malformed XML DTDs external entities and a wrong CII namespace', function (string $xml) {
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)($xml)]), $this->storage);
    expect($result['requests'])->toBe(1)->and($result['conversion'])->toBe('not_confirmed')
        ->and($result['validation'])->toBe('not_attempted')->and($result['ready_for_emission'])->toBeFalse();
})->with(['', '<broken>', '<CrossIndustryInvoice/>', '<!DOCTYPE x SYSTEM "https://example.invalid/never"><x/>',
    '<!DOCTYPE x [<!ENTITY y SYSTEM "file:///never-read">]><x>&y;</x>', "<x>\0</x>"]);

it('preserves a converted CII with an inconsistent identity or total and refuses validation', function (string $from, string $to) {
    $xml = str_replace($from, $to, $this->xml);
    expect($xml)->not->toBe($this->xml);
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)($xml)]), $this->storage);
    expect($result)->toMatchArray(['conversion' => 'simulated_success', 'local_cii_consistency' => 'failed',
        'validation' => 'not_attempted', 'requests' => 1, 'ready_for_emission' => false]);
    expect(file_get_contents($result['artifact_directory'].'/document.test.cii.xml'))->toBe($xml);
})->with([['SANDBOX-FICTIF-0001', 'WRONG-FICTITIOUS-REFERENCE'], ['120.00', '999.00']]);

it('keeps conversion success distinct from a negative validation and never persists remote messages', function () {
    $this->report['data'][0]['is_valid'] = false;
    $this->report['data'][0]['error'] = 'REMOTE-SECRET-MUST-NOT-APPEAR';
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)(), ($this->validation)()]), $this->storage);
    expect($result)->toMatchArray(['conversion' => 'simulated_success', 'validation' => 'failed', 'requests' => 2, 'ready_for_emission' => false]);
    expect(json_encode($result).file_get_contents($result['artifact_directory'].'/manifest.json'))->not->toContain('REMOTE-SECRET-MUST-NOT-APPEAR');
});

it('does not treat an HTTP 200 incomplete ambiguous or unrelated report as a validation', function (string $case) {
    $report = $this->report;
    match ($case) {
        'empty' => $report = [],
        'scalar_data' => $report = ['data' => 'invalid'],
        'scalar_row' => $report = ['data' => [true]],
        'name' => $report['data'][0]['file_name'] = 'another.xml',
        'size' => $report['data'][0]['file_size']++,
        'format' => $report['data'][0]['format'] = 'unrelated',
        'duplicate' => $report['data'][] = $report['data'][0],
        'is_valid_string' => $report['data'][0]['is_valid'] = 'true',
        'no_checks' => $report['data'][0]['subreports'][0]['checks_count'] = 0,
        'failure' => $report['data'][0]['subreports'][0]['failures'] = ['hidden-failure'],
        'no_subreports' => $report['data'][0]['subreports'] = [],
    };
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)(), ($this->validation)($report)]), $this->storage);
    expect($result['conversion'])->toBe('simulated_success')->and($result['validation'])->toBe('not_confirmed')
        ->and($result['requests'])->toBe(2)->and($result['ready_for_emission'])->toBeFalse();
})->with(['empty', 'scalar_data', 'scalar_row', 'name', 'size', 'format', 'duplicate', 'is_valid_string', 'no_checks', 'failure', 'no_subreports']);

it('handles validation connection failures and keeps the known converted bytes', function () {
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)(), new RuntimeException('NETWORK-SECRET')]), $this->storage);
    expect($result)->toMatchArray(['conversion' => 'simulated_success', 'validation' => 'not_confirmed', 'requests' => 2]);
    expect(file_get_contents($result['artifact_directory'].'/document.test.cii.xml'))->toBe($this->xml)
        ->and(json_encode($result))->not->toContain('NETWORK-SECRET');
});

it('rejects non sandbox profiles foreign origins and duplicate or interpolated variables', function (string $case) {
    $text = "APP_ENV=sandbox\nSUPERPDP_ENVIRONMENT=sandbox\nSUPERPDP_API_URL=".CiiProbeInput::API;
    $text = match ($case) {
        'app' => str_replace('APP_ENV=sandbox', 'APP_ENV=production', $text),
        'environment' => str_replace('SUPERPDP_ENVIRONMENT=sandbox', 'SUPERPDP_ENVIRONMENT=production', $text),
        'origin' => str_replace(CiiProbeInput::API, 'https://foreign.invalid/v1.beta', $text),
        'query' => $text.'?unexpected=1',
        'slash' => $text.'/',
        'duplicate' => $text."\nSUPERPDP_ENVIRONMENT=sandbox",
        'interpolation' => str_replace('APP_ENV=sandbox', 'APP_ENV=${DO_NOT_RESOLVE}', $text),
    };
    file_put_contents($this->directory.'/profile', $text);
    expect(fn () => CiiProbeInput::profile($this->directory.'/profile'))->toThrow(CiiProbeFailure::class);
})->with(['app', 'environment', 'origin', 'query', 'slash', 'duplicate', 'interpolation']);

it('ignores database configuration and OAuth secrets entirely when checking its profile', function () {
    file_put_contents($this->directory.'/profile', "APP_ENV=sandbox\nSUPERPDP_ENVIRONMENT=sandbox\nSUPERPDP_API_URL=".CiiProbeInput::API
        ."\nDB_URL=never-connect\nSUPERPDP_CLIENT_SECRET=\"\${DO_NOT_RESOLVE}\"\nUNRELATED=\"broken");
    CiiProbeInput::profile($this->directory.'/profile');
    expect(true)->toBeTrue();
});

it('blocks a deposit OAuth foreign host or unexpected method at the final HTTP boundary', function (string $url, string $method) {
    $payload = CiiProbeInput::payload();
    $json = json_encode($payload);
    $backend = new MockHandler([]);
    $guard = new CiiProbeHandler($json, $payload, $backend);
    $request = new Request($method, $url, ['Content-Type' => 'application/json', 'Accept' => 'application/xml'], $json);
    expect(fn () => $guard($request, ['verify' => true, 'allow_redirects' => false]))->toThrow(CiiProbeFailure::class);
    expect($guard->requests)->toBe(0);
})->with([[CiiProbeInput::API.'/invoices', 'POST'], [CiiProbeInput::ORIGIN.'/oauth2/token', 'POST'],
    ['https://foreign.invalid/v1.beta/invoices/convert?from=en16931&to=cii', 'POST'],
    [CiiProbeInput::VALIDATE, 'POST'], [CiiProbeInput::CONVERT, 'GET'], [CiiProbeInput::CONVERT.'#x', 'POST']]);

it('refuses insecure TLS redirects debug authenticated requests and altered input bytes', function (string $case) {
    $payload = CiiProbeInput::payload();
    $json = json_encode($payload);
    $guard = new CiiProbeHandler($json, $payload, new MockHandler([]));
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/xml'];
    $options = ['verify' => true, 'allow_redirects' => false, 'debug' => false];
    $body = $json;
    match ($case) {
        'tls' => $options['verify'] = false,
        'redirect' => $options['allow_redirects'] = true,
        'debug' => $options['debug'] = true,
        'token' => $headers['Authorization'] = 'Bearer NEVER-SEND',
        'body' => $body .= ' ',
    };
    expect(fn () => $guard(new Request('POST', CiiProbeInput::CONVERT, $headers, $body), $options))->toThrow(CiiProbeFailure::class);
    expect($guard->requests)->toBe(0);
})->with(['tls', 'redirect', 'debug', 'token', 'body']);

it('refuses storage outside the owned run directory', function () {
    expect(fn () => $this->storage->begin('../outside', '{}'))->toThrow(CiiProbeFailure::class);
    expect(fn () => $this->storage->write(dirname($this->directory), 'manifest.json', '{}'))->toThrow(CiiProbeFailure::class);
    expect(fn () => $this->storage->write($this->directory, '../unsafe', '{}'))->toThrow(CiiProbeFailure::class);
});

it('requires explicit live confirmation before choosing any network transport', function () {
    file_put_contents($this->directory.'/profile', "APP_ENV=sandbox\nSUPERPDP_ENVIRONMENT=sandbox\nSUPERPDP_API_URL=".CiiProbeInput::API);
    $profile = CiiProbeProfile::fromFile($this->directory.'/profile');
    $result = CiiDiagnostic::live($profile, $this->storage);
    expect($result['requests'])->toBe(0)->and($result['error'])->toBe('explicit_confirmation_required')
        ->and($result['conversion'])->toBe('not_attempted')->and(glob($this->directory.'/live-*'))->toBe([]);
});

it('keeps the actual two request count when private manifest persistence fails', function () {
    $root = $this->directory;
    $handler = null;
    $handler = new MockHandler([($this->conversion)(), ($this->validation)()], function () use (&$handler, $root) {
        if ((string) $handler->getLastRequest()->getUri() === CiiProbeInput::VALIDATE) {
            mkdir(glob($root.'/simulated-*')[0].'/manifest.json');
        }
    });
    $result = CiiDiagnostic::simulate($handler, $this->storage);
    rmdir($result['artifact_directory'].'/manifest.json');
    expect($result['requests'])->toBe(2)->and($result['error'])->toBe('private_manifest_write_failed')
        ->and($result['ready_for_emission'])->toBeFalse();
});

it('blocks a third request and altered validation multipart bytes at the transport boundary', function () {
    $payload = CiiProbeInput::payload();
    $json = json_encode($payload);
    $guard = new CiiProbeHandler($json, $payload, new MockHandler([($this->conversion)(), ($this->validation)()]));
    $options = ['verify' => true, 'allow_redirects' => false, 'debug' => false];
    $guard(new Request('POST', CiiProbeInput::CONVERT, ['Content-Type' => 'application/json', 'Accept' => 'application/xml'], $json), $options)->wait();
    $guard->allowValidation($this->xml);
    $headers = ['Content-Type' => 'multipart/form-data; boundary=test-boundary', 'Accept' => 'application/json'];
    expect(fn () => $guard(new Request('POST', CiiProbeInput::VALIDATE, $headers, 'altered bytes'), $options))->toThrow(CiiProbeFailure::class);
    $body = new MultipartStream([['name' => 'file_name', 'filename' => 'invoice.xml', 'contents' => $this->xml]], 'test-boundary');
    $request = new Request('POST', CiiProbeInput::VALIDATE, $headers, $body);
    $guard($request, $options)->wait();
    expect($guard->requests)->toBe(2);
    expect(fn () => $guard($request, $options))->toThrow(CiiProbeFailure::class);
    expect($guard->requests)->toBe(2);
});

it('rejects malformed JSON duplicate JSON keys oversized bodies and validation redirects without promotion', function (string $case) {
    $bad = match ($case) {
        'json' => new Response(200, ['Content-Type' => 'application/json'], '{"data":'),
        'duplicates' => new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"data":[]}'),
        'oversized' => new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 5242881)),
        'redirect' => new Response(302, ['Location' => 'https://foreign.invalid/', 'Content-Type' => 'application/json'], ''),
        'html' => new Response(200, ['Content-Type' => 'text/html'], '<p>REMOTE-SECRET</p>'),
    };
    $result = CiiDiagnostic::simulate(new MockHandler([($this->conversion)(), $bad]), $this->storage);
    expect($result['requests'])->toBe(2)->and($result['conversion'])->toBe('simulated_success')
        ->and($result['validation'])->toBe('not_confirmed')->and($result['ready_for_emission'])->toBeFalse();
    expect(json_encode($result).file_get_contents($result['artifact_directory'].'/manifest.json'))->not->toContain('REMOTE-SECRET');
})->with(['json', 'duplicates', 'oversized', 'redirect', 'html']);

it('runs the CLI offline with safe distinct failure codes and native network disabled', function (array $args, int $exitCode, string $scenario = 'normal', ?string $error = null, ?string $category = null) {
    $root = dirname(__DIR__, 3);
    // Use a miniature CLI tree with a fictitious profile: never rely on a real
    // ignored .env.sandbox or on credentials being installed on a CI machine.
    $cli = $this->directory.'/cli';
    mkdir($cli.'/tools/support', 0700, true);
    mkdir($cli.'/vendor', 0700);
    $files = [
        $cli.'/vendor/autoload.php' => '<?php require '.var_export($root.'/vendor/autoload.php', true).';',
        $cli.'/tools/support/SuperPdpCiiDiagnostic.php' => '<?php require_once '.var_export($root.'/tools/support/SuperPdpCiiDiagnostic.php', true).';',
        $cli.'/tools/superpdp-cii-diagnostic.php' => file_get_contents($root.'/tools/superpdp-cii-diagnostic.php'),
        $cli.'/.env.sandbox' => "APP_ENV=sandbox\nSUPERPDP_ENVIRONMENT=sandbox\nSUPERPDP_API_URL=".CiiProbeInput::API,
    ];
    if ($scenario === 'profile') {
        $files[$cli.'/.env.sandbox'] = "APP_ENV=production\nSECRET=DO-NOT-EXPOSE";
    } elseif ($scenario === 'autoload') {
        unset($files[$cli.'/vendor/autoload.php']);
    } elseif ($scenario === 'support') {
        unset($files[$cli.'/tools/support/SuperPdpCiiDiagnostic.php']);
    } elseif ($scenario === 'class') {
        $files[$cli.'/tools/support/SuperPdpCiiDiagnostic.php'] = '<?php // missing diagnostic classes';
    } elseif (in_array($scenario, ['validation', 'warning', 'type', 'parse', 'unsafe_reason', 'permission'], true)) {
        $statement = match ($scenario) {
            'validation' => 'throw new \\RuntimeException("DO-NOT-EXPOSE");',
            'warning' => 'trigger_error("DO-NOT-EXPOSE", E_USER_WARNING);',
            'type' => 'strlen([]);',
            'parse' => 'eval("DO-NOT-EXPOSE invalid php");',
            'unsafe_reason' => 'throw new CiiProbeFailure("DO-NOT-EXPOSE");',
            'permission' => 'throw new CiiProbeFailure("private_storage_acl_privilege_required");',
        };
        $files[$cli.'/tools/support/SuperPdpCiiDiagnostic.php'] = '<?php namespace SimpleDevis\\Tools\\SuperPdp; '
            .'class CiiProbeFailure extends \\RuntimeException { public function __construct(public string $reason) {} } '
            .'class CiiProbeProfile { public static function fromFile($path) { return new self; } } '
            .'class CiiDiagnostic { public static function check() { '.$statement.' } }';
    }
    foreach ($files as $file => $bytes) {
        file_put_contents($file, $bytes);
    }
    $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail',
        $cli.'/tools/superpdp-cii-diagnostic.php', ...$args];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cli);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    foreach (array_keys($files) as $file) {
        unlink($file);
    }
    rmdir($cli.'/tools/support');
    rmdir($cli.'/tools');
    rmdir($cli.'/vendor');
    rmdir($cli);
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($status)->toBe($exitCode)->and($stderr)->toBe('')->and($result['requests'])->toBe(0)
        ->and($result['ready_for_emission'])->toBeFalse();
    expect($stdout)->not->toContain('DO-NOT-EXPOSE', 'Stack trace', $cli);
    if ($error !== null) {
        expect($result['error'])->toBe($error)->and($result['error_category'])->toBe($category);
    }
})->with([[['--check'], 0], [['--check', '--business-fixture'], 0],
    [['--check', '--business-fixture', '--business-fixture'], 1], [['--execute'], 1], [['--confirm-fictional-sandbox'], 1],
    [['--check', '--execute', '--confirm-fictional-sandbox'], 1], [['--check', '--check'], 1], [['--deposit'], 1],
    [['--check'], 1, 'profile', 'profile_refused', 'configuration'],
    [['--check'], 1, 'autoload', 'dependency_autoload_missing', 'dependency_loading'],
    [['--check'], 1, 'support', 'dependency_support_missing', 'dependency_loading'],
    [['--check'], 1, 'class', 'php_class_unavailable', 'dependency_or_php_execution'],
    [['--check'], 1, 'validation', 'local_validation_failed', 'local_validation'],
    [['--check'], 1, 'warning', 'php_runtime_warning', 'php_execution'],
    [['--check'], 1, 'type', 'php_type_error', 'php_execution'],
    [['--check'], 1, 'parse', 'php_dependency_parse_error', 'dependency_or_php_execution'],
    [['--check'], 1, 'unsafe_reason', 'local_validation_failed', 'local_validation'],
    [['--check'], 1, 'permission', 'private_storage_acl_privilege_required', 'storage_permissions'],
]);

it('prepares and rechecks the private Windows DACL without requiring audit administration', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Windows DACL regression.');
    }
    $previous = getenv('LOCALAPPDATA');
    putenv('LOCALAPPDATA='.$this->directory);
    $privateRoot = $this->directory.'/SimpleDevis/superpdp-cii-tests';
    try {
        // Before the fix, Set-Acl on a new DirectorySecurity throws
        // PrivilegeNotHeldException (SeSecurityPrivilege) for an ordinary user.
        $storage = CiiProbeStorage::privateDefault();
        expect($storage)->toBeInstanceOf(CiiProbeStorage::class);
        expect(CiiProbeStorage::privateDefault())->toBeInstanceOf(CiiProbeStorage::class);
        $run = $storage->begin('simulated', '{}');
        expect(file_get_contents($run.'/input.en16931.json'))->toBe('{}');
    } finally {
        $previous === false ? putenv('LOCALAPPDATA') : putenv('LOCALAPPDATA='.$previous);
        if (isset($run)) {
            unlink($run.'/input.en16931.json');
            rmdir($run);
        }
        if (is_dir($privateRoot)) {
            rmdir($privateRoot);
        }
        if (is_dir($this->directory.'/SimpleDevis')) {
            rmdir($this->directory.'/SimpleDevis');
        }
    }
});
