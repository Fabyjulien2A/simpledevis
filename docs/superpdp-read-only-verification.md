# Vérifications des parties avant émission

Mise à jour du 10 novembre 2026 : voir [la sécurisation OAuth](superpdp-oauth-hardening.md).
Le support confirme Authorization Code sans permission `send` supplémentaire et
sans activation BT-34. Les anciennes exigences décrites ci-dessous sont remplacées
par `not_required` ; la publication de réception devient informative. Le transport
reste fermé et `emission_ready` reste faux.

## Périmètre local

`InvoicePartyVerification::verify($actor, $invoice, $environment)` réutilise le builder historique, le diagnostic local et les qualifications existantes. Il ne modifie aucune table. Les factures sans les données historiques requises sont arrêtées avant toute consultation. Les profils actuels ne remplacent jamais les identités historiques.

Les seules opérations du SDK accessibles par la passerelle sont quatre GET sur l'origine officielle fixe :

| GET `/v1.beta/...` | Contrôle |
| --- | --- |
| `french_directory/entries?number=SIREN` | Destination unique, active, prise en charge et identique au BT-49 historique ; réponses partielles et ambiguës refusées comme preuves. Consultation publique sans token. |
| `oauth2_sessions/me` | Client OAuth attendu, KYB et identité utilisateur vérifiés. |
| `companies/me` | Société authentifiée identique au vendeur historique, à la connexion du propriétaire et à l'environnement demandé. |
| `directory_entries` | Publication du BT-34 comme adresse de **réception** de cette société, statut, réseau, date d'effet et exclusion des adresses techniques de réponse. |

Référence documentaire : copie OpenAPI locale `resources/schemas/superpdp/1.39.0.beta/openapi.json`. Aucun contrat en ligne n'a été consulté pendant ce développement.

## Ce que ces GET ne prouvent pas

Le contrat de session décrit l'autorité légale/KYB sans permission OAuth explicite `send`. Selon la confirmation officielle du support, aucune permission supplémentaire ni activation BT-34 n'est nécessaire : les deux contrôles sont `not_required`. La publication d'une adresse de réception ne constitue pas un prérequis d'émission.

L'API décrit par ailleurs des mandats délégués sortants ; ce parcours vise uniquement l'émission directe pour le vendeur authentifié, pas la délégation. Aucun mandat sortant supplémentaire n'est ajouté à ce parcours.

## Résultats et confidentialité

Chaque contrôle distingue `passed`, `refused`, `ambiguous`, `unavailable` et désormais `not_required`, avec un motif stable. La provenance est calculée depuis la réponse SDK : `simulated`, `cached` ou `live`. Un contrôle simulé ou issu du cache n'est jamais marqué `verified`. Le bilan des contrôles requis peut être `passed`, mais `emission_ready` vaut toujours `false` et le diagnostic global existant reste inchangé. La publication de l'adresse de réception est exclue du bilan des prérequis d'émission.

Un compte rendu privé versionné est conservé sur le disque local privé `invoice_cii`, sous `party-checks/v1/{user}/{invoice}/{sha256}.json`. Il contient les empreintes des sources historiques et des réponses, les horodatages, les motifs et la destination retenue. Aucun token, secret OAuth, en-tête ou corps distant brut n'est conservé. Lecture : `VerificationAuditStore::read($actor, $invoice, $reference)`, avec contrôle du propriétaire, du chemin et de l'intégrité. L'échec d'archivage remonte explicitement. Aucun nettoyage automatique n'est ajouté.

La relecture finale détecte une modification de la source pendant les consultations. Le compte rendu constitue un constat à un instant donné, pas un verrou, une habilitation durable ni une preuve directement utilisable pour autoriser un dépôt.

## Premier essai réel en lecture seule, ultérieur

`config/superpdp_verification.php` conserve `live_reads_enabled = false`. Aucune route, tâche ou commande automatique n'est ajoutée ; le transport d'émission et ses prérequis restent fermés. Les mocks ne sont acceptés qu'en environnement de test, sans repli réseau.

Après autorisation distincte, un premier essai limité à ces GET devra utiliser une connexion appartenant au vendeur, un token non expiré, le client OAuth et l'environnement explicites et concordants avec la connexion. Aucun refresh OAuth n'est effectué par ce parcours. Il faudra comparer les réponses réelles au contrat figé, confirmer l'exhaustivité de l'annuaire et l'identité/KYB. La publication de l'adresse de réception reste informative. La réussite de ces GET n'active jamais le transport d'émission.

Tests : réponses SDK simulées exclusivement, blocage des requêtes inattendues, SQLite en mémoire et stockage privé temporaire. Aucune migration nécessaire.
