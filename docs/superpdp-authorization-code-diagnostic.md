# Diagnostic Authorization Code Burger Queen — préparé, non exécuté en réel

**État actuel : OAuth temporairement en pause pour observer le refus du
formulaire réel.** Sans `SUPERPDP_OAUTH_DIAGNOSTIC_MODE=oauth` dans le processus,
le routeur reste en observation : le bouton teste uniquement
les contrôles locaux, aucune URL d'autorisation n'est générée, et le callback
est bloqué avec `oauth_paused`. Ne pas interpréter les instructions de consentement
ci-dessous comme une réactivation de ce mode. Une observation navigateur réelle
est nécessaire avant de conclure à la correction du refus.

Ce diagnostic autonome ne charge ni Laravel, ni ses providers, ni PDO. Il ne
modifie pas `SuperPdpOAuthService`, les routes métier, la connexion de réception
ou les règles de qualification. Aucun SIRET local, compte SimpleDevis ou facture
n'est nécessaire. Il ne produit aucune preuve applicative `seller_identity` ou
`seller_session` utilisable pour autoriser un dépôt : les champs homonymes du
bilan sont seulement le résultat de ce diagnostic, avec un mode explicite.

## Callback à enregistrer

```text
http://127.0.0.1:8765/oauth/callback
```

Cette valeur est fixe et distincte du callback métier sur le port 8000.
Le diagnostic n'utilise pas `SUPERPDP_REDIRECT_URI` et ne change pas `.env.sandbox`.

Dans l'interface SUPER PDP, sélectionner le client OAuth correspondant exactement
au `SUPERPDP_CLIENT_ID` du profil `.env.sandbox`, dans le contexte sandbox. Vérifier
que le client accepte Authorization Code et PKCE S256. Ajouter l'URI ci-dessus à
ses URI autorisées, sans remplacer le callback métier existant. Comparer tous
les caractères : HTTP, `127.0.0.1`, port 8765, chemin, absence de slash final.
Confirmer que le fournisseur accepte HTTP sur boucle locale. Le contrôle hors
ligne ne peut vérifier ni cet enregistrement, ni les droits du compte, ni la
validité du client/secret.

Lors du futur consentement, vérifier que l'application est celle attendue et que
l'entreprise sélectionnée est Burger Queen, schéma `sandbox`, numéro `000000002`.
Le préremplissage n'est pas une preuve : le callback vérifie l'identité obtenue.
Ne pas choisir Tricatel ou une entreprise réelle. Annuler en cas de doute.

## Contrôle local sans réseau

```powershell
php .\tools\superpdp-authorization-code.php --check
```

Le contrôle lit exclusivement le fichier fixe `.env.sandbox`, sans charger ses
valeurs dans l'environnement. Il valide APP_ENV et SUPERPDP_ENVIRONMENT sandbox,
le client/secret non vides, APP_URL local sur le port 8000 et les URL officielles
exactes d'autorisation, jeton et API. Les doublons utiles, interpolations,
profils absents/liés ou de plus de 64 Kio sont refusés. DB_* et le `.env` principal
ne sont jamais interprétés. Le résultat indique l'URI du diagnostic et zéro
requête ; il ne confirme aucun élément distant.

## Exécution future — uniquement après autorisation distincte

Aucun serveur et aucun navigateur ne sont lancés dans cette préparation.
Après accord explicite, depuis la racine du dépôt, dans un terminal PowerShell
dédié, le lancement prévu est :

```powershell
$env:SUPERPDP_OAUTH_DIAGNOSTIC_LIVE = '1'
$env:SUPERPDP_OAUTH_DIAGNOSTIC_MODE = 'oauth' # Seulement après autorisation du test réel.
try {
    # Conserver le -d session.save_path du serveur actuel si le répertoire
    # PHP par défaut n'est pas accessible en écriture. Il doit rester privé.
    php -d display_errors=0 -d log_errors=0 -d zend.exception_ignore_args=1 -d disable_classes=PDO,mysqli,SQLite3 -S 127.0.0.1:8765 tools/superpdp-authorization-code-router.php 2>$null
} finally {
    Remove-Item Env:\SUPERPDP_OAUTH_DIAGNOSTIC_LIVE -ErrorAction SilentlyContinue
    Remove-Item Env:\SUPERPDP_OAUTH_DIAGNOSTIC_MODE -ErrorAction SilentlyContinue
}
```

