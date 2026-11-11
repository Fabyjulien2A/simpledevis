<?php

declare(strict_types=1);

namespace SimpleDevis\Tools\SuperPdp;

use App\Services\InvoiceAmountCalculator;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\InvoiceLinePreparationService;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Sandbox\SandboxInvoiceFixture;
use App\Services\SuperPdp\Sandbox\BusinessInvoiceFixture;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use DOMDocument;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Utils;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Saloon\Contracts\Sender;
use Saloon\Http\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Throwable;

final class CiiProbeFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct('CII test refused.');
    }
}

final class CiiProbeProfile
{
    private function __construct() {}

    public static function fromFile(string $path): self
    {
        CiiProbeInput::profile($path);

        return new self;
    }
}

final class CiiProbeInput
{
    public const ORIGIN = 'https://api.superpdp.tech';

    public const API = self::ORIGIN.'/v1.beta';

    public const CONVERT = self::API.'/invoices/convert?from=en16931&to=cii';

    public const VALIDATE = self::API.'/validation_reports';

    public static function profile(string $path): void
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 65536) {
            throw new CiiProbeFailure('profile_refused');
        }
        // Deliberately ignore every DB, OAuth secret and application key variable.
        $keys = ['APP_ENV', 'SUPERPDP_ENVIRONMENT', 'SUPERPDP_API_URL'];
        $selected = [];
        foreach (preg_split('/\r\n|\r|\n/', file_get_contents($path)) as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) || ! in_array($match[1], $keys, true)) {
                continue;
            }
            if (isset($selected[$match[1]]) || str_contains($line, '${')) {
                throw new CiiProbeFailure('profile_refused');
            }
            $selected[$match[1]] = $line;
        }
        $values = Dotenv::parse(implode("\n", $selected));
        if (($values['APP_ENV'] ?? null) !== 'sandbox' || ($values['SUPERPDP_ENVIRONMENT'] ?? null) !== 'sandbox'
            || ($values['SUPERPDP_API_URL'] ?? null) !== self::API) {
            throw new CiiProbeFailure('profile_refused');
        }
    }

    public static function contract(): string
    {
        $path = dirname(__DIR__, 2).'/resources/schemas/superpdp/1.39.0.beta/openapi.json';
        $contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $convert = $contract['paths']['/v1.beta/invoices/convert']['post'] ?? [];
        $validate = $contract['paths']['/v1.beta/validation_reports']['post'] ?? [];
        if (($convert['security'] ?? null) !== [] || ($validate['security'] ?? null) !== []
            || ! isset($convert['requestBody']['content']['application/json'], $validate['requestBody']['content']['multipart/form-data'])
            || ! in_array('cii', $contract['components']['schemas']['convert_invoice_format']['enum'] ?? [], true)
            || ! in_array('en16931', $contract['components']['schemas']['convert_invoice_format']['enum'] ?? [], true)) {
            throw new CiiProbeFailure('local_contract_changed');
        }

        return hash_file('sha256', $path);
    }

    public static function payload(string $scenario = 'technical'): array
    {
        if (! in_array($scenario, ['technical', 'business'], true)) {
            throw new CiiProbeFailure('fixture_scenario_refused');
        }
        // The existing fixture has an unsaved Eloquent value object. Its date
        // casts need only a grammar; the PDO factory and every SQL are forbidden.
        $previous = Model::getConnectionResolver();
        $previousValidator = ValidatorFacade::getFacadeRoot();
        ValidatorFacade::swap(new ValidationFactory(new Translator(new ArrayLoader, 'fr')));
        $connection = new Connection(static function (): never {
            throw new CiiProbeFailure('database_forbidden');
        });
        $connection->beforeExecuting(static function (): never {
            throw new CiiProbeFailure('database_forbidden');
        });
        $resolver = new ConnectionResolver(['offline_fixture' => $connection]);
        $resolver->setDefaultConnection('offline_fixture');
        Model::setConnectionResolver($resolver);
        try {
            $lines = new InvoiceLinePreparationService(new InvoiceAmountCalculator);
            $fixture = $scenario === 'business' ? new BusinessInvoiceFixture($lines) : new SandboxInvoiceFixture;
            if ($fixture->invoice()->exists || $fixture->invoice()->getKey() !== null) {
                throw new CiiProbeFailure('persisted_fixture_forbidden');
            }
            $schema = dirname(__DIR__, 2).'/resources/schemas/superpdp/1.39.0.beta/en-invoice.schema.json';
            $builder = new SuperPdpInvoicePayloadBuilder($fixture,
                new InvoiceFiscalDataPreparer($lines),
                new SuperPdpInvoicePayloadValidator($schema));

            if ($scenario === 'business') {
                $context = $fixture->invoice()->emission_context;

                return $builder->build($fixture->invoice(), $context['seller_electronic_address'], $context['buyer_electronic_address']);
            }

            return $builder->buildFromData($fixture->buildElectronic($fixture->invoice()), ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
                ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'], $fixture->frenchContext());
        } finally {
            if ($previousValidator === null) {
                ValidatorFacade::clearResolvedInstance('validator');
            } else {
                ValidatorFacade::swap($previousValidator);
            }
            if ($previous === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($previous);
            }
        }
    }

    public static function assertCiiXml(string $xml): void
    {
        if ($xml === '' || strlen($xml) > 5242880 || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            throw new CiiProbeFailure('converted_cii_invalid');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null
                || $document->documentElement?->localName !== 'CrossIndustryInvoice'
                || $document->documentElement?->namespaceURI !== CiiDocumentChecks::NS) {
                throw new CiiProbeFailure('converted_cii_invalid');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

/** Last HTTP boundary, including exact bytes, order and maximum request count. */
final class CiiProbeHandler
{
    public int $requests = 0;

    public ?string $xml = null;

    private readonly \Closure $backend;

    public function __construct(private readonly string $json, private readonly array $payload, callable $backend)
    {
        $this->backend = \Closure::fromCallable($backend);
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $conversion = $this->requests === 0 && (string) $request->getUri() === CiiProbeInput::CONVERT;
        $validation = $this->requests === 1 && $this->xml !== null && (string) $request->getUri() === CiiProbeInput::VALIDATE;
        if ((! $conversion && ! $validation) || $request->getMethod() !== 'POST'
            || ($options['verify'] ?? null) !== true || ($options['allow_redirects'] ?? null) !== false
            || ($options['debug'] ?? false) !== false || ($options['proxy'] ?? '') !== ''
            || $request->hasHeader('Authorization') || $request->hasHeader('Cookie') || $request->hasHeader('X-Api-Key')) {
            throw new CiiProbeFailure('request_guard_refused');
        }
        if ($conversion) {
            if ((string) $request->getBody() !== $this->json || $request->getHeaderLine('Content-Type') !== 'application/json'
                || $request->getHeaderLine('Accept') !== 'application/xml') {
                throw new CiiProbeFailure('request_guard_refused');
            }
        } else {
            if (! preg_match('/\Amultipart\/form-data; boundary=([A-Za-z0-9_-]{1,100})\z/D', $request->getHeaderLine('Content-Type'), $match)
                || $request->getHeaderLine('Accept') !== 'application/json') {
                throw new CiiProbeFailure('request_guard_refused');
            }
            $expected = new MultipartStream([['name' => 'file_name', 'filename' => 'invoice.xml', 'contents' => $this->xml, 'headers' => []]], $match[1]);
            if ((string) $request->getBody() !== (string) $expected) {
                throw new CiiProbeFailure('multipart_bytes_refused');
            }
        }
        $options['sink'] = \GuzzleHttp\Psr7\Utils::streamFor(fopen('php://memory', 'w+'));
        $this->requests++;

        return ($this->backend)($request, $options)->then(function (ResponseInterface $response) use ($conversion) {
            if ($response->getStatusCode() !== 200) {
                throw new CiiProbeFailure($conversion ? 'conversion_http_refused' : 'validation_http_refused', $response->getStatusCode());
            }
            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            if ($type !== ($conversion ? 'application/xml' : 'application/json') || strlen((string) $response->getBody()) > 5242880) {
                throw new CiiProbeFailure($conversion ? 'conversion_response_refused' : 'validation_response_refused');
            }

            return $response;
        });
    }

    public function allowValidation(string $xml): void
    {
        (new CiiDocumentChecks)->assertMatches($xml, $this->payload);
        $this->xml = $xml;
    }
}

final class CiiProbeConnector extends SuperPdpConnector
{
    public function __construct(private readonly Client $client)
    {
        parent::__construct(baseUrl: CiiProbeInput::ORIGIN);
        $this->tries = 1;
        $this->config()->merge(['verify' => true, 'allow_redirects' => false, 'debug' => false, 'proxy' => '', 'timeout' => 30, 'connect_timeout' => 5]);
        $this->middleware()->onRequest(static function (PendingRequest $request): void {
            if ($request->getRequest() instanceof ConvertInvoiceRequest) {
                $request->headers()->add('Accept', 'application/xml');
            } elseif ($request->getRequest() instanceof CreateValidationReportRequest && $request->body() instanceof MultipartBodyRepository) {
                $request->headers()->add('Accept', 'application/json');
                $request->headers()->add('Content-Type', 'multipart/form-data; boundary='.$request->body()->getBoundary());
            } else {
                throw new CiiProbeFailure('sdk_operation_refused');
            }
        });
    }

    protected function defaultSender(): Sender
    {
        return new class($this->client) extends GuzzleSender
        {
            public function __construct(Client $client)
            {
                $this->client = $client;
                $this->handlerStack = $client->getConfig('handler');
            }
        };
    }
}

/** Always test-only storage; no official archive service or invoice association. */
final class CiiProbeStorage
{
    public function __construct(private readonly string $root)
    {
        if (! is_dir($root) || is_link($root) || ! is_writable($root)) {
            throw new CiiProbeFailure('private_storage_refused');
        }
    }

    public static function privateDefault(): self
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_string($local = getenv('LOCALAPPDATA')) || $local === '') {
            throw new CiiProbeFailure('private_windows_storage_required');
        }
        $root = $local.'/SimpleDevis/superpdp-cii-tests';
        // Native ACLs protect files independently of a web server or .gitignore.
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$failure='private_storage_path_refused'
try {
$p=Join-Path $env:LOCALAPPDATA 'SimpleDevis\superpdp-cii-tests'
foreach ($candidate in @($env:LOCALAPPDATA,(Join-Path $env:LOCALAPPDATA 'SimpleDevis'),$p)) {
 if (Test-Path -LiteralPath $candidate) { if ((Get-Item -LiteralPath $candidate).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'path refused' } }
}
$failure='private_storage_create_failed'
New-Item -ItemType Directory -Force -Path $p | Out-Null
$failure='private_storage_acl_failed'
# Load/persist the DACL only. Set-Acl on a fresh DirectorySecurity also tries
# to persist the SACL and requires SeSecurityPrivilege (audit administration).
$acl=[IO.Directory]::GetAccessControl($p,[Security.AccessControl.AccessControlSections]::Access)
$acl.SetAccessRuleProtection($true,$false)
foreach ($rule in @($acl.Access)) { $acl.PurgeAccessRules($rule.IdentityReference) }
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
foreach ($s in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'),[Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))) {
 $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($s,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
}
[IO.Directory]::SetAccessControl($p,$acl)
$actual=[IO.Directory]::GetAccessControl($p,[Security.AccessControl.AccessControlSections]::Access)
if (-not $actual.AreAccessRulesProtected -or $actual.Access.Count -ne 3) { throw 'acl refused' }
foreach ($rule in $actual.Access) {
 $id=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
 if ($id -notin @($sid.Value,'S-1-5-18','S-1-5-32-544') -or $rule.IsInherited -or
     $rule.AccessControlType -ne 'Allow' -or $rule.FileSystemRights -ne 'FullControl' -or
     $rule.InheritanceFlags -ne 'ContainerInherit,ObjectInherit' -or $rule.PropagationFlags -ne 'None') { throw 'acl refused' }
}
} catch {
 if ($_.Exception -is [Security.AccessControl.PrivilegeNotHeldException]) { $failure='private_storage_acl_privilege_required' }
 elseif ($_.CategoryInfo.Category -eq 'PermissionDenied') { $failure='private_storage_permission_denied' }
 [Console]::Out.Write($failure)
 exit 1
}
PS;
        $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new CiiProbeFailure('private_storage_refused');
        }
        fclose($pipes[0]);
        $status = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new CiiProbeFailure(in_array($status, [
                'private_storage_path_refused', 'private_storage_create_failed', 'private_storage_acl_failed',
                'private_storage_acl_privilege_required', 'private_storage_permission_denied',
            ], true) ? $status : 'private_storage_process_failed');
        }

        return new self($root);
    }

    public function begin(string $mode, string $json): string
    {
        if (! in_array($mode, ['live', 'simulated'], true)) {
            throw new CiiProbeFailure('storage_scope_refused');
        }
        $directory = $this->root.'/'.$mode.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new CiiProbeFailure('storage_write_failed');
        }
        $this->write($directory, 'input.en16931.json', $json);

        return $directory;
    }

    public function write(string $directory, string $filename, string $bytes): void
    {
        if (! in_array($filename, ['input.en16931.json', 'document.test.cii.xml', 'manifest.json'], true)
            || realpath(dirname($directory)) !== realpath($this->root) || is_link($directory)) {
            throw new CiiProbeFailure('storage_scope_refused');
        }
        $path = $directory.'/'.$filename;
        if (is_link($path) || is_dir($path) || (is_file($path) && $filename !== 'manifest.json')) {
            throw new CiiProbeFailure('storage_scope_refused');
        }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || hash_file('sha256', $path) !== hash('sha256', $bytes)) {
            throw new CiiProbeFailure('storage_write_failed');
        }
    }
}

