# OAuth SUPER PDP — sécurisation locale du 10 novembre 2026

Le parcours reste Authorization Code avec PKCE S256 et scopes vides. Sources :
copie officielle OpenAPI `1.39.0.beta` du dépôt et réponse officielle du support
rapportée par le propriétaire du projet. Aucune documentation en ligne ni API
n'a été appelée pendant cette intervention.

## Consentement et limite documentaire

La copie locale ne décrit aucune valeur acceptée pour
`superpdp_send_and_receive`. Le paramètre précédemment forcé à `receive` est
donc omis. Aucune valeur combinée et aucun scope `send` ne sont inventés.
L'identité est préremplie avec les paramètres documentés
`superpdp_company_number` et `superpdp_company_number_scheme=fr_siren` ; ce
préremplissage n'est jamais une preuve d'identité.

Cette omission enlève la restriction explicite à la réception, mais ne démontre
pas le comportement effectif émission/réception de l'écran de consentement.
Il reste à obtenir le passage documentaire correspondant hors de cette étape
sans réseau, ou à observer le parcours lors d'un futur essai autorisé. Les
entreprises fictives utilisant le schéma `sandbox` ne sont pas assimilées à un
SIREN français : seules les sociétés `fr_siren` correspondant au SIRET local
sont acceptées actuellement.

## Identité, environnement et stockage

Le contexte temporaire contient utilisateur, entreprise, SIREN, environnement,
client OAuth, URL API, redirect URI, state et verifier. Il expire après dix
minutes et est consommé une seule fois, y compris en cas d'échec. Les refus et
retours incomplets effacent ce contexte sans afficher les descriptions distantes.

Le callback vérifie le propriétaire connecté et relit l'entreprise avant
l'échange. Avec le jeton reçu, il contrôle `GET /v1.beta/companies/me` : identifiant
positif, pays FR, schéma `fr_siren`, SIREN exact et environnement attendu.
L'éventuel `company_id` de la réponse de jetons doit concorder. Le propriétaire
et le SIREN sont revérifiés sous verrou avant enregistrement. Les jetons restent
chiffrés et cachés à la sérialisation.

`SUPERPDP_ENVIRONMENT` doit être explicitement `sandbox` ou `production`.
La connexion enregistre aussi `oauth_client_id` et `api_url`. Les services OAuth,
la passerelle de vérification et le téléchargement de l'original reçu refusent
un contexte absent ou différent avant d'utiliser les jetons. Le renouvellement
recontrôle l'identité distante et l'environnement.

Une seule connexion reste autorisée par entreprise. Une reconnexion au même
contexte conserve les curseurs de réception ; un autre environnement, client,
URL API ou identifiant d'entreprise distant ne remplace pas cette connexion.
Un usage simultané sandbox et production nécessiterait un travail distinct.

Migration préparée :
`2026_11_10_000001_add_oauth_context_to_superpdp_connections.php`.
Elle ajoute trois colonnes nullables, sans backfill ni modification de jetons.
Les connexions historiques sans contexte seront bloquées jusqu'à reconnexion.
La migration n'a été exécutée que par les tests sur SQLite en mémoire, jamais
sur une base persistante. Aucun changement Alwaysdata ni `.env` réel.

## Rotation et confidentialité

Le renouvellement verrouille et relit la connexion dans une transaction sans
retry automatique. Un worker attendant le verrou réutilise le jeton renouvelé
par le précédent. L'échange et l'enregistrement de la rotation restent sous
verrou. Un échec après tentative de refresh place la connexion en `error`, sans
rejouer automatiquement l'ancien jeton ; une reconnexion est nécessaire.
Une panne de base ou un arrêt du processus après rotation distante reste un
cas de récupération manuelle ; une transaction SQL ne rend pas l'API atomique.

Les erreurs OAuth sont génériques, sans réponse distante ni exception HTTP
chaînée ; les paramètres sensibles sont annotés. Les redirections HTTP lors des
échanges sont désactivées. Le téléchargement reçu ne journalise plus les corps
distants ni l'exception susceptible d'en contenir.

## Vérification d'émission

Selon le support, aucune permission `send` supplémentaire ni activation BT-34
n'est requise. Les contrôles correspondants deviennent `not_required`, avec
motifs explicites ; aucun résultat simulé n'est promu en preuve réelle. La
publication du BT-34 comme adresse de réception reste informative et ne bloque
plus le bilan d'émission. Identité, session, environnement et destination acheteur
restent contrôlés. `emission_ready` reste faux et le diagnostic local reste fermé.

Les prérequis et transports d'émission, les conversions, PDF, Stripe et le
traitement des factures reçues ne sont pas ouverts ou refondus.

## Vérification et prochain essai

Les tests utilisent SQLite en mémoire et des réponses HTTP/SDK simulées. Le
bootstrap de vérification interdit les résolveurs SQL non SQLite et les transports
réseau natifs ; il ignore le cache de configuration et force DB_URL vide.
Le test de concurrence couvre un second worker possédant un modèle périmé et la
transaction pendant le faux échange. La contention réelle entre processus
MariaDB/MySQL n'est pas testée ici, conformément à l'interdiction de base persistante.

Avant un premier essai : appliquer séparément la migration dans l'environnement
autorisé, configurer le client et la redirect URI enregistrée, confirmer le
consentement émission/réception, reconnecter une entreprise sandbox et vérifier
son identité. Il faudra ensuite raccorder, dans une intervention distincte,
les prérequis réels, l'archive CII validée, le destinataire sandbox et un transport
strictement sandbox. Aucun bouton, route, transport ou envoi réel n'est activé ici.

Résultats de vérification : suite PHP complète, **2 875 tests réussis,
17 256 assertions**. Les quatre tests supplémentaires du téléchargement reçu
ont ensuite été inclus dans la sélection ciblée : **184 tests réussis,
1 419 assertions** (OAuth, téléchargement, vérification des parties, réception,
identifiants vendeur). Pint `--test` réussit sur les dix fichiers PHP formatés
et le nouveau test de téléchargement ; le contrôleur de téléchargement existant
a été modifié uniquement aux contrôles OAuth et à la journalisation, sans
reformatage global, et passe `php -l`. `git diff --check` réussit.
Le bootstrap temporaire de blocage réseau/SQL est retiré après vérification.
