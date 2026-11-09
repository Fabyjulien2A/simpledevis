# Étape 4 — validation de bout en bout entièrement simulée

## Portée de la preuve

Le test relie les composants existants depuis les formulaires jusqu’aux événements
sortants. Aucun service applicatif, transport réel, diagnostic, migration, PDF,
réception, Stripe ou référence CII n’est modifié par cette étape.
Les réponses annuaire, OAuth, conversion, validation, dépôt et suivi sont simulées.
Les bases et fichiers sont ceux des tests ; aucun accès Alwaysdata ni appel réseau.

**Le parcours applicatif reste fermé.** Le succès de ce test ne démontre ni une
habilitation réelle ni une acceptation par SUPER PDP.

## Chaîne exercée

1. Saisie du pays ISO FR et du BT-34 dans les paramètres de l’entreprise ; création
   d’un client professionnel français avec BT-49 distinct des contacts.
2. Création d’un brouillon ordinaire B2B France 380/EUR, puis finalisation via la
   route existante. Capture de `party_snapshot` et du contexte d’émission.
3. Modification des profils actuels après finalisation : le builder conserve les
   parties historiques et reconstruit le nom acheteur depuis les prénom/nom capturés.
4. Qualification d’une réponse d’annuaire active unique correspondant au SIREN/BT-49
   historiques ; contrôles vendeur distincts sur des réponses simulées.
5. Appel simulé au SDK de conversion EN16931 → CII et validation du CII obtenu.
   `PreparedCiiFixture` et la référence standard existante sont réutilisés sans
   changer les quatre références de non-régression.
6. Publication de l’archive privée avec ses octets exacts, empreinte et manifeste
   explicitement `offline_simulation`, `ready_for_emission=false`.
7. Réservation durable de la tentative, prise en charge commitée, puis unique
   appel `send()` du SDK avec réponse en mémoire et précontrôle de destination conservé.
8. Collecte paginée d’événements sortants et déduplication, sans paiement créé ni
   modification des données ou du statut de paiement de la facture.

Les empreintes du CII préparé, archivé, remis au contrat de dépôt et enregistré
dans le registre sont identiques. Le corps XML remis au SDK est comparé octet
pour octet à celui de l’archive. La facture définitive et son instantané sont
comparés avant et après le parcours ; le manifeste de simulation reste inchangé.

## Jonction des étapes 2 et 3 : limite explicite

Le contrat applicatif interdit volontairement le dépôt d’une archive de simulation.
Pour tester la mécanique en un seul scénario, `Tests\Support\SimulatedEmissionBridge`
remplace **uniquement dans le test** le lecteur de documents/prérequis fermé.

Cet adaptateur relit l’archive via le service existant, contrôle les empreintes
de source/payload, réutilise le diagnostic local, le builder et les vérifications CII.
Il fournit ensuite un alias en mémoire du contrat d’entrée de l’orchestrateur.
**Aucun fichier `validated` n’est créé, aucune archive n’est déplacée et aucun
manifeste n’est promu en preuve réelle.** Tous les registres portent le mode `test`.

L’adaptateur n’est pas enregistré dans le conteneur applicatif et échoue hors
`testing`. Les liaisons réelles restent `ClosedEmissionPrerequisites` et
`DisabledEmissionTransport`. Le test vérifie séparément leur refus des références
de simulation et de l’alias lorsqu’un dépôt réel est demandé.
Il ne démontre donc pas l’existence d’une jonction de production déjà opérationnelle.

## Scénarios de refus et de concurrence

- Finalisation refusée pour pays, BT-34 ou mention obligatoire absents : rollback
  du numéro et des captures, aucun appel de préparation ni registre d’émission.
- Factures historiques SQL NULL, v1 ou v2 incomplètes : refus sans enrichissement
  ni modification de leur contenu.
- Annuaire partiel ou ambigu et vérification vendeur insuffisante : pas de conversion
  ni dépôt, aucune destination choisie arbitrairement.
- Archives altérées entre les étapes : refus de réservation, aucun artefact réparé
  silencieusement et aucun appel au dépôt.
- Workers/clics entrelacés après commit : même tentative et un seul appel SDK simulé.
- Timeout simulé ou HTTP 503 : résultat inconnu, absence de nouvelle tentative,
  réconciliation positive par GET métadonnées + original exact, puis suivi des statuts.
- Original différent ou corrélation absente : l’inconnu reste bloqué, sans nouveau POST.

Les scénarios de concurrence sont des entrelacements déterministes, pas des
processus MariaDB simultanés. Les tests de l’étape 3 restent également exécutés.

