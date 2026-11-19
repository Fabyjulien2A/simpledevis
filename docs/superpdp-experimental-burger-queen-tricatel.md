# Premier dépôt sandbox Burger Queen → Tricatel

Mise à jour du 13 novembre 2026 : la commande Artisan est raccordée au module
expérimental existant et au SDK SUPER PDP. Aucun appel API ni accès SQL réel n'a
été exécuté pour cette livraison. Aucune facture réelle, archive réelle ou tentative
réelle n'a été créée pendant les tests.

## Commande et fermeture

`superpdp:sandbox-trial` propose `inspect`, `prepare`, `deposit` et `consult`.
Sans `--execute`, aucune opération SQL, fichier ou réseau n'est effectuée.
`inspect` reste toujours sans effet. Aucun endpoint web, worker ou planificateur.

Le périmètre reste strictement : entreprise locale 3, propriétaire utilisateur 3,
connexion OAuth locale 1, société fournisseur 18172, Burger Queen
`sandbox / 000000002` → Tricatel `sandbox / 000000001`, avec destinataire unique
`0225:315143296_18171`. L'adresse vendeur est `0225:315143296_18172`.
Les adresses `_replyto`, la production et les autres sociétés sont refusées.

Les règles, qualifications et autorisations de production ne sont pas modifiées.
La confirmation manuelle est conservée comme `operator_confirmed`,
`source=superpdp_interface`, `api_verified=false`. Elle ne devient jamais une
preuve API. Le POST ne désactive jamais les précontrôles SUPER PDP :
`CreateInvoiceRequest(..., false)` conserve `disable_pre_check=false` (le SDK
omet le paramètre de désactivation lorsqu'il vaut false).

## Stockage et facturation

Emplacement unique, sans option de substitution :

`%LOCALAPPDATA%\SimpleDevis\sandbox-emission-bq3-tricatel-first`

Le chemin doit être sur un volume Windows local fixe, hors OneDrive. Les points
de réanalyse sont refusés. Le répertoire final est créé, s'il manque, avec une
DACL protégée pour l'utilisateur courant, SYSTEM et Administrateurs. Les ACL
existantes du répertoire et de ses descendants sont vérifiées ; elles ne sont
pas réparées automatiquement. Les parents du projet ne sont jamais modifiés.

Fichiers conservés :

- `document.cii.xml` : octets exacts renvoyés par la conversion ;
- `validation-report.json` : corps JSON exact renvoyé par SUPER PDP, avec son SHA-256 brut ;
- `<package_sha256>.trial.json` : facture/scénario/payload/contexte/rapport figés ;
- `prepared.json` : UUID et empreintes nécessaires aux commandes suivantes ;
- `burger-queen-3-tricatel-first-deposit.jsonl` : journal transactionnel fichier ;
- `requests.jsonl` : intentions durables avant chaque requête SDK ;
- `operation.lock` et `preparation.started` : exclusivité et absence de relance implicite.

Aucun jeton n'est enregistré dans ces artefacts. Le scénario existant est réutilisé :
une prestation fictive de 100 EUR HT, TVA 20 EUR, TTC 120 EUR, numéro
`SANDBOX-PREPARATION-BQ-TRICATEL`. Aucun modèle Invoice, compteur ou statut SQL
n'est créé ni modifié. La préparation affiche aussi dates, identités, UUID,
empreintes et chemins afin de permettre la revue avant autorisation du dépôt.

La commande documentaire précédente `superpdp:sandbox-documentary-test` renvoyait
les statuts et empreintes sans enregistrer le XML ou le rapport. Le fichier racine
`F20261113_175022_896.cii.xml` est dans le sens inverse Tricatel → Burger Queen.
Les empreintes recherchées `7f1a8bb892b3e9f1d7623e0b729839684ea6f27110bcaa72d6ac19cf89eef721`
et `a9fa27abe3050ebfecf6ebaaead7de62e76355f6efcc9ecd35d51d7ddc6f50e1` n'ont pas été
retrouvées dans les fichiers accessibles du projet. Les anciens stockages privés
protégés n'étaient pas lisibles par cette session : leur absence n'est pas affirmée.
La nouvelle préparation réalise et conserve sa propre conversion/validation ; elle
ne prétend jamais reproduire les anciens octets ou leurs empreintes.

## Commandes exactes (à exécuter uniquement après autorisation appropriée)

Depuis la racine du projet, sous PowerShell. Contrôle sans aucun effet :

```powershell
php artisan superpdp:sandbox-trial
php artisan help superpdp:sandbox-trial
```

Préparation : autorise trois GET vendeur puis un POST de conversion et un POST de
validation, mais aucun dépôt. Les dates peuvent être remplacées par celles du test
souhaité avant la première préparation. Le flag de confirmation d'adresse atteste
une confirmation manuelle réelle par l'opérateur.

```powershell
php artisan superpdp:sandbox-trial prepare --env=sandbox --execute --operator="Julien" --confirm-tricatel-address --date=2026-11-13 --due-date=2026-12-13
```

Si une préparation complète existe, elle est relue et vérifiée sans nouvel appel
API, avec contrôle des mêmes dates. Un marqueur incomplet après crash/erreur bloque
une nouvelle préparation ; aucune régénération automatique, aucun effacement ni
reset du journal n'est fourni. Une intervention explicite après examen serait
nécessaire pour traiter cet échec documentaire.

Après ouverture du CII/rapport, revue de la facture et autorisation explicite du
dépôt unique, recopier exactement les valeurs affichées (pas celles d'un autre test) :

```powershell
$attempt = Read-Host 'UUID préparé et approuvé'
$packageHash = Read-Host 'package_sha256 préparé et approuvé'
$ciiHash = Read-Host 'cii_sha256 préparé et approuvé'
php artisan superpdp:sandbox-trial deposit --env=sandbox --execute --authorize-single-deposit --operator="Julien" "--attempt=$attempt" "--package-sha256=$packageHash" "--cii-sha256=$ciiHash"
```

Cette dernière commande n'a PAS été exécutée. Sans tous ces flags et valeurs
concordantes, elle refuse avant de lancer les appels. L'approbation est limitée
au processus et expire au bout de cinq minutes ; aucun verrou de production
n'est ouvert ni aucune autorisation persistante créée.

Consultation/suivi, après autorisation des lectures, sans POST :

```powershell
php artisan superpdp:sandbox-trial consult --env=sandbox --execute --operator="Julien" "--attempt=$attempt" "--package-sha256=$packageHash" "--cii-sha256=$ciiHash"
```

Après un résultat `unknown` sans ID connu, fournir un ID candidat explicite obtenu
indépendamment (ne pas le deviner). Même commande avec `--remote-id=ID_FOURNISSEUR`.
Les métadonnées et l'original sont vérifiés avant toute réconciliation positive.
Une erreur, un document différent ou une absence ne prouve pas un non-dépôt et
n'autorise jamais un renvoi.

## Garanties opérationnelles

- Le bootstrap et `SandboxDatabaseIsolation` imposent la seule base sandbox
  `fabyjulien_simpledevis_test` et son compte dédié. Les trois modèles nécessaires
  sont chargés en transaction `READ ONLY`, terminée par rollback avant le réseau.
  Aucun SQL métier n'est écrit, même en sandbox ; aucun refresh OAuth automatique.
- Contexte OAuth, client, société, déchiffrement et marge d'expiration de cinq
  minutes sont contrôlés. Trois preuves vendeur live, non cachées, de moins de
  quinze minutes sont liées au token courant ; elles sont rechargées au dépôt.
- Un verrou exclusif d'opération couvre tous les appels réseau. Chaque requête est
  précédée d'une intention fichier avec flush/fsync. L'archive et les empreintes
  sont relues, puis le claim est enregistré durablement AVANT POST `/invoices`.
- Le POST utilise les octets archivés exacts, l'UUID en `external_id`, TLS vérifié,
  aucune redirection, un seul essai HTTP. L'UUID est une corrélation, pas une
  garantie d'idempotence de SUPER PDP. Le contexte et l'approbation sont recontrôlés
  dans le middleware juste avant l'envoi.
- Le slot du premier dépôt est permanent. Un second claim est refusé avant même
  les GET vendeur, après acceptation, rejet, crash ou incertitude. Aucun retry,
  expiration de lease, remplacement de dossier ou réémission n'est proposé.
- Après erreur de transport/persistance, le résultat reste `unknown`. La commande
  `consult` relit GET `/invoices/{id}` et, si nécessaire, `?format=original` pour
  comparer société/direction/corrélation et SHA-256 de l'original exact.
- Le suivi utilise GET `/invoice_events` avec `invoice_id`, `starting_after_id`,
  `limit=100` et conserve `has_after`, sans passer par le DTO SDK qui le perd.
  Une invocation collecte une page ; la suivante reprend le curseur enregistré.
  La page entière et son curseur sont enregistrés ensemble, jamais après un lot
  invalide ou un doublon contradictoire. Aucun polling n'est lancé.

Les événements sont cumulés, pas interprétés comme une machine à états.
`api:uploaded` et `api:validated` ne prouvent aucune livraison. `api:sent` signifie
transmission au point d'accès distant ; `api:acknowledged` et `api:accepted` sont
ses réponses optionnelles. `api:received` est décrit en mode réception et ne suffit
pas à prouver la réception de cet envoi par Tricatel. Un `fr:202`, s'il existe,
reste une observation distincte. `api:invalid`/`api:rejected` sont conservés sans
annuler l'acceptation technique du dépôt ni autoriser un renvoi. Aucun paiement
ou statut commercial SQL n'est modifié.

## Vérification hors ligne et prérequis restants

```powershell
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail vendor/bin/pest tests/Unit/Services/SuperPdp/ExperimentalSandboxTrialTest.php --bootstrap tests/Support/ExperimentalOfflineBootstrap.php --compact --no-coverage
```

Les tests emploient le SDK et un handler Guzzle strictement en mémoire, sans socket
ni PDO. Les branches de provenance live sont exercées avec des réponses synthétiques,
jamais avec de véritables preuves et jamais dans le stockage d'émission réel.
Le script PowerShell de contrôle des ACL est analysé syntaxiquement, sans l'exécuter.

Résultat de cette livraison : **103 tests réussis, 562 assertions**, sur la suite
expérimentale et la régression de la passerelle documentaire. Pint est validé.

Avant la préparation réelle : profil `.env.sandbox` isolé fonctionnel, utilisateur 3,
entreprise 3, connexion 1 et identité SUPER PDP 18172 concordants, jetons sandbox
lisibles et valables au-delà de cinq minutes, accès autorisé à la création/lecture
du stockage privé Windows. Ces états ne sont pas certifiés par les tests hors ligne.
Un jeton expiré impose une reconnexion/actualisation distinctement autorisée ; le
parcours ne modifie pas automatiquement les tokens SQL.

Aucun raccordement logiciel supplémentaire n'est requis pour lancer la commande.
La préparation et les contrôles live ne sont pas encore exécutés : leurs résultats,
les ACL effectives de l'hôte et la réception réelle côté Tricatel restent à constater.
Le dépôt reste soumis à l'examen de la facture et à l'autorisation explicite suivante.

## Diagnostic ciblé après un refus de préparation

Commande en lecture seule, **sans `--execute`** :

```powershell
php artisan superpdp:sandbox-trial prepare --env=sandbox --diagnostic --operator="Julien" --confirm-tricatel-address --date=2026-11-13 --due-date=2026-12-13
```

Elle vérifie les options, le profil isolé, le chemin/les ACL existants et la présence
des artefacts locaux. Elle ne crée aucun répertoire, fichier, verrou ou marqueur,
ne modifie aucune ACL, ne se connecte pas en SQL et ne consulte aucune API. Combiner
`--diagnostic` et `--execute` est refusé avant de résoudre le service d'exécution.

La sortie contient `stage`, `error_type`, `reason`, `explanation` et
`corrective_action`. Les messages bruts d'exception, stdout/stderr PowerShell,
requêtes SQL, chemins privés, secrets et corps fournisseur ne sont jamais affichés.
Les contrôles PowerShell utilisent un protocole de codes strictement autorisés.

Un stockage absent, ou des ACL conformes sans autre preuve locale, donne
`inconclusive` : cela ne certifie ni OAuth ni la cause historique. L'ancienne
version n'enregistrait pas l'exception ayant produit son message générique.
Un marqueur `preparation.started` sans dossier `prepared.json` est signalé,
sans effacement ni relance. Ce constat ne prouve pas quelle requête a abouti.

Les prochaines erreurs du parcours normal affichent également une étape précise :
stockage/ACL/verrou, chargement SQL en lecture seule par modèle, contrôle OAuth,
chaque lecture vendeur, conversion, validation, écritures d'archive et réservation.
Cet étiquetage conserve les contrôles et les autorisations existants. Ne pas
relancer une préparation réelle uniquement pour obtenir un message plus détaillé.
