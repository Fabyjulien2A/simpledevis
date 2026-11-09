# Validation MariaDB isolée de l’émission

## Isolation confirmée

- Connexion exclusivement issue de `.env.mariadb-test`, ignoré et non suivi par Git.
- Serveur : `mysql-fabyjulien.alwaysdata.net`, MariaDB **10.11.18**.
- Base réellement sélectionnée : **`fabyjulien_simpledevis_test`**.
- Compte authentifié : **`fabyjulien_simpledevis_test@%`**.
- Droits : `USAGE` global sans droit de données et `ALL PRIVILEGES` uniquement sur
  le nom exact de la base de test, avec les underscores échappés. Le compte possède
  aussi `GRANT OPTION` limité à cette base. Aucune modification des droits n’a été faite.
- Bases visibles : la base de test et `information_schema` seulement.
- Base initialement vide. Aucun accès à la production ou à une autre base métier.

Le lanceur temporaire `tmp/mariadb-emission-validation.php` revalide ces conditions
dans chaque processus avant toute opération. Il ne charge pas le `.env` principal,
n’utilise pas le cache de configuration applicatif et ne reçoit aucun secret en
argument de ligne de commande. La connexion Laravel de l’essai est construite en
mémoire à partir du seul fichier dédié. Les rapports n’incluent aucun mot de passe.

## Migrations et conservation

Le migrateur Laravel a exécuté le schéma initial sur la base vide. Puis les deux
migrations d’émission ont été appliquées dans cet ordre :

1. `2026_11_09_000001_add_invoice_emission_preparation_fields`
2. `2026_11_09_000002_add_outgoing_emission_orchestration`

Un jeu de **35 factures historiques fictives**, profils fictifs et historique
électronique ancien a été inséré avant ces deux migrations. Les empreintes des
données ont été comparées après chaque étape : aucune reprise rétroactive, aucun
instantané inventé ni requalification des anciennes émissions.

Le retour arrière des deux migrations, avant utilisation de leurs nouveaux champs,
et leur réapplication ont réussi sur MariaDB en conservant ces données.
Les migrations restent appliquées et la base reste conservée pour les prochains essais.

Après création de données de préparation/tentatives, les retours arrière sont
désormais **refusés avant tout DDL destructif**. Leur refus a été vérifié sur cette
base, sans supprimer les essais. Il ne faut pas confondre cette protection avec
une procédure de downgrade après émission : celle-ci nécessitera une stratégie
de conservation/sauvegarde. Tout DDL doit s’exécuter sans écritures concurrentes.

## Scénarios MariaDB exécutés

Les tables transactionnelles ont été confirmées en **InnoDB**, avec isolation
**REPEATABLE-READ**. Le transport de dépôt est un objet de test sans client réseau,
qui inscrit une sonde fictive dans la base de test ; ce n’est pas un appel SUPER PDP.

Treize groupes de scénarios passent après correction :

- Transactions : changements non commités invisibles depuis une seconde connexion,
  rollback effectif et commit visible depuis cette seconde connexion.
- Trois courses de réservation/dépôt entre **deux processus PHP réellement distincts**
  chacun connecté à MariaDB : même tentative et **un seul appel au faux transport**
  pour chaque facture. Les identifiants de sessions SQL sont distincts.
- Six contraintes d’unicité vérifiées par erreur MariaDB **1062** : registre,
  référence locale, numéro et référence de tentative, liaison d’identifiant distant,
  clé d’événement. Déduplication des événements et paiement inchangé.
- Timeout simulé après le faux dépôt : résultat inconnu, aucun renvoi, puis
  réconciliation positive par métadonnées et original fictifs concordants.
- Original différent à la réconciliation : maintien de l’inconnu et du blocage.
- Délai de verrouillage réel **1205** avant réservation : aucune réservation
  partielle, reprise explicite sûre après libération du verrou.
- Arrêt forcé d’un vrai processus après prise en charge, avant le faux dépôt :
  récupération en inconnu, aucun renvoi, absence de preuve positive donc blocage conservé.
- Arrêt forcé après le faux dépôt mais avant sa réponse : récupération en inconnu,
  un seul faux dépôt, puis réconciliation positive sans nouveau dépôt.
- Deadlock réel **1213 / SQLSTATE 40001** pendant la réservation : rollback complet,
  aucun dépôt, reprise explicite sûre.
- Deadlock réel pendant l’enregistrement du résultat après faux dépôt : résultat
  inconnu conservé, aucun renvoi, réconciliation positive.
- Deadlock réel pendant les prérequis de prise en charge : rollback complet,
  tentative encore en attente sans prise en charge, puis reprise explicite sûre.