La redirection stderr vers `$null` est intentionnelle : le serveur PHP intégré
émet normalement les URI de requête, dont le callback avec son code OAuth.
Ne pas utiliser de transcription, capture HTTP ou journal d'accès autour de ce
serveur. Aucune erreur PHP, exception HTTP, réponse brute ou jeton n'est imprimé
par le diagnostic. Garder le serveur lié à 127.0.0.1 et l'arrêter après l'essai.
La variable d'activation n'est pas ajoutée à un fichier de configuration.

Ouvrir ensuite manuellement `http://127.0.0.1:8765/`, dans un navigateur local,
et utiliser le bouton de consentement. Ne pas utiliser `localhost` et ne pas
cliquer sur la connexion métier de SimpleDevis. Le routeur refuse les autres
hôtes/ports et les clients non locaux ; aucun fichier statique n'est servi.

Le diagnostic emploie une session PHP fichier distincte, avec cookie HttpOnly,
SameSite Lax, sans domaine partagé et sans jeton. Seuls CSRF, state, verifier PKCE,
date et empreinte de configuration y sont écrits. Le nonce de démarrage est à
usage unique et le POST exige l'origine locale exacte. Le state expire après
dix minutes ; un nouveau démarrage remplace le précédent. Le callback consomme
et enregistre la suppression du state avant l'échange réseau, même en cas de
refus, erreur ou arrêt ultérieur du processus. Aucun jeton d'accès ou de refresh
n'est conservé en session, base, fichier de résultat ou journal. La session de
diagnostic ne partage pas celle de SimpleDevis.

## Requêtes autorisées et bilan

Après la navigation de consentement, le callback effectue au plus trois requêtes
serveur, sans retry, redirection ni refresh :

1. POST `https://api.superpdp.tech/oauth2/token`, avec authorization_code,
   client_secret_post, redirect_uri fixe et verifier PKCE.
2. GET `https://api.superpdp.tech/v1.beta/oauth2_sessions/me` avec le Bearer reçu.
3. Seulement si les deux statuts sont `verified`, GET
   `https://api.superpdp.tech/v1.beta/companies/me` avec le même Bearer.

La session doit porter le client attendu et une date valide. Toute valeur
manquante, inconnue, en attente ou négative bloque la suite. L'identité exige
un ID entier positif, une date valide, `country=FR`, `env=sandbox`,
`number_scheme=sandbox` et le numéro chaîne exact `000000002`. Si la réponse de
jeton contient company_id, il doit être un entier positif égal à celui de la
société. Si absent, l'ID est découvert dans companies/me, sans être déduit du
numéro ou du suffixe Peppol. Cette première découverte ne vérifie pas un ID
préconnu indépendamment ; le futur rattachement métier devra le figer.

TLS est vérifié, proxy implicite désactivé, délais bornés à 5/20 secondes,
réponses limitées à 64 Kio et reçues en mémoire. Les JSON ambigus/dupliqués ou
réponses HTTP autres que 200 sont refusés. Un jeton doit être Bearer avec une
durée entière d'au moins 60 secondes ; sa durée restante est contrôlée avant
la lecture de société. Un refresh token absent est signalé, sans empêcher ce
diagnostic ; il bloquerait la connexion métier actuelle.

