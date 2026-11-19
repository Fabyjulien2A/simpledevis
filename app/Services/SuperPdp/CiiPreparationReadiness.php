<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Throwable;

/** Local inspection only. Never constructs a gateway or performs preparation. */
final class CiiPreparationReadiness
{
    public function __construct(
        private readonly CiiPreparationGuards $guards,
        private readonly CiiPreparationEvidence $evidence,
        private readonly InvoiceExportDataBuilder $source,
    ) {}

    public function inspect(User $actor, Invoice $invoice, array $documentaryPreparation): array
    {
        abort_unless($actor->exists && auth()->id() === $actor->id && $invoice->user_id === $actor->id, 403);
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        abort_unless($stored->user_id === $actor->id, 403);
        abort_unless($stored->isIssued(), 404);
        $groups = [
            'documents' => ['label' => 'Données documentaires figées',
                'messages' => array_column($documentaryPreparation['blockers'], 'message')],
            'oauth' => ['label' => 'Connexion OAuth', 'messages' => []],
            'evidence' => ['label' => 'Preuves vendeur et destinataire', 'messages' => []],
            'authorization' => ['label' => 'Autorisation de préparation', 'messages' => []],
        ];
        $data = null;
        if ($documentaryPreparation['diagnostic']['local_checks_passed']) {
            try {
                $data = $this->source->buildElectronic($stored);
            } catch (Throwable) {
                $groups['documents']['messages'][] = 'Les données figées ne peuvent pas être relues pour la préparation CII.';
            }
        }

        $client = null;
        try {
            $client = $this->guards->configuration();
        } catch (RuntimeException $exception) {
            $groups['oauth']['messages'][] = $exception->getMessage();
        }
        $company = Company::on($stored->getConnectionName())->where('user_id', $actor->id)->first();
        if ($company !== null && ! in_array($stored->getRawOriginal('company_id'), [null, $company->id], true)) {
            $groups['documents']['messages'][] = 'La facture ne correspond pas à l’entreprise courante.';
        }
        $connection = $company?->superPdpConnection()->first();
        $connectionQualified = false;
        if ($connection === null) {
            $groups['oauth']['messages'][] = 'Aucune connexion OAuth n’est enregistrée pour votre entreprise.';
        } elseif ($client !== null) {
            try {
                $this->guards->connection($connection, $client);
                $connectionQualified = true;
            } catch (DecryptException) {
                $groups['oauth']['messages'][] = 'Les jetons OAuth enregistrés sont illisibles. Reconnectez votre entreprise.';
            } catch (RuntimeException $exception) {
                $groups['oauth']['messages'][] = $exception->getMessage();
            } catch (Throwable) {
                $groups['oauth']['messages'][] = 'Les informations de connexion OAuth sont illisibles. Reconnectez votre entreprise.';
            }
        }

        // No caller-supplied reference and no inference from the destination-only report.
        $reference = config('superpdp_preparation.authorization.verification_reference');
        $liveEvidence = false;
        if (! is_string($reference) || $reference === '') {
            $groups['evidence']['messages'][] = 'Aucun bilan complet vendeur/destinataire n’est sélectionné par l’autorisation de préparation. Le contrôle de destination seul ne suffit pas.';
        } elseif ($data === null || ! $connectionQualified) {
            $groups['evidence']['messages'][] = 'Le bilan vendeur/destinataire ne peut pas être qualifié avant la validation des données figées et du contexte OAuth.';
        } else {
            try {
                $liveEvidence = $this->evidence->assess($actor, $stored, $data, $connection, $reference)['live'];
                if (! $liveEvidence) {
                    $groups['evidence']['messages'][] = 'Le bilan contient des preuves simulées ou en cache ; des preuves réelles sont requises pour une préparation distante.';
                }
            } catch (Throwable $exception) {
                $groups['evidence']['messages'][] = match ($exception->getMessage()) {
                    'Fresh party evidence required (maximum 15 minutes).' => 'Une preuve vendeur/destinataire est expirée ou son horodatage est invalide. La fraîcheur maximale exigée est de quinze minutes.',
                    'Complete matching party evidence required.' => 'Le bilan doit contenir des preuves qualifiées et cohérentes pour l’identité du vendeur, sa session et la destination de l’acheteur.',
                    'Party evidence does not match the historical invoice or sandbox company.' => 'Le bilan ne correspond pas aux données figées, à la connexion sandbox ou à la facture, ou son résultat est en échec.',
                    default => 'Le bilan privé vendeur/destinataire est absent, illisible ou non autorisé pour cette facture.',
                };
            }
        }

        try {
            $this->guards->activation();
            if ($data === null || ! $connectionQualified || ! is_string($reference) || $reference === '') {
                $groups['authorization']['messages'][] = 'L’autorisation ne peut pas être qualifiée tant que les données figées, la connexion OAuth et la référence des preuves ne sont pas disponibles.';
            } else {
                $this->guards->authorization($actor->id, $stored->id, CiiPreparationEvidence::hash($data),
                    $reference, $connection, $client, $liveEvidence);
            }
        } catch (RuntimeException $exception) {
            $groups['authorization']['messages'][] = $exception->getMessage();
        }
        foreach ($groups as &$group) {
            $group['passed'] = $group['messages'] === [];
        }
        unset($group);

        $webEnabled = false;
        try {
            $this->guards->webActivation();
            $webEnabled = true;
        } catch (RuntimeException) {
            // The view remains readable even when the HTTP operation is closed.
        }
        $canPrepare = $webEnabled && $data !== null && $connectionQualified && $liveEvidence
            && ! in_array(false, array_column($groups, 'passed'), true);

        return ['groups' => $groups, 'web_preparation_enabled' => $webEnabled,
            'source_sha256' => $data === null ? null : CiiPreparationEvidence::hash($data),
            'verification_reference' => is_string($reference) ? $reference : null,
            'can_prepare' => $canPrepare, 'cii_produced' => false, 'validation_performed' => false,
            'emission_allowed' => false];
    }
}
