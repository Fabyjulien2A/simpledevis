<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Throwable;

final class TrialDiagnostic
{
    public static function step(string $stage, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (TrialFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw self::failure($exception, $stage);
        }
    }

    public static function failure(Throwable $exception, string $stage): TrialFailure
    {
        if ($exception instanceof TrialFailure) {
            return $exception;
        }
        // Exact known local messages only. Never print or interpolate a foreign message.
        $reason = match ($exception->getMessage()) {
            'Another sandbox operation is running.', 'Experimental ledger busy.' => 'operation_busy',
            'Artifact already exists; no overwrite or automatic retry.' => 'artifact_exists',
            'Artifact creation failed.' => 'artifact_write_failed',
            'Sandbox OAuth expired, unreadable or mismatched; reconnect separately.' => 'oauth_unusable',
            'Real isolated sandbox bootstrap required.', 'Only the isolated sandbox database is permitted.' => 'sandbox_scope',
            'An invoice with different dates already occupies the first-deposit slot.' => 'dates_mismatch',
            'Explicit valid dates required.', 'Due date before issue date.' => 'invalid_dates',
            'Manual destination confirmation required for preparation only.' => 'manual_confirmation_required',
            'Named CLI operator required.', 'Named CLI sandbox operator required.' => 'operator_required',
            'Fresh live seller identity, session and address required.' => 'seller_unqualified',
            'Prepared UUID or reviewed digest mismatch.', 'Frozen experimental archive missing or changed.' => 'archive_mismatch',
            default => 'step_failed',
        };
        $type = match (true) {
            $exception instanceof QueryException => 'QueryException',
            $exception instanceof ModelNotFoundException => 'ModelNotFoundException',
            $exception instanceof DecryptException => 'DecryptException',
            $exception instanceof \PDOException => 'PDOException',
            $exception instanceof \TypeError => 'TypeError',
            $exception instanceof \ErrorException => 'ErrorException',
            $exception instanceof \RuntimeException => 'RuntimeException',
            $exception instanceof \Error => 'Error',
            default => 'Throwable',
        };

        return new TrialFailure($stage, $reason, $type);
    }

    public static function describe(TrialFailure $failure): array
    {
        [$explanation, $action] = match ($failure->reason) {
            'operation_busy' => ['Une autre opération détient le verrou.', 'Attendre sa fin ; ne pas effacer le verrou ou le journal.'],
            'artifact_exists', 'preparation_incomplete' => ['Une préparation a déjà commencé sans dossier final complet.', 'Examiner les artefacts et les intentions réseau. Ne rien supprimer ni relancer automatiquement.'],
            'artifact_write_failed' => ['Le fichier privé n’a pas pu être créé ; son existence n’est pas établie.', 'Vérifier les droits et l’espace disque du stockage dédié, sans écraser les artefacts existants.'],
            'oauth_unusable' => ['La connexion OAuth est expirée, illisible ou ne correspond pas au scénario.', 'Vérifier séparément la connexion sandbox 1 et, après autorisation, la reconnecter ou renouveler ses jetons.'],
            'sandbox_scope' => ['Le profil SQL sandbox isolé requis n’est pas sélectionné.', 'Employer --env=sandbox et vérifier le profil isolé sans modifier les règles de production.'],
            'dates_mismatch', 'invalid_dates' => ['Les dates sont invalides ou diffèrent du dossier déjà préparé.', 'Employer les dates valides du dossier existant ; ne pas remplacer ce dossier.'],
            'manual_confirmation_required' => ['La confirmation manuelle du destinataire est absente.', 'Vérifier l’adresse dans l’interface et fournir --confirm-tricatel-address pour prepare seulement.'],
            'operator_required' => ['Un opérateur CLI nommé est requis.', 'Fournir --operator avec le nom de l’opérateur autorisé.'],
            'seller_unqualified' => ['Les preuves vendeur ne satisfont pas les contrôles sandbox.', 'Vérifier identité, session vérifiée, adresse vendeur, token commun et fraîcheur ; conserver tous les contrôles.'],
            'archive_mismatch' => ['Le dossier ou ses empreintes ne correspondent pas.', 'Relire le dossier approuvé et ses empreintes ; ne pas écraser l’archive ou le journal.'],
            'storage_platform' => ['Le stockage Windows local hors OneDrive est indisponible.', 'Vérifier LOCALAPPDATA, PHP Windows et un volume local fixe.'],
            'storage_reparse' => ['Un point de réanalyse est présent sur le chemin privé.', 'Utiliser le chemin local attendu sans lien ni jonction ; ne pas contourner ce refus.'],
            'storage_inspection' => ['Un composant du chemin n’a pas pu être inspecté ; aucun point de réanalyse n’est prouvé.', 'Vérifier les droits de lecture et le fournisseur de fichiers pour ce chemin. Le refus reste actif ; ne pas contourner le contrôle.'],
            'storage_acl' => ['La DACL du stockage ne satisfait pas les exigences privées.', 'Faire vérifier les droits effectifs du répertoire dédié pour l’identité PHP, SYSTEM et Administrateurs ; ne pas élargir les droits.'],
            'storage_access' => ['L’identité courante ne peut pas lire ou créer le stockage privé.', 'Vérifier le compte exécutant PHP et ses droits sur le répertoire dédié.'],
            'storage_process' => ['Le contrôle PowerShell n’a pas pu être exécuté ou n’a pas répondu.', 'Vérifier la disponibilité de powershell.exe, les fonctions de lancement PHP et les restrictions de processus.'],
            default => self::stageHelp($failure->stage),
        };

        return ['status' => 'failed', 'stage' => $failure->stage, 'error_type' => $failure->errorType,
            'reason' => $failure->reason, 'explanation' => $explanation, 'corrective_action' => $action];
    }

    private static function stageHelp(string $stage): array
    {
        return match (true) {
            str_starts_with($stage, 'identity.') => ['Le chargement local de l’identité sandbox a échoué.', 'Vérifier la connexion SQL sandbox, son schéma et les lignes utilisateur 3, entreprise 3 et connexion 1. Aucun SQL n’est lancé par le diagnostic.'],
            str_starts_with($stage, 'oauth.') => ['Le contrôle local OAuth a échoué.', 'Vérifier le contexte, la clé de chiffrement sandbox, les jetons lisibles et leur expiration ; ne jamais afficher leurs valeurs.'],
            str_starts_with($stage, 'seller.') => ['La lecture ou qualification d’une preuve vendeur a échoué.', 'Examiner cette lecture après autorisation séparée ; ne pas lancer de vérification réseau depuis le diagnostic local.'],
            str_starts_with($stage, 'document.') => ['La conversion, validation ou cohérence documentaire a échoué à cette étape.', 'Conserver les artefacts et le journal. Examiner le résultat existant avant toute nouvelle autorisation documentaire.'],
            str_starts_with($stage, 'archive.'), str_starts_with($stage, 'journal.') => ['L’archive ou le journal n’a pas pu être lu ou conservé durablement.', 'Vérifier droits, espace disque, empreintes et intégrité ; ne pas effacer ou réinitialiser les fichiers.'],
            str_starts_with($stage, 'storage.') => ['Le contrôle ou verrouillage du stockage privé a échoué.', 'Vérifier le stockage local, ses ACL et les opérations concurrentes, sans désactiver les contrôles.'],
            default => ['Un contrôle préalable a échoué.', 'Vérifier le profil sandbox et les options documentées de la commande.'],
        };
    }
}
