# Diagnostic autonome Client Credentials

Le lanceur `tools/superpdp-client-credentials.php` teste uniquement l’authentification
du compte développeur et son environnement. Il ne démarre pas Laravel, ne charge
pas ses providers, ne construit pas PDO et ne lit/écrit aucune table. Il n’utilise
ni le service OAuth des clients ni `superpdp_connections`, et ne déclenche aucune
migration, conversion, validation, réception ou émission de facture.

Il réutilise le `SuperPdpProvider` livré avec le SDK, puis
`SuperPdpConnector::company()->me()` avec un sender Guzzle strictement limité.
L’utilitaire JSON existant `EmissionJson` est partagé ; il est purement PHP et
ne démarre aucun composant Laravel. Les services applicatifs ne sont pas modifiés.

## Configuration lue

Seules six variables sont interprétées depuis le fichier local fixe `.env.sandbox` :

- `APP_ENV=sandbox` ;
- `SUPERPDP_ENVIRONMENT=sandbox` ;
- `SUPERPDP_CLIENT_ID` et `SUPERPDP_CLIENT_SECRET`, non vides ;
- `SUPERPDP_API_URL=https://api.superpdp.tech/v1.beta` ;
- `SUPERPDP_TOKEN_URL=https://api.superpdp.tech/oauth2/token`.

Les autres paramètres, notamment `DB_*`, ne sont ni interprétés ni installés dans
l’environnement du processus. Il n’y a aucun repli vers `.env`, les variables
héritées ou le chargeur de profil Laravel. Les doublons de clés utiles, références
`${...}`, valeurs manquantes, environnements différents et URL non exactes sont
refusés. Les fichiers liés symboliquement et les profils de plus de 64 Kio sont
également refusés. Aucun identifiant ou secret n’est accepté en argument CLI.

La validité du client et son rattachement au compte sandbox ne sont pas déduits
de la présence des valeurs : seul un essai autorisé pourra les vérifier.

## Mode hors ligne

Depuis la racine du projet :

```powershell
php .\tools\superpdp-client-credentials.php --check
```

L’absence d’option équivaut au contrôle hors ligne. Ce mode lit et vérifie le
profil mais n’instancie aucun transport réseau natif et ne demande aucun jeton.
Résultat attendu pour une configuration recevable :

```json
{"mode":"offline","authentication":"not_attempted","sandbox_environment":"not_checked","error":null,"http_status":null,"requests":0}
```

Ce résultat ne confirme pas l’authentification ou l’environnement distant.

## Commande réelle — à utiliser seulement après autorisation

```powershell
php .\tools\superpdp-client-credentials.php --execute --confirm-sandbox-account
```

Cette commande **n’a pas été exécutée** pendant cette préparation. Les deux
options sont requises ; `--execute` seul ne choisit aucun transport natif et
renvoie `explicit_confirmation_required`. Les options inconnues, dupliquées ou
contradictoires sont refusées. Aucun autre endpoint, fichier de configuration ou
mode d’authentification n’est sélectionnable par argument.

Le parcours réel autorisé est limité à :

1. `POST https://api.superpdp.tech/oauth2/token`, avec
   `grant_type=client_credentials` et les identifiants du fichier. Le provider SDK
   utilise `client_secret_post` (formulaire) ; il n’y a pas de tentative automatique
   de méthode Basic alternative. Aucun callback, PKCE, refresh ou permission API
   `send` n’est demandé.
2. Après une réponse JSON 200 avec jeton Bearer et `expires_in` entier positif,
   `GET https://api.superpdp.tech/v1.beta/companies/me`, avec le jeton en mémoire.
3. Succès uniquement si cette réponse est un objet JSON cohérent, avec identifiant
   de société entier positif et **`env` exactement égal à `sandbox`**. Aucune
   règle SIREN/SIRET n’est appliquée aux identifiants fictifs de ce diagnostic.

Un jeton peut être obtenu avec des identifiants de production : le premier POST
ne prouve donc jamais à lui seul l’environnement. Si le GET retourne production,
une valeur inconnue, une réponse ambiguë ou une erreur, le diagnostic échoue et
aucune autre opération n’a lieu.

## Garde-fous et sortie

Le même garde de transport couvre le provider OAuth et le SDK : URLs HTTPS
exactes, méthodes exactes, ordre imposé et deux requêtes au maximum. Toute requête
supplémentaire, endpoint de facture, query string ou en-tête d’authentification
inattendu est refusé avant le transport. Les redirections et retries sont
désactivés, TLS reste vérifié et le proxy implicite de l’environnement est désactivé.
Les délais sont bornés à 5 secondes pour la connexion et 20 secondes par requête.
Les réponses sont limitées à 64 Kio et reçues dans un flux mémoire.

Les jetons restent uniquement dans la mémoire du processus, sans persistance,
affichage, sérialisation ou journalisation. Les corps distants et descriptions
d’erreurs ne sont jamais affichés. La sortie contient seulement :

- le mode `offline`, `simulated` (tests uniquement) ou `live` ;
- `authentication` et `sandbox_environment` ;
- un code d’erreur contrôlé, le statut HTTP connu et le nombre de requêtes.

Exemples de codes : `authentication_refused`, `token_response_invalid`,
`production_company_refused`, `sandbox_not_confirmed`, `company_request_failed`.
Le lanceur désactive l’affichage et la journalisation des erreurs PHP et des
arguments d’exception avant de lire les identifiants. Son handler d’erreur et son
catch final n’impriment que des messages fixes. Il n’écrit aucun fichier.

Code de sortie : 0 pour un contrôle hors ligne recevable ou un diagnostic réel
confirmé sandbox ; 1 pour un refus ou une erreur. Il faut toujours lire `mode` :
un succès de configuration hors ligne n’est pas un succès d’authentification.

## Vérifications effectuées

55 tests unitaires autonomes réussissent, avec 201 assertions. Ils utilisent le
provider et le SDK réels avec `MockHandler` en mémoire, sous transports réseau
natifs désactivés. Ils ne dérivent pas du `Tests\TestCase` Laravel et ne lancent
aucune migration. Les cas couvrent les méthodes et corps attendus, les profils
incomplets/production, les URL interdites, redirections, réponses invalides et
dupliquées, erreurs expurgées, refus de production et troisième requête interdite.

Le contrôle `--check` a été exécuté avec succès et zéro requête. Le mode réel
reste volontairement non exécuté : les identifiants et l’accès distant ne sont
donc pas encore validés. Le transport d’émission et les fichiers `.env` existants
restent inchangés.
