<?php

namespace App\Console\Commands;

use App\Services\SuperPdp\Documentary\ReferenceArchive;
use App\Services\SuperPdp\Documentary\ReferenceScope;
use App\Services\SuperPdp\Documentary\ReferenceTest;
use Illuminate\Console\Command;
use Throwable;

final class SuperPdpSandboxCiiReference extends Command
{
    protected $signature = 'superpdp:sandbox-cii-reference
        {--generate : Demander une seule facture CII officielle de référence}
        {--validate : Valider uniquement le CII déjà archivé}
        {--run= : Référence exacte autorisée côté serveur}';

    protected $description = 'Test documentaire CII sandbox indépendant ; aucune émission ni facture SimpleDevis';

    public function handle(ReferenceScope $scope, ReferenceTest $test): int
    {
        $this->line(ReferenceTest::LABEL);
        if (! $this->option('generate') && ! $this->option('validate')) {
            $this->line('Mode fermé : aucun accès SQL, stockage ou API. Autorisations serveur distinctes requises.');

            return self::SUCCESS;
        }
        if ($this->option('generate') && $this->option('validate')) {
            $this->error('Choisir une seule opération : génération ou validation.');

            return self::FAILURE;
        }
        try {
            $run = (string) $this->option('run');
            $operation = $this->option('generate') ? 'generation' : 'validation';
            // Closed configuration is checked BEFORE SQL or archive access.
            $configuration = $scope->configuration($run, $operation);
            [$user, $company, $connection] = $scope->load($configuration);
            $scope->models($configuration, $user, $company, $connection);
            $root = config('superpdp_documentary.private_root');
            if (! is_string($root) || $root === '') {
                throw new \RuntimeException('Stockage privé non configuré.');
            }
            $result = $test->run($run, $operation, $user, $company, $connection,
                app()->makeWith(ReferenceArchive::class, ['directory' => $root]));
            // No XML, token, private path or upstream error body in console output.
            $this->line(json_encode(array_intersect_key($result, array_flip([
                'status', 'validation', 'date', 'cii_sha256', 'size', 'report_sha256', 'reused',
            ])), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            if ($operation === 'validation' && $result['validation'] !== 'passed') {
                $this->warn($result['validation'] === 'failed' ? 'Validation documentaire échouée. CII conservé.' : 'Validation indisponible ou non concluante. CII conservé.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Test refusé ou résultat incertain, à examiner : vérifier les autorisations serveur, le périmètre OAuth, les archives et le journal. Aucun renouvellement ni relance automatique.');

            return self::FAILURE;
        }
    }
}