Le bilan JSON expose seulement mode, résultats OAuth/session/identité, ID
fournisseur confirmé, présence booléenne d'un refresh token, deux statuts de
vérification parmi une liste fermée, code d'erreur fixe, nombre de requêtes et
dernier statut HTTP reçu. Ni nom/adresse libre, ni client_id, ni corps distant,
ni token/code/verifier/state n'y figurent. Un mode `simulated` n'atteste aucune
vérification distante ; un `passed` ici n'active aucun droit applicatif.

Si la session est en attente, aucun polling n'est lancé. Le jeton est abandonné ;
après résolution manuelle auprès du fournisseur, un nouveau consentement sera
nécessaire. L'adresse de Tricatel, les données légales et la facture restent hors
du périmètre de cet outil.

## Tests hors ligne

Les tests dans `tests/Unit/Tools/SuperPdpAuthorizationCodeDiagnosticTest.php`
utilisent le TestCase PHPUnit simple et exclusivement Guzzle MockHandler.
Ils couvrent consentement/PKCE, nonce et state, expiration/rejeu, dérive de
configuration, profils de production, routage local/CSRF, statuts manquants,
mauvaises identités, concordance company_id, absence de refresh, erreurs HTTP,
redirections, JSON dupliqué, taille et confidentialité des sorties.

```powershell
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect -d disable_classes=PDO,mysqli,SQLite3 vendor/bin/pest tests/Unit/Tools/SuperPdpAuthorizationCodeDiagnosticTest.php --no-coverage --do-not-cache-result
```

Cette commande ne lance pas les tests Feature, qui utilisent des migrations.

Vérifications effectuées le 12 novembre 2026 : 78 tests propres à ce diagnostic
(288 assertions), puis 133 tests avec la non-régression Client Credentials
(489 assertions), tous réussis sous les restrictions réseau/SQL ci-dessus.
Le contrôle `--check` réussit sur le profil local avec `requests=0` et
`provider_configuration=not_checked`. Les quatre fichiers PHP passent `php -l`
et Pint `--test`. Aucun serveur, navigateur ou parcours réel n'a été lancé.
La session fichier et le retour navigateur réels restent à vérifier lors de
l'essai autorisé ; les tests HTTP présents sont des appels en mémoire au routeur.

## Correction du démarrage refusé

Le premier clic sur le formulaire a été signalé comme `request_refused`. La
configuration initiale envoyait `Referrer-Policy: no-referrer` sur la page du
formulaire : cette politique peut imposer `Origin: null` au POST de navigation,
en contradiction avec le contrôle d'origine locale exacte. Le refus 403 de ce
cas est reproduit hors ligne. Les tests initiaux imposaient directement une
origine locale, sans vérifier la politique envoyée par le serveur. Le POST du
navigateur utilisateur n'a pas été capturé ; aucune donnée OAuth n'a été lue.

La page `/` renvoie désormais `Referrer-Policy: same-origin`, qui préserve
l'origine du POST local sans transmettre de Referer au fournisseur externe.
Le callback conserve `no-referrer`. La CSP du formulaire et du démarrage autorise
uniquement self et l'endpoint officiel exact `/oauth2/authorize`, afin que le
303 vers le consentement ne soit pas bloqué par `form-action`. Les autres pages
gardent la CSP restrictive. Aucune origine nulle/manquante/étrangère n'est admise.

Le POST exige toujours origine exacte, cookie de session et nonce HTML identique,
à usage unique, sans autre champ. L'état OAuth n'est créé qu'après ces contrôles.
Un accès direct en GET à `/oauth/start` reste refusé ; il faut repartir de `/`.

Vérifications après correction : 143 tests hors ligne, 540 assertions, fonctions
réseau et classes SQL désactivées ; contrôle de profil réussi avec zéro requête ;
syntaxe PHP et Pint validés. Deux GET locaux avant correction ont confirmé un
cookie de session stable. Un GET local après correction a confirmé HTTP 200 et
les nouveaux en-têtes sur le serveur déjà lancé, sans redémarrage. Aucun POST
réel de démarrage, consentement ou appel fournisseur n'a été exécuté pendant
cette correction. Pour réessayer manuellement, recharger la page `/` afin
d'obtenir le nouveau document et un nouveau nonce, puis cliquer sur le bouton.

