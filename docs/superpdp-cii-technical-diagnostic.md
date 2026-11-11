# Premier diagnostic technique CII isolé

## Blocage local du premier essai et correction

L'unique tentative autorisée s'est arrêtée avant toute requête : le sous-processus
PowerShell de `CiiProbeStorage::privateDefault()` levait une
`System.Security.AccessControl.PrivilegeNotHeldException` pour
`SeSecurityPrivilege`. `Set-Acl` appliqué à un `DirectorySecurity` neuf tentait
aussi de persister la SACL (audit). L'exception était réduite à
`private_storage_refused`, puis masquée par le catch générique du lanceur.

Le stockage lit désormais uniquement la DACL (`AccessControlSections.Access`),
retire les anciennes règles et la persiste avec `Directory.SetAccessControl`.
La SACL n'est pas modifiée. Un contrôle après écriture exige une DACL protégée,
exactement trois règles explicites FullControl héritables (utilisateur courant,
SYSTEM, administrateurs), sans autre identité. Aucun privilège d'audit ni
élévation permanente n'est requis par cette correction.

Le lanceur expose maintenant des codes fixes avec `phase` et `error_category` :
configuration (`profile_refused`), dépendances (`dependency_autoload_missing`,
`dependency_support_missing`, `php_class_unavailable`), validation locale
(`local_validation_failed`), exécution PHP (`php_runtime_warning`,
`php_type_error`) et stockage (`private_storage_acl_privilege_required`,
`private_storage_permission_denied`, etc.). Les sorties du sous-processus sont
filtrées par liste blanche ; aucun message libre, secret ou trace n'est affiché.

La régression Windows prépare deux fois un stockage temporaire avec le vrai
code d'ACL et vérifie une écriture fictive. Les cas CLI contrôlent les codes et
l'absence de fuite avec les fonctions réseau désactivées. Cette correction
n'autorise aucune nouvelle tentative distante ; celle-ci exige un nouvel accord.

Le lanceur `tools/superpdp-cii-diagnostic.php` prépare exclusivement un exemple
fictif en mémoire. Il ne démarre pas Laravel, ne charge pas le `.env` principal,
ne lit aucune facture ni entreprise enregistrée et n'a aucun accès SQL.
Aucune conversion ou validation réseau n'a été exécutée pendant son développement.

## Services et données réutilisés

- `SandboxInvoiceFixture` fournit l'exemple existant `SANDBOX-FICTIF-0001` :
  prestation S1, EUR, HT 100,00, TVA 20,00, TTC/dû 120,00, parties fictives et
  adresses électroniques `@example.invalid`. Aucun numéro réel n'est réutilisé.
- `SuperPdpInvoicePayloadBuilder::buildFromData()`, `InvoiceFiscalDataPreparer`,
  `InvoiceLinePreparationService` et le calculateur existants construisent et
  contrôlent le JSON EN16931.
- `SuperPdpInvoicePayloadValidator` conserve ses règles ; son constructeur
  accepte maintenant un chemin local explicite pour fonctionner sans le
  conteneur Laravel. Le chemin par défaut de l'application reste identique.
- Le SDK existant effectue `invoice()->convert()` puis `invoice()->validate()`.
- `CiiDocumentChecks` vérifie les références, parties, adresses, lignes, taxes,
  montants et mentions du XML, puis la concordance du rapport avec ses octets.

Les contrôles locaux ne constituent pas une validation XSD/Schematron complète.
Seul le second appel pourra fournir un rapport réel de validation fournisseur.

L'objet facture non enregistré de la fixture utilise temporairement une connexion
sans PDO, réservée à la grammaire des dates : toute tentative d'obtenir un PDO ou
d'exécuter du SQL lève une exception. Le validateur utilise une factory en mémoire.
Les résolveurs sont ensuite restaurés. Aucun fournisseur Laravel, session, cache,
worker, service OAuth client ou archive officielle n'est démarré.

Les identifiants composés de zéros proviennent de la fixture technique existante.
Ce ne sont ni des SIREN/SIRET légaux qualifiés, ni un format officiellement confirmé
pour l'identification `sandbox`. Aucune nouvelle règle n'a été inventée et aucun
contrôle de production n'a été assoupli. Leur acceptation par une conversion ne
prouve pas leur acceptation par une validation EN16931/française. Un rapport
négatif est un résultat possible et doit être conservé comme tel.

## Contrat local vérifié

Source : `resources/schemas/superpdp/1.39.0.beta/openapi.json`.

| Opération | Requête | Réponse attendue |
| --- | --- | --- |
| Conversion | POST `https://api.superpdp.tech/v1.beta/invoices/convert?from=en16931&to=cii`, `Content-Type: application/json`, `Accept: application/xml` | HTTP 200 et XML CII |
| Validation | POST `https://api.superpdp.tech/v1.beta/validation_reports`, multipart avec une seule pièce `name="file_name"; filename="invoice.xml"`, contenant les octets CII exacts ; `Accept: application/json` | HTTP 200 et objet JSON `data` contenant le rapport du fichier |

Les deux opérations ont `security: []`. Le lanceur n'extrait donc aucun secret OAuth
et ne transmet aucun jeton. Il ne refait pas les appels OAuth ou `companies/me`.
Le succès Client Credentials sandbox antérieur est un diagnostic indépendant.
Ces endpoints publics partagent l'origine API et n'attestent pas eux-mêmes
`env=sandbox`. Le manifeste porte explicitement `endpoint_environment_attested=false`.

Le profil `.env.sandbox` doit contenir :

