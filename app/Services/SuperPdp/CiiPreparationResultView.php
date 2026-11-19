<?php

namespace App\Services\SuperPdp;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** French presentation of verified local results, never a preparation action. */
class CiiPreparationResultView
{
    public function __construct(private readonly CiiPreparationRegistry $registry) {}

    public function inspect(User $actor, Invoice $invoice): array
    {
        $view = ['state' => 'none', 'label' => 'Aucune préparation CII effectuée', 'date' => null,
            'validation' => 'Non effectuée', 'messages' => [], 'download_id' => null, 'simulation' => false];
        try {
            $reservation = $this->registry->consult($actor, $invoice);
            if ($reservation === null) {
                $view['messages'][] = 'Aucun résultat enregistré pour les données figées et le contexte SUPER PDP actuels.';

                return $view;
            }
            $view['date'] = Carbon::createFromTimestamp($reservation['updated_at'], 'Europe/Paris')->format('d/m/Y H:i:s');
            if ($reservation['state'] === 'running') {
                return array_replace($view, ['state' => 'running', 'label' => 'Préparation en cours.',
                    'messages' => ['Une préparation détient déjà la réservation. Aucune nouvelle conversion ne peut être lancée.']]);
            }
            $result = $reservation['result'];
            if ($result !== null) {
                $validation = $result['artifact']['validation_status'];
                $view['download_id'] = $reservation['reservation_id'];
                $view['simulation'] = $result['status'] === 'prepared_simulation';
                $view['validation'] = match ($validation) {
                    'passed' => 'Réussie', 'passed_simulation' => 'Réussie en simulation uniquement',
                    'failed' => 'Échouée', default => 'Indisponible',
                };
                if ($view['simulation']) {
                    return array_replace($view, ['state' => 'simulation', 'label' => 'Document de simulation uniquement — conformité réelle non établie.',
                        'messages' => ['Ce résultat ne constitue pas une validation réelle du CII.']]);
                }
                if ($result['status'] === 'prepared_validated' && $validation === 'passed') {
                    return array_replace($view, ['state' => 'validated', 'label' => 'CII produit et validation réussie.',
                        'messages' => ['La validation enregistrée concerne ce document. Elle ne vérifie pas le routage actuel et n’autorise aucun envoi.']]);
                }

                return array_replace($view, ['state' => $validation === 'failed' ? 'failed' : 'unavailable',
                    'label' => $validation === 'failed' ? 'CII produit mais validation échouée.' : 'Validation indisponible.',
                    'messages' => ['Le document peut être consulté, mais sa conformité électronique n’est pas établie. Aucune relance automatique.']]);
            }

            return array_replace($view, ['state' => 'review', 'label' => 'Préparation interrompue ou résultat incertain, à examiner.',
                'messages' => [$reservation['reason'] === 'interrupted_or_expired'
                    ? 'La réservation a dépassé quinze minutes. Elle reste bloquante ; aucune relance automatique.'
                    : 'La préparation nécessite un examen manuel. Aucun document vérifié n’est disponible.']]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return array_replace($view, ['state' => 'review', 'label' => 'Préparation interrompue ou résultat incertain, à examiner.',
                'download_id' => null,
                'messages' => ['Le résultat ne peut pas être vérifié : données ou contexte incompatibles, journal illisible, archive absente ou altérée. Aucun téléchargement ni relance automatique.']]);
        }
    }
}