## Observation temporaire du refus réel

Après un nouveau signalement, la correction de Referrer-Policy n'est plus
considérée comme une résolution suffisante. Le serveur a été instrumenté sans
enregistrer les requêtes brutes. `startObservation` classe la méthode et l'origine
dans des listes fermées et indique les résultats booléens des contrôles : méthode
POST, URI exacte, origine locale, présence du nonce en session et dans le
formulaire, égalité CSRF et absence de champs supplémentaires. Un refus reste
local et affiche les prédicats en échec ; aucun contrôle n'est supprimé.

Le routeur complète ce bilan avec présence booléenne du cookie et session active,
date et empreinte de la version PHP réellement chargée. Le bilan expurgé est
affiché et ajouté à `start-observations.jsonl` dans le répertoire de session
temporaire privé configuré au lancement. Le fichier est borné et un lien
symbolique est refusé. Il ne contient aucune valeur de cookie, nonce, state,
code OAuth, jeton, secret, Origin brut, URL ou champ libre. Les journaux d'accès
du serveur PHP restent désactivés comme au lancement initial.

Procédure d'observation : recharger `/`, vérifier le bouton « Tester le formulaire
local (sans OAuth) », puis cliquer une seule fois. Cette action ne lance aucun
consentement et ne fait aucun appel fournisseur, même si tous les contrôles
locaux réussissent. L'en-tête `X-SimpleDevis-Diagnostic-Revision` permet de
confirmer que la version instrumentée est effectivement chargée.

## Vérification de la session et du rejeu

Les trois observations disponibles au début de cette investigation avaient
`session_cookie_present=true` : deux POST acceptés, puis un POST refusé avec
`session_csrf_present=false`. Elles ne confirment donc pas l'absence de cookie
annoncée dans le compte rendu utilisateur. Aucun attribut du cookie n'est modifié
sur la base de ce compte rendu contradictoire.

Vérification HTTP réelle sur 127.0.0.1, sans navigateur ni accès fournisseur :
GET avec CookieContainer, POST du nonce reçu, rejeu du même POST, nouveau GET et
POST du nouveau nonce. Le premier passe, le rejeu échoue malgré un cookie présent,
et le formulaire fraîchement chargé passe. La cause reproduite est la disparition
normale du nonce après sa consommation à usage unique, et non sa perte avec le
cookie. Le nonce n'est ni restauré ni rendu réutilisable.

Attributs constatés dans Set-Cookie : Path=/, Domain absent (host-only), Secure
absent pour HTTP local, HttpOnly, SameSite=Lax, cookie de session. Le cookie est
transmis entre GET et POST par le client local. Un contrôle négatif supprime le
cookie côté client : le POST est bien refusé avec `session_cookie_missing` et
CSRF invalide. Ce contrôle négatif ne prétend pas expliquer pourquoi un autre
navigateur aurait supprimé son cookie ; une telle suppression reste à observer
si elle se reproduit réellement.

Le bilan distingue maintenant `session_cookie_missing`,
`form_missing_or_already_consumed` et `form_stale_or_changed`. Il indique de
recharger `http://127.0.0.1:8765/` avant une seule soumission. Ne pas actualiser
le résultat POST, renvoyer le même formulaire ni repartir d'un ancien onglet.

