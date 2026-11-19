<?php

namespace App\Services\SuperPdp\Emission;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** One operator-managed private grant, reloaded at boot, never supplied by HTTP. */
final class SandboxWebEmissionActivation
{
    public const GRANT_PATH = 'sandbox-web-emission/authorization.json';

    public function activate(): void
    {
        if (config('superpdp_emission.http_sandbox_enabled') !== true) {
            return;
        }

        // An absent, invalid or revoked web grant must not inherit a CLI approval.
        config(['superpdp_emission.sandbox_enabled' => false,
            'superpdp_emission.sandbox_client_id' => null,
            'superpdp_emission.authorization' => null]);

        try {
            HttpEmission::assertSandboxRuntime();
            if (config('filesystems.disks.invoice_cii.driver') !== 'local'
                || config('filesystems.disks.invoice_cii.visibility') !== 'private'
                || config('filesystems.disks.invoice_cii.serve') !== false) {
                throw new RuntimeException('Private local grant storage required.');
            }
            $disk = Storage::disk('invoice_cii');
            if (! $disk->exists(self::GRANT_PATH) || $disk->size(self::GRANT_PATH) > 16384) {
                throw new RuntimeException('Sandbox web grant unavailable.');
            }
            $grant = json_decode($disk->get(self::GRANT_PATH), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($grant)) {
                throw new RuntimeException('Invalid sandbox web grant.');
            }
            foreach (['user_id', 'invoice_id', 'company_id', 'connection_id', 'provider_company_id'] as $key) {
                if (! is_int($grant[$key] ?? null) || $grant[$key] <= 0) {
                    throw new RuntimeException('Exact sandbox web scope required.');
                }
            }
            if ($grant['invoice_id'] === 81 || array_key_exists('attempt_reference', $grant)
                || ! is_string($grant['document_reference'] ?? null)
                || ! is_string($grant['cii_sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $grant['cii_sha256'])) {
                throw new RuntimeException('Sandbox web document refused.');
            }
            EmissionDocument::assertReference($grant['document_reference'], $grant['user_id'], $grant['invoice_id']);
            config(['superpdp_emission.sandbox_enabled' => true,
                'superpdp_emission.sandbox_client_id' => $grant['oauth_client_id'] ?? null,
                'superpdp_emission.authorization' => $grant]);
            // Includes the existing maximum fifteen-minute lifetime and exact endpoint/client checks.
            app(SandboxEmissionAuthorization::class)->approval();
        } catch (Throwable) {
            config(['superpdp_emission.sandbox_enabled' => false,
                'superpdp_emission.sandbox_client_id' => null,
                'superpdp_emission.authorization' => null]);
        }
    }
}