Les concurrents et verrous utilisent exclusivement les utilisateurs, factures et
tables de sondes fictifs de cette base. Aucun réglage global du serveur n’a changé ;
seul le délai de verrouillage de chaque session de test a été fixé à six secondes.
Les deadlocks sont provoqués avec des écritures concurrentes volontairement dans
un ordre opposé, pour vérifier aussi les chemins d’erreur de l’orchestrateur.

## Corrections indispensables révélées

### Initialisation d’une base MariaDB vierge

La migration historique `2026_04_12_172414_add_method_to_payments_table` utilisait
`change()` sur `payments.method` alors que la création initiale de `payments`
n’ajoutait pas cette colonne. MariaDB refusait donc le schéma initial avec **1054**.
La correction ajoute la colonne nullable si elle est absente et conserve `change()`
si elle existe. Les valeurs existantes sont préservées ; la logique de paiement
et Stripe n’est pas modifiée. Deux tests de régression couvrent les deux branches.

### Deadlock pendant les prérequis

Le catch général des prérequis convertissait une exception SQL en refus métier.
Sur un vrai deadlock MariaDB, la transaction était déjà annulée côté serveur :
les écritures de refus pouvaient alors persister hors de la transaction attendue,
puis le commit échouait. Le test a constaté un faux `local_precondition_failed`
avant tout appel au transport.

`OutgoingEmission` laisse désormais remonter les `PDOException` et leurs
`QueryException` dérivées pour que Laravel termine le rollback. Les refus métier
existants gardent leur comportement. Le scénario réel MariaDB repasse, ainsi que
deux régressions locales ; aucun dépôt n’a été effectué pendant le deadlock.

### Protection des retours arrière après usage

Les `down()` des deux migrations supprimaient leurs nouveaux champs et la table
des tentatives même lorsqu’ils contenaient des données. Ils vérifient maintenant
ces données et refusent le rollback avant toute suppression. Huit tests locaux
couvrent les champs de préparation, les tentatives et les métadonnées de suivi.

Ces corrections ont été expliquées avant application. Aucun changement du routage,
de l’activation, des PDF, de la réception, de Stripe ou des références CII.

## Limites

- Les propriétés de verrouillage, contraintes et erreurs ont été exercées sur le
  serveur MariaDB de test indiqué, pas sur la base de production.
- Les interruptions sont celles des processus clients ; aucun serveur partagé
  Alwaysdata n’a été arrêté ni aucune panne globale de l’hébergement provoquée.
- Les pièces, réponses et identités du fournisseur restent fictives. Cette
  validation ne prouve pas une habilitation ou une émission réelle SUPER PDP.
- Le diagnostic global, les prérequis applicatifs et le transport réel restent fermés.
- La base contient les données fictives des passages de diagnostic, y compris
  l’état erroné constaté avant correction ; elles n’ont pas été effacées pour masquer
  l’incident. Les passages finaux sont identifiés dans le rapport JSON.

## Fichiers concernés

- `app/Services/SuperPdp/Emission/OutgoingEmission.php`
- `database/migrations/2026_04_12_172414_add_method_to_payments_table.php`
- Les deux migrations d’émission existantes : protections `down()` seulement, aucun nouvel `up()`.
- `tests/Unit/Services/SuperPdp/OutgoingEmissionTest.php`
- `tests/Unit/Services/PaymentMethodMigrationTest.php`
- `tests/Unit/Services/EmissionRollbackProtectionTest.php`
- Lanceur et rapports temporaires sous `tmp/mariadb-emission-*` (aucun identifiant secret).
- Ce compte rendu.

## Résultats de non-régression

- Scénarios MariaDB : **13 groupes réussis**, avec vrais processus/connexions,
  deadlocks 1213 et délai de verrouillage 1205 ; aucun double faux dépôt détecté.
- Retours arrière après usage : refus vérifié pour les deux migrations, données conservées.
- Régressions ciblées locales : **59 réussis / 549 assertions**, puis
  **9 réussis / 489 assertions** pour les protections de rollback et l’ordre des migrations.
- Suite PHP complète finale : **2 769 réussis, 16 599 assertions**, 183,86 secondes,
  sur SQLite en mémoire. Les tests ordinaires n’ont pas été redirigés vers Alwaysdata.
- Aucun appel SUPER PDP, aucun envoi électronique et aucune modification de la production.
- Le `.env` principal n’a pas été modifié ; `.env.mariadb-test` reste ignoré par Git.

La base conservée contient 2 utilisateurs fictifs, 72 factures fictives (dont les
35 historiques), 37 tentatives de test et aucune ligne de paiement. Les 33 appels
au faux transport répartis sur les passages ne comportent aucun doublon par facture.
Les rapports techniques sans secrets sont conservés sous
`tmp/mariadb-emission-b7e2a14c09f631d8/`, notamment `results.json` et `state.json`.
