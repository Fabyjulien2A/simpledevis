<?php

namespace App\Services\SuperPdp\Documentary;

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\Verification\VerificationObservation;
use Bluerock\SuperPdp\Requests\Invoices\GenerateTestInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Throwable;

class ReferenceTest
{
    public const LABEL = 'Test documentaire sandbox — document non émis — routage non vérifié';

    public function __construct(private readonly ReferenceScope $guard) {}

    public function run(string $run, string $operation, User $user, Company $company, SuperPdpConnection $connection,
        ReferenceArchive $archive, ?MockClient $mock = null): array
    {
        $scope = $this->guard->configuration($run, $operation);
        $this->guard->models($scope, $user, $company, $connection);
        // Fake responses may never be injected into a real CLI authorization.
        if ($mock !== null && ! defined('SIMPLEDEVIS_DOCUMENTARY_OFFLINE_TEST')) {
            throw new RuntimeException('Simulation réservée aux tests hors ligne.');
        }

        return $archive->locked($run, function (string $directory) use ($run, $operation, $scope, $user, $company, $connection, $archive, $mock) {
            $binding = json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_exists($directory.'/scope.json')) {
                if ($archive->read($directory, 'scope.json') !== $binding) {
                    throw new RuntimeException('Archive appartenant à un autre périmètre.');
                }
            } else {
                $archive->write($directory, 'scope.json', $binding);
            }
            $xml = null;
            if ($operation === 'validation' || file_exists($directory.'/generation.result.json')) {
                $generated = $this->result($archive, $directory, 'generation');
                $xml = $archive->read($directory, 'document.cii.xml');
                if (! hash_equals($generated['cii_sha256'], hash('sha256', $xml))) {
                    throw new RuntimeException('CII archivé altéré.');
                }
                app(CiiDocumentChecks::class)->assertCiiXml($xml);
                // A simulated/cached document cannot be promoted into a live test.
                if ($mock === null && $generated['provenance']['source'] !== 'live') {
                    throw new RuntimeException('Document réel requis pour une validation réelle.');
                }
                if ($operation === 'validation') {
                    $this->guard->authorization($scope, $operation, hash('sha256', $xml));
                }
            }
            if (file_exists($directory.'/'.$operation.'.result.json')) {
                $result = $this->result($archive, $directory, $operation);
                if ($operation === 'validation') {
                    if (! hash_equals($result['cii_sha256'], hash('sha256', $xml))
                        || ! hash_equals($result['report_sha256'], hash('sha256', $archive->read($directory, 'validation-report.json')))) {
                        throw new RuntimeException('Rapport archivé altéré.');
                    }
                }

                return $result + ['reused' => true];
            }
            if (file_exists($directory.'/'.$operation.'.intent.json')) {
                throw new RuntimeException('Préparation interrompue ou résultat incertain, à examiner. Aucun nouvel appel.');
            }
            $ciiHash = $xml === null ? null : hash('sha256', $xml);
            $this->guard->authorization($scope, $operation, $ciiHash);
            // Persist before any sender can run. A partial marker also permanently blocks replay.
            $archive->write($directory, $operation.'.intent.json', json_encode([
                'scope' => $scope, 'operation' => $operation, 'at' => now()->toIso8601String(), 'cii_sha256' => $ciiHash,
                'authorization' => array_intersect_key(config('superpdp_documentary.'.$operation.'_authorization'),
                    array_flip(['approved_by', 'approved_at', 'expires_at', 'purpose'])),
            ], JSON_THROW_ON_ERROR));
            $connector = new SuperPdpConnector(baseUrl: ReferenceScope::ORIGIN,
                accessToken: $operation === 'generation' ? $connection->access_token : null);
            $connector->tries = 1;
            $connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
            if ($mock !== null) {
                $connector->withMockClient($mock);
            }
            $connector->middleware()->onRequest(function (PendingRequest $request) use ($run, $operation, $scope, $user, $company, $connection, $ciiHash) {
                if ($this->guard->configuration($run, $operation) !== $scope) {
                    throw new RuntimeException('Périmètre modifié.');
                }
                $this->guard->models($scope, $user, $company, $connection);
                $this->guard->authorization($scope, $operation, $ciiHash);
                $generation = $operation === 'generation';
                if ($request->getRequest()::class !== ($generation ? GenerateTestInvoiceRequest::class : CreateValidationReportRequest::class)
                    || $request->getMethod()->value !== ($generation ? 'GET' : 'POST')
                    || $request->getUrl() !== ReferenceScope::API.($generation ? '/invoices/generate_test_invoice' : '/validation_reports')
                    || $request->query()->all() !== ($generation ? ['format' => 'cii'] : [])
                    || $request->headers()->get('Authorization') !== ($generation ? 'Bearer '.$connection->access_token : null)) {
                    throw new RuntimeException('Opération documentaire non autorisée.');
                }
                if (! $generation && $request->body() instanceof MultipartBodyRepository) {
                    $request->headers()->add('Content-Type', 'multipart/form-data; boundary='.$request->body()->getBoundary());
                }
            });
            try {
                $response = $operation === 'generation'
                    ? $connector->invoice()->generateTest(format: 'cii')
                    : $connector->invoice()->validate(['invoice.xml' => $xml]);
                if ($response->status() !== 200 || strlen($response->body()) > ($operation === 'generation' ? 5242880 : 1048576)) {
                    throw new RuntimeException('Réponse documentaire refusée.');
                }
                $observation = VerificationObservation::fromResponse([], $response);
                $result = ['label' => self::LABEL, 'scope' => $scope, 'date' => $observation->observedAt,
                    'provenance' => ['source' => $observation->source, 'sha256' => $observation->sha256, 'request' => $observation->request],
                    'contract_version' => '1.39.0.beta', 'ready_for_emission' => false, 'routing_verified' => false,
                    'simpledevis_invoice_id' => null];
                if ($operation === 'generation') {
                    if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/xml') {
                        throw new RuntimeException('Réponse XML attendue.');
                    }
                    $xml = $response->body();
                    app(CiiDocumentChecks::class)->assertCiiXml($xml);
                    $archive->write($directory, 'document.cii.xml', $xml);
                    $result += ['status' => 'cii_received', 'validation' => 'not_performed', 'cii_sha256' => hash('sha256', $xml), 'size' => strlen($xml)];
                } else {
                    // Preserve the exact report, even when invalid or undecodable.
                    $archive->write($directory, 'validation-report.json', $response->body());
                    $report = null;
                    $status = 'unavailable';
                    try {
                        $report = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
                        app(CiiDocumentChecks::class)->assertReport($report, $xml);
                        $status = 'passed';
                    } catch (Throwable) {
                        $row = is_array($report) ? ($report['data'][0] ?? null) : null;
                        if (is_array($row) && count($report['data']) === 1 && ($row['file_name'] ?? null) === 'invoice.xml'
                            && ($row['file_size'] ?? null) === strlen($xml) && ($row['is_valid'] ?? null) === false) {
                            $status = 'failed';
                        }
                    }
                    $result += ['status' => 'validation_completed', 'validation' => $status,
                        'cii_sha256' => $ciiHash, 'report_sha256' => hash('sha256', $response->body())];
                }
                $bytes = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $archive->write($directory, $operation.'.result.json', $bytes);
                $archive->write($directory, $operation.'.result.sha256', hash('sha256', $bytes));

                return $result + ['reused' => false];
            } catch (Throwable $failure) {
                if ($mock !== null) {
                    throw $failure;
                }
                throw new RuntimeException('Résultat documentaire indisponible ou incertain, à examiner. Aucun nouvel appel automatique ; document déjà archivé conservé.');
            }
        });
    }

    private function result(ReferenceArchive $archive, string $directory, string $operation): array
    {
        $bytes = $archive->read($directory, $operation.'.result.json');
        if (! hash_equals($archive->read($directory, $operation.'.result.sha256'), hash('sha256', $bytes))) {
            throw new RuntimeException('Manifeste altéré.');
        }
        $result = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (($result['scope'] ?? null) !== json_decode($archive->read($directory, 'scope.json'), true, 64, JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Périmètre du manifeste altéré.');
        }

        return $result;
    }
}