final class CiiDiagnostic
{
    public static function check(string $scenario = 'technical'): array
    {
        CiiProbeInput::contract();
        CiiProbeInput::payload($scenario);

        return array_replace(self::initial('offline'), ['local_payload' => 'passed', 'fixture_scenario' => $scenario]);
    }

    public static function simulate(MockHandler $responses, CiiProbeStorage $storage, string $scenario = 'technical'): array
    {
        return self::run($responses, $storage, 'simulated', $scenario);
    }

    public static function live(CiiProbeProfile $profile, CiiProbeStorage $storage, bool $explicitlyApproved = false, string $scenario = 'technical'): array
    {
        if (! $explicitlyApproved) {
            return self::initial('offline') + ['error' => 'explicit_confirmation_required'];
        }

        return self::run(Utils::chooseHandler(), $storage, 'live', $scenario);
    }

    private static function initial(string $mode): array
    {
        return ['mode' => $mode, 'fictional' => true, 'local_payload' => 'not_checked', 'conversion' => 'not_attempted',
            'local_cii_consistency' => 'not_checked', 'validation' => 'not_attempted', 'requests' => 0, 'ready_for_emission' => false];
    }

    private static function reportSummary(array $report): array
    {
        $rows = $report['data'] ?? null;
        $summary = ['report_count' => is_array($rows) ? count($rows) : null, 'checks_count' => 0, 'failures_count' => 0, 'messages_count' => 0];
        if (! is_array($rows)) {
            return $summary;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['subreports'] ?? null)) {
                continue;
            }
            foreach ($row['subreports'] as $subreport) {
                if (! is_array($subreport)) {
                    continue;
                }
                if (is_int($subreport['checks_count'] ?? null) && $subreport['checks_count'] >= 0 && $subreport['checks_count'] <= 1000000) {
                    $summary['checks_count'] += $subreport['checks_count'];
                }
                if (is_array($subreport['failures'] ?? null)) {
                    $summary['failures_count'] += count($subreport['failures']);
                }
                if (is_array($subreport['messages'] ?? null)) {
                    $summary['messages_count'] += count($subreport['messages']);
                }
            }
        }

        return $summary;
    }

    private static function run(callable $backend, CiiProbeStorage $storage, string $mode, string $scenario): array
    {
        $contractHash = CiiProbeInput::contract();
        $payload = CiiProbeInput::payload($scenario);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $directory = $storage->begin($mode, $json);
        $result = self::initial($mode);
        $result['fixture_scenario'] = $scenario;
        $result['local_payload'] = 'passed';
        $guard = new CiiProbeHandler($json, $payload, $backend);
        $client = new Client(['handler' => HandlerStack::create($guard), 'allow_redirects' => false, 'verify' => true,
            'debug' => false, 'http_errors' => false, 'proxy' => '', 'cookies' => false, 'timeout' => 30, 'connect_timeout' => 5,
            'on_headers' => static function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > 5242880) {
                    throw new CiiProbeFailure('response_too_large');
                }
            },
            'progress' => static function ($total, $downloaded): void {
                if ($downloaded > 5242880) {
                    throw new CiiProbeFailure('response_too_large');
                }
            },
        ]);
        $connector = new CiiProbeConnector($client);
        $phase = 'conversion';
        $xml = null;
        $reportHash = null;
        try {
            $response = $connector->invoice()->convert($json, 'en16931', 'cii', 'application/json');
            $xml = $response->body();
            CiiProbeInput::assertCiiXml($xml);
            $result['conversion'] = $mode === 'live' ? 'succeeded' : 'simulated_success';
            $phase = 'private_storage';
            $storage->write($directory, 'document.test.cii.xml', $xml);
            $phase = 'local_cii_consistency';
            $guard->allowValidation($xml);
            $result['local_cii_consistency'] = 'passed';
            $phase = 'validation';
            $response = $connector->invoice()->validate(['invoice.xml' => $xml]);
            $reportHash = hash('sha256', $response->body());
            $report = EmissionJson::object($response->body());
            $result['validation_summary'] = self::reportSummary($report);
            (new CiiDocumentChecks)->assertReport($report, $xml);
            $result['validation'] = $mode === 'live' ? 'passed_test' : 'simulated_pass';
        } catch (Throwable $exception) {
            $result[$phase] = $phase === 'local_cii_consistency' ? 'failed' : 'not_confirmed';
            if ($phase === 'validation' && is_array($report['data'] ?? null) && array_is_list($report['data']) && count($report['data']) === 1) {
                $row = $report['data'][0];
                if (is_array($row) && ($row['file_name'] ?? null) === 'invoice.xml' && ($row['file_size'] ?? null) === strlen($xml)
                    && ($row['format'] ?? null) === CiiDocumentChecks::NS && ($row['is_valid'] ?? null) === false) {
                    $result['validation'] = 'failed';
                }
            }
            $result['error'] = $exception instanceof CiiProbeFailure ? $exception->reason : $phase.'_not_confirmed';
            if ($exception instanceof CiiProbeFailure && $exception->httpStatus !== null) {
                $result['http_status'] = $exception->httpStatus;
            }
        }
        $result['requests'] = $guard->requests;
        $manifest = $result + ['schema_version' => 1, 'purpose' => 'fictional_technical_test_only', 'official_archive' => false,
            'fixture_definition_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/app/Services/SuperPdp/Sandbox/'
                .($scenario === 'business' ? 'BusinessInvoiceFixture.php' : 'SandboxInvoiceFixture.php')),
            'business_translation_sha256' => $scenario === 'business'
                ? hash_file('sha256', dirname(__DIR__, 2).'/app/Services/InvoiceFiscalItemTranslator.php') : null,
            'contract_sha256' => $contractHash, 'payload_sha256' => hash('sha256', $json),
            'payload_schema_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/resources/schemas/superpdp/1.39.0.beta/en-invoice.schema.json'),
            'cii_sha256' => $xml !== null ? hash('sha256', $xml) : null, 'validation_response_sha256' => $reportHash,
            'validation_report_content_persisted' => false, 'api_authentication' => 'none_public_operations',
            'endpoint_environment_attested' => false, 'fixture_identifiers_officially_qualified' => false];
        try {
            $storage->write($directory, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Preserve the actual request count even when final persistence fails.
            $result['error'] = 'private_manifest_write_failed';
        }

        return $result + ['artifact_directory' => $directory];
    }
}
