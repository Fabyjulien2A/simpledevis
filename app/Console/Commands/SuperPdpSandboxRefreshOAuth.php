<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SandboxOAuthTokenRefresh;
use Illuminate\Console\Command;
use Throwable;

final class SuperPdpSandboxRefreshOAuth extends Command
{
    protected $signature = 'superpdp:sandbox-refresh-oauth
        {--connection=1 : Fixed sandbox target: 1 Burger Queen, 2 Tricatel}
        {--execute : Explicitly authorize one sandbox OAuth renewal}';

    protected $description = 'CLI-only renewal of one selected sandbox OAuth connection; no consent or documentary call';

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli') {
            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $this->line('No SQL or API operation performed. Explicit --env=sandbox --execute required for one renewal.');

            return self::SUCCESS;
        }
        $previousUser = null;
        $restoreAuth = false;
        try {
            $ownerId = match ((string) $this->option('connection')) {
                '1' => 3,
                '2' => 4,
                default => throw new \RuntimeException('Unsupported sandbox OAuth connection.'),
            };
            SandboxDatabaseIsolation::assertConfiguredScope();
            $previousUser = auth()->user();
            $restoreAuth = true;
            $user = User::on(SandboxDatabaseIsolation::CONNECTION)->select(['id'])->findOrFail($ownerId);
            auth()->setUser($user);
            $result = app(SandboxOAuthTokenRefresh::class)->run($user, (int) $this->option('connection'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return in_array($result['status'], ['refreshed', 'already_valid'], true)
                && ($result['token_valid_for_documentary_test'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Sandbox OAuth renewal refused. Check owner, identity and isolated context. No automatic retry.');

            return self::FAILURE;
        } finally {
            if ($restoreAuth) {
                $previousUser !== null ? auth()->setUser($previousUser) : auth()->forgetUser();
            }
        }
    }
}
