<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Models\Company;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationObservation;
use RuntimeException;

/** Frozen experimental invoice, NOT an Invoice model or a production validated archive. */
final readonly class TrialPackage
{
    public const PROFILE = 'experimental-burger-queen-3-tricatel-first-deposit-v1';

    private function __construct(public string $xml, public array $manifest) {}

    public static function freeze(
        SandboxEmissionContext $context,
        Company $company,
        SandboxInvoiceScenario $scenario,
        VerificationObservation $conversion,
        VerificationObservation $validation,
        string $confirmedBy,
        string $confirmedAt,
    ): self {
        $context->assertFor($company);
        TrialGate::observed($confirmedAt);
        if (trim($confirmedBy) === '' || strlen($confirmedBy) > 200) {
            throw new RuntimeException('Named operator confirmation required.');
        }
        $preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData(
            $scenario->data(), $context, $company, $scenario->frenchContext());
        $xml = $conversion->data['xml'] ?? null;
        $payloadHash = CiiPreparationEvidence::hash($preparation['payload']);
        if (($conversion->data['operation'] ?? null) !== 'conversion' || ! is_string($xml)
            || $conversion->sha256 !== hash('sha256', $xml)
            || ($conversion->data['input_sha256'] ?? null) !== $payloadHash
            || ($validation->data['operation'] ?? null) !== 'validation'
            || ($validation->data['input_sha256'] ?? null) !== hash('sha256', $xml)
            || ! is_array($validation->data['report'] ?? null)) {
            throw new RuntimeException('Exact conversion and validation receipts required.');
        }
        foreach ([$conversion, $validation] as $receipt) {
            // Exact documentary receipts remain reusable; freshness applies to current
            // seller/OAuth checks and approval, not to an unchanged historical conversion.
            TrialGate::observed($receipt->observedAt);
        }
        app(CiiDocumentChecks::class)->assertSandboxMatches($xml, $preparation, $context, $company);
        app(CiiDocumentChecks::class)->assertReport($validation->data['report'], $xml);

        return new self($xml, [
            'schema_version' => 1, 'profile' => self::PROFILE, 'environment' => 'sandbox',
            'owner_user_id' => auth()->id(), 'local_company_id' => 3, 'provider_company_id' => 18172,
            'oauth_client_id' => config('services.superpdp.client_id'),
            'api_url' => 'https://api.superpdp.tech/v1.beta',
            'context' => $context->toArray(), 'invoice' => $scenario->data(),
            'french_context' => $scenario->frenchContext(), 'payload' => $preparation['payload'],
            'source_sha256' => CiiPreparationEvidence::hash([$scenario->data(), $scenario->frenchContext()]),
            'payload_sha256' => $payloadHash, 'cii_sha256' => hash('sha256', $xml),
            'routing' => ['source' => 'superpdp_interface', 'status' => 'OK',
                'verification' => 'operator_confirmed', 'api_verified' => false,
                'identifier' => '0225:315143296_18171', 'confirmed_by' => $confirmedBy, 'confirmed_at' => $confirmedAt],
            'conversion' => ['source' => $conversion->source, 'observed_at' => $conversion->observedAt,
                'response_sha256' => $conversion->sha256],
            'validation' => ['source' => $validation->source, 'observed_at' => $validation->observedAt,
                'response_sha256' => $validation->sha256, 'report' => $validation->data['report']],
            'disable_pre_check' => false, 'ready_for_emission' => false,
            'production_archive' => false, 'network_transport_implemented' => true,
        ]);
    }

    public function bytes(): string
    {
        return json_encode(['manifest' => $this->manifest, 'xml' => $this->xml],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes());
    }

    /** Caller must provision a private, persistent directory and verify its effective ACLs. */
    public function archive(string $directory): string
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        if (! auth()->check() || auth()->id() !== $this->manifest['owner_user_id']) {
            throw new RuntimeException('Current sandbox owner required.');
        }
        $directory = TrialLedger::directory($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$this->sha256().'.trial.json';
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            // An incomplete or changed existing archive is never repaired or overwritten.
            self::verifyArchive($path, $this->sha256());

            return $path;
        }
        try {
            TrialLedger::writeDurably($handle, $this->bytes());
        } finally {
            fclose($handle);
        }
        self::verifyArchive($path, $this->sha256());

        return $path;
    }

    /** Expected digest must come from the independently reviewed approval, not this file. */
    public static function verifyArchive(string $path, string $expectedSha256): void
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > 16777216
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256)
            || ! hash_equals($expectedSha256, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Frozen experimental archive missing or changed.');
        }
    }

    /** Trusted private archive only; the reviewed digest must be supplied separately. */
    public static function readArchive(string $path, string $expectedSha256): self
    {
        SandboxDatabaseIsolation::assertConfiguredScope();
        self::verifyArchive($path, $expectedSha256);
        $bytes = file_get_contents($path);
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (! is_string($data['xml'] ?? null) || ! is_array($data['manifest'] ?? null)) {
            throw new RuntimeException('Experimental archive envelope refused.');
        }
        $package = new self($data['xml'], $data['manifest']);
        if ($package->bytes() !== $bytes || $package->manifest['profile'] !== self::PROFILE
            || ! auth()->check() || auth()->id() !== $package->manifest['owner_user_id']) {
            throw new RuntimeException('Canonical experimental archive and current owner required.');
        }

        return $package;
    }
}