Le test HTTP de non-régression est reproductible contre le serveur déjà lancé :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\check-superpdp-oauth-local-session.ps1
```

L'option d'exécution ne vaut que pour ce processus PowerShell. Le test exige
l'en-tête `X-SimpleDevis-Diagnostic-Mode: observe`, utilise exclusivement deux
chemins locaux fixes et ne suit aucune redirection. Les cookies et nonces restent
en mémoire ; seules les conclusions sont imprimées. Le test passe sur le serveur
réel : attributs, continuité, refus de rejeu, rechargement accepté et refus sans
cookie. Les tests PHP hors ligne passent également : 157 tests, 647 assertions.
Le mode OAuth utilise les mêmes prédicats que l'observation et renvoie aussi
une explication expurgée et l'URL de rechargement lors d'un refus local.

Le mode de processus vaut `observe` par défaut (ou explicitement), `oauth` sur
activation explicite ; toute autre valeur est refusée. La sortie d'observation
requiert le redémarrage du seul serveur de diagnostic avec MODE=oauth, en
conservant LIVE=1, le profil sandbox et le répertoire de session privé actuellement
configuré. Les deux autorisations sont nécessaires. Vérifier l'en-tête de mode
après redémarrage, puis charger un formulaire neuf avant de cliquer. Le mode
OAuth n'a pas été activé pendant cette intervention et aucun appel SUPER PDP
n'a été effectué. Le serveur actuel reste en observation.

## Diagnostic du serveur après redémarrage — 12 novembre 2026

Cette intervention remplace l'état de processus décrit ci-dessus : le serveur
local tourne en `oauth`, avec `SUPERPDP_OAUTH_DIAGNOSTIC_LIVE=1`, le profil fixe
`.env.sandbox` et son répertoire de sessions privé sous `%TEMP%`. Le processus
PHP et la ligne de commande ont été contrôlés, ainsi que l'écoute exclusive
sur `127.0.0.1:8765`. Les classes SQL restent désactivées.

Le parcours direct sur le serveur réel fonctionne : GET `/` renvoie 200,
le nonce HTML est effectivement enregistré dans le fichier de session, et le
POST `/oauth/start`, avec le même cookie et l'Origin exact, renvoie 303.
Les tests ne suivent jamais cette redirection et ne demandent aucun callback.

Un défaut de consommation prématurée a été reproduit avant correction :
GET 200 → POST avec Origin `null` refusé 403 (`origin_invalid`) → nonce effacé
sur disque → POST valide refusé 403 (`form_missing_or_already_consumed`).
L'identifiant de session reste identique : cette reproduction n'est pas une
perte de cookie. Un nonce incorrect ou un formulaire ancien pouvait également
invalider le formulaire courant. Le nonce est désormais consommé uniquement
après validation de tous les prédicats, sous le verrou de session PHP. Les
requêtes invalides restent refusées, les rejeux après acceptation aussi.
Les contrôles Origin, CSRF, les champs autorisés et PKCE restent inchangés.

Cette cause est démontrée pour la séquence reproduite. Les observations
historiques disponibles ne prouvent pas quelle requête a précédé le refus
signalé dans le navigateur ; elles ne permettent donc pas d'attribuer avec
certitude cet incident précis à cette séquence ou à la coupure de courant.
Un redémarrage contrôlé du processus entre GET et POST, avec le même répertoire
et les cookies conservés, donne également 303 et un identifiant inchangé.
Une session effacée ou tronquée par un arrêt brutal doit toujours être refusée ;
un nouveau GET crée un nouveau nonce, sans réutiliser l'ancien formulaire.

Régression HTTP réutilisable, exclusivement locale et en mode OAuth :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\check-superpdp-oauth-http.ps1 -SessionDirectory '<répertoire privé du processus PHP>'
```

Elle vérifie l'écriture du nonce, la continuité du cookie, un GET annexe,
les refus Origin et nonce, la conservation du formulaire après refus, le POST
valide 303, le PKCE enregistré, le refus du rejeu, le refus sans cookie et un
nouveau formulaire valide. Aucun identifiant, nonce, Location ou secret n'est
imprimé. Aucun appel SUPER PDP, SQL, facture ou émission n'est effectué.

## Capture temporaire GET → POST dans Chrome

