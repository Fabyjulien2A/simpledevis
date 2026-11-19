<?php

namespace App\Services\SuperPdp\Emission;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\CiiPreparationGuards;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Entirely local inspection; real HTTP transport remains closed. */
class InvoiceEmissionReadiness
{
    public function __construct(private readonly OutgoingEmission $outgoing,
        private readonly CiiPreparationGuards $oauth, private readonly SandboxEmissionAuthorization $authorization,
        private readonly SandboxEmissionPrerequisites $prerequisites, private readonly HttpEmission $http) {}

    public function owned(User $actor, Invoice $invoice): Invoice
    {
        abort_unless($actor->exists && $invoice->user_id === $actor->id, 403);
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($stored->user_id === $actor->id, 403);
        abort_unless($stored->isIssued(), 404);
        $company = Company::on($stored->getConnectionName())->where('user_id', $actor->id)->first();
        abort_unless(in_array($stored->getRawOriginal('company_id'), [null, $company?->id], true), 403);

        return $stored;
    }

    public function inspect(User $actor, Invoice $invoice): array
    {
        $invoice = $this->owned($actor, $invoice);
        $groups = ['document' => ['label' => 'Document CII validé', 'messages' => []],
            'oauth' => ['label' => 'Connexion OAuth sandbox', 'messages' => []],
            'authorization' => ['label' => 'Autorisation d’émission', 'messages' => []],
            'evidence' => ['label' => 'Preuves vendeur, session et destination', 'messages' => []],
            'transport' => ['label' => 'Transport d’émission depuis l’interface', 'messages' => [
                'Le transport web reste fermé. Aucun dépôt ne peut être effectué depuis cette interface.',
                'Une autorisation distincte liée à l’UUID de la tentative réservée sera nécessaire avant tout dépôt.',
            ]]];
        $reference = null;
        $httpAvailable = $this->http->available();
        if ($httpAvailable) {
            $groups['transport']['messages'] = [];
        }
        $company = Company::on($invoice->getConnectionName())->where('user_id', $actor->id)->first();
        $connection = $company?->superPdpConnection()->first();
        $oauthPassed = false;
        try {
            $client = config('superpdp_emission.sandbox_client_id');
            if ((! $httpAvailable && (! app()->environment('sandbox') || config('app.env') !== 'sandbox'))
                || config('services.superpdp.environment') !== 'sandbox' || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
                || ! is_string($client) || $client === '' || config('services.superpdp.client_id') !== $client || $connection === null) {
                throw new \RuntimeException;
            }
            $this->oauth->connection($connection, $client);
            $oauthPassed = true;
        } catch (Throwable) {
            $groups['oauth']['messages'][] = 'Une connexion sandbox correspondante, un client OAuth explicitement autorisé et un jeton valable plus de cinq minutes sont requis. Aucun renouvellement automatique.';
        }
        $approvedReference = null;
        try {
            $approval = $this->authorization->approval();
            $candidate = $approval['document_reference'] ?? null;
            if (! is_string($candidate) || ($approval['user_id'] ?? null) !== $actor->id
                || ($approval['invoice_id'] ?? null) !== $invoice->id) {
                throw new RuntimeException('Sandbox authorization scope mismatch.');
            }
            EmissionDocument::assertReference($candidate, $actor->id, $invoice->id);
            $approvedReference = $candidate;
        } catch (Throwable) {
            $groups['authorization']['messages'][] = 'Émission désactivée ou autorisation serveur absente, expirée ou invalide. Sa durée maximale est de quinze minutes ; elle est distincte de l’autorisation CII.';
        }
        if ($approvedReference !== null && $oauthPassed) {
            try {
                $reference = $this->prerequisites->authorize($actor, $invoice, $approvedReference)->reference;
            } catch (Throwable $error) {
                $groups['evidence']['messages'][] = match ($error->getMessage()) {
                    'Fresh timestamped real evidence required (maximum 15 minutes).' => 'Le document ou les preuves ont plus de quinze minutes, ou leur date est invalide.',
                    'Simulated cached or incomplete evidence refused.', 'Real sandbox verification evidence required.' => 'Les preuves vendeur, session et destination doivent être réelles, complètes et correspondre aux empreintes de cette facture.',
                    'Sandbox authorization scope mismatch.', 'Sandbox document authorization mismatch.' => 'L’autorisation d’émission ne correspond pas au propriétaire, à la facture, au CII ou à son empreinte.',
                    default => 'Les contrôles existants d’émission refusent le document, les preuves ou leur rattachement. Consultez les prérequis de préparation ; aucun contournement des identifiants légaux ou de fr_siren.',
                };
            }
        } else {
            $groups['evidence']['messages'][] = 'Les preuves réelles du vendeur, de sa session et de la destination ne peuvent pas être qualifiées avant la validation du document, du contexte OAuth et de l’autorisation d’émission.';
        }
        if ($reference === null) {
            $groups['document']['messages'][] = 'Aucun CII privé validé et compatible avec l’autorisation d’émission actuelle.';
        }
        try {
            $status = $this->outgoing->consult($actor, $invoice);
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            $status = ['state' => 'review', 'label' => 'Statut d’émission indisponible, examen requis.', 'date' => null,
                'simulation' => false, 'messages' => ['Le registre sortant ne peut pas être consulté. Aucun nouvel envoi autorisé.']];
        }
        foreach ($groups as &$group) {
            $group['passed'] = $group['messages'] === [];
        }
        unset($group);

        return ['groups' => $groups, 'status' => $status,
            'can_review' => $httpAvailable && $status['state'] === 'pending',
            'can_send' => $httpAvailable && $status['state'] === 'none' && ! in_array(false, array_column($groups, 'passed'), true),
            'document_reference' => $reference];
    }
}