## Compatibilité des migrations

Ordre vérifié :

1. `2026_11_09_000001_add_invoice_emission_preparation_fields.php`
2. `2026_11_09_000002_add_outgoing_emission_orchestration.php`

Le test d’ordre crée une connexion SQLite **nommée et exclusivement en mémoire**,
applique les migrations antérieures, puis y insère 35 factures historiques fictives,
une entreprise, un client et un historique électronique ancien. Il applique les
deux migrations dans l’ordre et compare toutes les colonnes préexistantes.
Les nouveaux champs restent NULL, les 35 instantanés SQL NULL restent NULL et
aucune ancienne émission n’est requalifiée.

Le retour arrière de ces deux migrations seulement, puis leur réapplication,
sont également vérifiés sur cette base isolée. Toutes les requêtes observées par
ce test doivent utiliser cette connexion. La connexion est détruite à la fin.
Les tests de parcours utilisent aussi le migrateur Laravel sur SQLite en mémoire.
Ces 35 données de test ne constituent pas un contrôle des factures de production.

## Ce qui reste à vérifier avec SUPER PDP

- Adresses réelles, complétude de l’annuaire, ambiguïtés et fraîcheur des preuves,
  concordant avec les parties historiques et l’environnement choisi.
- Identité de l’entreprise/session, droit d’émission ou mandat nécessaire et
  rattachement du BT-34. Les réponses `verified` simulées n’en apportent aucune preuve.
- Conversion effective, format exact des rapports et couverture XSD/Schematron,
  EN16931/profil français. Le rapport simulé ne certifie pas cette conformité.
- Réponses de dépôt, disponibilité de la corrélation et de l’original exact,
  récupération/pagination des événements et comportement en cas de perte de réponse.
  `external_id` reste une corrélation, pas une garantie fournisseur d’idempotence.

## Ce qui reste à vérifier sur MariaDB

- Exécution des deux migrations sur la version/configuration cible, avec données
  fictives représentatives, contraintes, index, JSON et éventuel retour arrière.
- Verrous InnoDB et isolation avec plusieurs connexions/processus : réservations
  simultanées, numéro de tentative, prise en charge et unicité de liaison distante.
- Deadlocks, délais d’attente, arrêt d’un worker et panne de persistance après dépôt :
  aucune récupération ne doit réautoriser un envoi dont le résultat est inconnu.

Ces essais doivent commencer sur une MariaDB jetable/isolée, sans réutiliser la base
Alwaysdata ou une base persistante existante.

## Conditions avant un premier essai contrôlé

1. Autorisation explicite de nouveaux appels réseau et du périmètre précis de l’essai,
   avec environnement, entreprise et facture fictive clairement identifiés.
2. Validation MariaDB isolée des migrations et de la concurrence, puis préparation
   du déploiement/retour arrière avant toute application persistante autorisée.
3. Contrôles réels d’annuaire/habilitation et CII produit et validé par le fournisseur,
   conservé en privé avec ses preuves. Aucun renommage ou booléen ne peut transformer
   une archive de simulation en document autorisé.
4. Développement et revue du lecteur d’archives validées, du transport réel et du
   raccordement contrôlé : ils n’existent pas comme parcours activable à ce stade.
   Le diagnostic ne sera ouvert qu’avec des preuves suffisantes et une autorisation
   séparée. Suivi et réconciliation devront être disponibles avant le premier POST.

## Fichiers ajoutés

- `tests/Support/SimulatedEmissionBridge.php`
- `tests/Unit/Services/SuperPdp/ElectronicEmissionEndToEndTest.php`
- `tests/Unit/Services/SuperPdp/EmissionMigrationOrderTest.php`
- Ce document.

Aucune correction applicative ni nouvelle migration n’a été nécessaire pour ces scénarios.

## Résultats exécutés localement

- **18 nouveaux cas** : 17 scénarios de parcours complet/refus et un test dédié à
  l’ordre, à la conservation des données et à la réversibilité des deux migrations.
- Tests ciblés : **846 réussis, 4 582 assertions**, 90,45 secondes.
- Suite complète `php vendor/bin/pest --compact` : **2 757 réussis,
  16 497 assertions**, 176,01 secondes.
- Les tests des quatre références CII existantes passent sans modification de leurs fichiers.
- Nouveaux tests formatés avec Pint ; `git diff --check` sans erreur d’espacement
  (avertissements CRLF/LF de fichiers préexistants uniquement).
- Aucune exécution MariaDB, migration persistante, activation ou requête réseau réelle.
