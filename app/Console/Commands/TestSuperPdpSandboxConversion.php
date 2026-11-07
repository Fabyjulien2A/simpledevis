<?php

namespace App\Console\Commands;

use App\Services\SuperPdp\Sandbox\SandboxConversionProbe;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class TestSuperPdpSandboxConversion extends Command
{
    protected $signature = 'superpdp:sandbox-convert
        {--scenario=standard : Exemple fictif standard, franchise, multi-rate ou mixed (M1)}
        {--execute : Autoriser un unique appel réel de conversion de l’exemple fictif sélectionné}';

    protected $description = 'Simule par défaut une conversion EN16931 vers CII, sans base de données ni émission';

    public function handle(SandboxConversionProbe $probe): int
    {
        try {
            $result = $probe->run((bool) $this->option('execute'), (string) $this->option('scenario'));
        } catch (ValidationException $exception) {
            $this->error('Exemple refusé par les contrôles locaux.');
            foreach ($exception->errors() as $path => $messages) {
                $this->line(OutputFormatter::escape($path.': '.implode(' ', $messages)));
            }

            return self::FAILURE;
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $totals = $result['payload']['totals'];
        $this->line('Exemple entièrement fictif : '.$totals['total_without_vat'].' EUR HT + '.$totals['total_vat_amount']['value'].' EUR TVA = '.$totals['total_with_vat'].' EUR TTC.');
        $this->line('POST '.SandboxConversionProbe::URL);
        $this->line('Content-Type: application/json ; Accept: application/xml ; aucun jeton OAuth.');
        if ($result['mode'] === 'simulation') {
            $this->info('SIMULATION : validation locale réussie ; aucun appel réseau.');
            $this->line(OutputFormatter::escape(json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));

            return self::SUCCESS;
        }

        $this->line('HTTP '.($result['status'] ?? 'indisponible'));
        if ($result['mode'] !== 'converted') {
            $this->error('Conversion non confirmée ('.$result['mode'].').');
            $this->line(OutputFormatter::escape($result['diagnostic']));

            return self::FAILURE;
        }

        $this->info('CII reçu : contrôle de forme XML uniquement, sans preuve de conformité fiscale ni émission.');
        $this->line(OutputFormatter::escape($result['xml']));

        return self::SUCCESS;
    }
}