```dotenv
APP_ENV=sandbox
SUPERPDP_ENVIRONMENT=sandbox
SUPERPDP_API_URL=https://api.superpdp.tech/v1.beta
```

Seules ces trois variables sont sélectionnées et parsées, sans chargement dans
l'environnement du processus. Les clés SQL, tokens, secrets, APP_KEY et autres
variables sont ignorés, même s'ils existent dans le fichier. Aucun repli vers
le profil principal n'est autorisé. Les doublons, interpolations et URL modifiées
sont refusés.

## Verrous de transport

Deux requêtes au maximum, dans l'ordre indiqué ; validation autorisée seulement
après vérification de cohérence du CII. Le dernier handler Guzzle contrôle les URL
exactes, méthodes, types et octets des corps, ainsi que le multipart entier.
Il refuse tout dépôt, OAuth, hôte différent, paramètre supplémentaire, troisième
requête, en-tête d'authentification, cookie ou entrée altérée.

TLS vérifié, redirections désactivées, proxy désactivé, aucune nouvelle tentative
automatique, délais bornés. Les réponses restent en RAM et sont limitées à 5 Mio.
Les XML avec DTD, entités, mauvais espace de noms ou contenu mal formé sont refusés.
Une réponse HTTP 200 ne suffit jamais à établir une validation.

## Résultats et fichiers

Le diagnostic distingue :

- contrôle local du payload : `local_payload` ;
- conversion : `conversion` ;
- concordance CII/payload : `local_cii_consistency` ;
- validation fournisseur : `validation`, avec nombres de contrôles/échecs/messages ;
- erreurs : codes fixes, éventuellement statut HTTP, sans corps distant ni message
  d'exception arbitraire.

Pour un succès réel, la validation est `passed_test`. Pour une réponse simulée,
elle est exclusivement `simulated_pass`. Le rapport doit notamment désigner
`invoice.xml`, sa taille exacte, le format CII, `is_valid=true` booléen et des
sous-rapports avec contrôles attestés et sans échec. Un document rejeté donne
`failed` ; un rapport incomplet, ambigu, contradictoire ou indisponible donne
`not_confirmed`. La conversion réussie reste visible même si la validation échoue.

Les fichiers d'une exécution future sont conservés sous :

```text
%LOCALAPPDATA%\SimpleDevis\superpdp-cii-tests\live-<date>-<aléa>\
  input.en16931.json
  document.test.cii.xml
  manifest.json
```

Les simulations de tests utilisent un répertoire `simulated-*`, avec provenance
distincte. Le dossier Windows réel est créé avec des ACL restreintes à
l'utilisateur, SYSTEM et aux administrateurs ; les points de réanalyse sont
refusés. Un XML CII bien formé est conservé à l'identique, même si sa concordance
avec le payload échoue ; il n'est alors pas soumis à validation.

Le manifeste contient les empreintes SHA-256 du contrat, schéma JSON, payload,
CII et réponse de validation, la provenance, les résultats et compteurs expurgés.
Le contenu brut du rapport et ses messages ne sont ni affichés ni enregistrés.
Les erreurs distantes ne sont jamais journalisées par le lanceur.

Chaque manifeste indique **`fictional=true`, `official_archive=false` et
`ready_for_emission=false`**, y compris après une validation fournisseur positive.
Aucun rattachement à une facture, une entreprise ou une archive de l'application
n'est créé. Le transport d'émission et le producteur officiel restent verrouillés.

## Commandes

Contrôle hors ligne, autorisé et déjà exécuté :

```powershell
php .\tools\superpdp-cii-diagnostic.php --check
```

Il vérifie le profil, le contrat, le calcul et le payload, avec zéro requête.
Il ne crée aucun XML simulé ni archive officielle et ne prépare pas encore le
dossier privé de l'exécution réelle.

Premier essai réseau, **à ne lancer qu'après une nouvelle autorisation explicite** :

```powershell
php .\tools\superpdp-cii-diagnostic.php --execute --confirm-fictional-sandbox
```

Conditions : profil conforme, extensions DOM/libxml et dépendances Composer
installées, PowerShell disponible et dossier privé accessible avec ACL valides,
autorisation limitée aux deux POST ci-dessus et acceptation du caractère fictif
non qualifié des identifiants. Aucun flag de configuration d'émission n'est à
activer. Ne pas utiliser les commandes Artisan de préparation de factures réelles.

Le premier appel fournira une conversion réelle ; le second établira seulement
le résultat réel de validation de cet exemple. Une validation positive n'est
pas garantie. Si des identifiants officiellement reconnus sont exigés, demander
à SUPER PDP un jeu d'essai documenté compatible avec ses règles, sans inventer
de SIRET ou de format `sandbox`.

Tests hors ligne, avec transports natifs désactivés :

```powershell
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail vendor/bin/pest tests/Unit/Tools/SuperPdpCiiDiagnosticTest.php --compact
```

Les fixtures CII capturées servent uniquement de réponses MockHandler ; elles
ne sont jamais présentées comme une nouvelle conversion ou validation réelle.

Vérifications réalisées : `--check` avec zéro requête ; création et contrôle hors
ligne du dossier privé et de ses ACL ; 64 nouveaux tests de diagnostic, complétés
par les tests du builder, du validateur, des contrôles CII et du diagnostic OAuth :
314 tests exécutés sans échec, 966 assertions. Une dépréciation préexistante de
`BigDecimal::stripTrailingZeros()` dans `InvoiceLinePreparationService` est signalée
dans 48 cas ; le lanceur CLI empêche son affichage. Ce service n'a pas été modifié.