Activation uniquement sur le serveur isolé, par variable de processus
`SUPERPDP_OAUTH_DIAGNOSTIC_TRACE=1`. Le mode reste `oauth`, `LIVE=1` et le
profil reste sandbox. Aucun contrôle de `AuthorizationCodeWeb` n'est modifié.
Le routeur ajoute au HTML un identifiant aléatoire de formulaire (128 bits),
visible et soumis dans le champ `diagnostic_id`. L'adaptateur de capture retire
uniquement ce champ avant les contrôles habituels ; les autres champs imprévus,
Origin invalide et CSRF invalide restent refusés.

Pour garantir zéro appel SUPER PDP, cette capture suspend la navigation externe
après un POST valide : le moteur crée toujours state/PKCE normalement, mais le
routeur remplace sa redirection par un bilan JSON local HTTP 200. Les callbacks
sont suspendus en HTTP 423 **avant** tout échange de code. Cette suspension
concerne seulement le diagnostic isolé et disparaît avec la variable de capture.
Le parcours métier de l'application et les paramètres OAuth restent inchangés.

Le sous-dossier `http-trace` du répertoire privé de sessions contient
`trace-state.json`. Son index interne associe chaque formulaire à une empreinte
HMAC de l'identifiant de session, avec une clé aléatoire privée ; aucun identifiant
de session, cookie, CSRF, state, verifier, URL de redirection, en-tête brut ou
secret fournisseur n'y est enregistré. L'identifiant de diagnostic n'autorise
rien et ne remplace jamais un nonce.

Les événements contiennent exclusivement les identifiants de diagnostic, leur
ordre et date, méthode/route classifiées, version de code, comparaison de versions,
présence du cookie, conservation du cookie entrant par PHP, continuité de session
(`same`, `different`, `unknown`), présence du nonce avant/après et du nonce soumis,
résultat CSRF et motif exact. Un GET intermédiaire est visible par le lien
`previous_form_id`. Un POST inconnu ne fait jamais refléter sa valeur arbitraire.
Les requêtes annexes sont classifiées sans journaliser leurs chemins bruts.

Rétention : les références et événements expirent après 1800 secondes et sont
élagués à chaque requête (au plus 512 événements et 257 références). Le processus
local caché `tools/expire-superpdp-oauth-trace.ps1` supprime le fichier après
30 minutes d'inactivité, avec un contrôle toutes les 30 secondes, même sans
nouvelle requête HTTP. Le dossier est protégé par une ACL limitée à l'identité
du serveur. La collecte est temporaire : désactiver la variable de capture et
arrêter le processus de purge après analyse de l'essai.

Vérification HTTP sans fournisseur :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\check-superpdp-oauth-http.ps1 -SessionDirectory '<répertoire privé>' -Capture
```

L'essai Chrome doit partir d'un **nouveau GET** de `http://127.0.0.1:8765/`.
Vérifier que « Diagnostic local », l'identifiant et « Capture active » sont
affichés, puis cliquer **une seule fois** sur le bouton du formulaire. Ne pas
actualiser le POST ni effectuer de deuxième essai. Transmettre seulement
l'identifiant de diagnostic affiché et signaler que l'essai est terminé afin
d'analyser les traces avant leur expiration. Aucune console, copie de cookies,
capture réseau ou transmission de nonce n'est nécessaire.

Validation de l'installation : 128 tests, 650 assertions réussies ; parcours
réel testé sur le même serveur en mode OAuth normal (200 → 303 sans suivi),
puis avec capture (200 → 200 local, sans Location). Les traces distinguent sur
HTTP réel le rejeu dans la même session, le cookie absent, le cookie inconnu
remplacé par PHP strict et le formulaire ancien après un deuxième GET.
La version du HTML correspond à celle du POST. Les ACL du dossier et du fichier
ont été contrôlées. La purge sans trafic a été testée en vieillissant uniquement
le fichier des tests, et un callback factice renvoie 423 sans redirection.
Ces tests n'établissent pas la cause du refus Chrome : analyser le prochain
identifiant de diagnostic communiqué par l'utilisateur avant toute correction.
