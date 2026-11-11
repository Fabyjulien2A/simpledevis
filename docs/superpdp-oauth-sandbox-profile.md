# Profil local OAuth sandbox — préparation sans connexion

Cette étape prépare uniquement la configuration. Aucun serveur n’a été démarré,
aucun accès MariaDB ou SUPER PDP n’a été effectué, aucune migration ou connexion
OAuth n’a été lancée. Les paramètres enregistrés chez SUPER PDP sont inchangés.

## Configuration préparée

Le nouveau fichier local `.env.sandbox` est ignoré par Git. Le `.env` principal
et `.env.mariadb-test` ne sont pas modifiés. Les secrets ne sont pas reproduits
dans ce document ou dans la sortie du vérificateur.

| Paramètre | Profil proposé |
| --- | --- |
| `APP_ENV` | `sandbox` |
| `SUPERPDP_ENVIRONMENT` | `sandbox` |
| `APP_URL` | `http://127.0.0.1:8000` |
| `SUPERPDP_REDIRECT_URI` | `http://127.0.0.1:8000/facturation-electronique/callback` |
| `SUPERPDP_API_URL` | `https://api.superpdp.tech/v1.beta` |
| `DB_CONNECTION` | `superpdp_sandbox` — nom de connexion Laravel dédié |
| Driver PDO | `mysql`, compatible avec MariaDB comme le lanceur de test antérieur |
| `DB_DATABASE` | `fabyjulien_simpledevis_test` |
| `DB_USERNAME` | `fabyjulien_simpledevis_test` |
| `DB_HOST`, port et mot de passe | Copiés du fichier SQL dédié existant |
| `DB_URL`, `DB_SOCKET` | Vides, aucun remplacement de la cible par une URL/socket |
| Session | Fichier local, cookie distinct `simpledevis_sandbox_session` |
| Clé Laravel | Nouvelle clé privée propre aux essais, distincte de la clé principale |

Les identifiants OAuth existants sont recopiés dans ce fichier, sans modification.
Leur validité et leur rattachement au client prévu pour les essais ne peuvent pas
être confirmés hors ligne. Aucun identifiant d’entreprise fictive ni SIRET n’est
ajouté. La clé Laravel de test doit être conservée pour relire les futurs jetons
chiffrés de cette base ; les clés de production ne sont pas reprises.

L’adresse `127.0.0.1:8000` doit être utilisée pendant tout le parcours : ne pas
commencer la session sur `localhost` puis revenir sur `127.0.0.1`. L’URI proposée
reprend la valeur locale existante du callback. Sa présence exacte dans la fiche
du client OAuth SUPER PDP reste à vérifier plus tard ; aucun paramètre distant
n’a été changé ici.

Les conversions et les transports d’émission restent fermés. Les réglages de
session/cache/mail ne concernent que le nouveau profil ; aucune logique Stripe,
PDF ou réception n’est modifiée.

## Pas de repli vers la configuration principale

Un fichier `.env` distinct ne suffit pas à lui seul : Laravel peut lire un cache
avant dotenv et les variables du processus peuvent prendre priorité sur le fichier.
Deux garde-fous ont donc été ajoutés, uniquement pour une sélection explicite du
profil sandbox :

1. `bootstrap/app.php` reconnaît `APP_ENV=sandbox` ou la sélection CLI
   `--env=sandbox` / `--env sandbox`. Le chargeur
   `bootstrap/superpdp-sandbox-environment.php` exige le fichier dédié, vérifie sa
   cible et force ses valeurs dans le processus avant le chargement Laravel.
   Une sélection contradictoire est refusée, sans reprise du `.env` principal.
2. `config/database.php` expose alors exactement une connexion,
   `superpdp_sandbox`, avec base et compte fixés au nom de test. Une autre base,
   un autre compte, une URL/socket, un hôte/port malformé ou un mot de passe absent
   provoque un refus. Les connexions génériques `mysql`, `mariadb`, `pgsql`, etc.
   ne sont pas disponibles dans ce profil.

Le chemin de cache de configuration est imposé à
`bootstrap/cache/superpdp-sandbox-config.php` et celui des routes à
`bootstrap/cache/superpdp-sandbox-routes.php`. Pour ce premier essai, leur présence
est refusée plutôt que de risquer de reprendre une configuration périmée.
Le cache principal n’est ni utilisé ni effacé. Le profil principal `local` garde
son circuit de configuration habituel.

Le futur lancement devra sélectionner explicitement ce profil et conserver cette
sélection dans le processus serveur. Aucun lancement n’a lieu dans cette étape.
Ne pas utiliser le lanceur temporaire de validation MariaDB : celui-ci contient
des opérations de migration et de création de fixtures étrangères à cette étape.

L’exclusivité de la cible Laravel est vérifiée hors ligne. La portée réelle des
droits SQL ne peut pas être recontrôlée sans connexion. Le
[rapport MariaDB antérieur](mariadb-emission-validation.md) atteste déjà que le
compte était limité à cette base ; ce constat historique n’est pas une nouvelle
vérification du serveur.

## Entreprises fictives : blocage identifié

La copie officielle OpenAPI locale `1.39.0.beta` autorise les schémas
`sandbox`, `fr_siren` et `be_numero_entreprise`, y compris comme préremplissage
de `superpdp_company_number_scheme` dans le parcours Authorization Code.

L’implémentation actuelle préremplit cependant toujours `fr_siren`, exige un
SIRET local de 14 chiffres, puis vérifie que `companies/me` renvoie `fr_siren`,
le SIREN correspondant, le pays FR et l’environnement attendu. Une entreprise
fictive SUPER PDP identifiée par `number_scheme=sandbox` sera donc refusée.

Il faudra, dans une étape distincte, prendre en charge explicitement le couple
`sandbox` / identifiant fictif fourni par SUPER PDP dans le profil de test et
le vérifier exactement au callback. Les contrôles des entreprises françaises
de production devront rester inchangés. Il ne faut ni fabriquer un SIRET pour
remplacer cet identifiant ni ajouter maintenant un paramètre que le code ignore.
Aucune adaptation du service OAuth ou des modèles n’est faite ici.

## Migrations à vérifier ultérieurement

Les fichiers nécessaires à la connexion OAuth sont présents :

- `2026_07_13_122944_create_superpdp_connections_table.php` : table de connexion,
  entreprise propriétaire, jetons et échéance ;
- `2026_11_10_000001_add_oauth_context_to_superpdp_connections.php` : colonnes
  `environment`, `oauth_client_id`, `api_url`.

Les tables `users` et `companies`, et leurs migrations applicatives préalables,
doivent aussi exister pour utiliser l’authentification et les entreprises locales.
Le rapport MariaDB antérieur indique l’application du schéma initial et des deux
migrations du 9 novembre. Il ne confirme pas l’application de la migration OAuth
du 10 novembre : c’est le point à vérifier en priorité sur la base de test lors
d’une prochaine lecture autorisée de l’état des migrations.

Sans interrogation de la table `migrations`, aucune liste des migrations réellement
manquantes n’est affirmée. Aucune commande `migrate`, `migrate:status`, `--pretend`,
rollback, nettoyage ou réinitialisation n’est exécutée. Aucune nouvelle migration
n’est créée pour cette préparation de configuration.

## Vérifications effectuées sans application ni réseau

`tools/check-superpdp-sandbox.php` lit explicitement le profil et les fichiers de
configuration PHP, sans démarrer Laravel, construire PDO ou envoyer HTTP. Il
simule dans son propre processus des variables héritées désignant une production
fictive et le cache principal ; le profil les remplace avant lecture de la config.

Les 12 contrôles positifs passent, notamment l’unicité de la connexion, les
identifiants SQL issus du fichier dédié, la différence avec le compte principal,
l’absence de cible secondaire et la cohérence des URL. Huit mauvaises valeurs
sont refusées : base, compte, connexion, URL, socket, hôte, port et mot de passe.
Le `.env` principal reste inchangé et le fichier sandbox est bien ignoré par Git.

Le vérificateur est exécuté avec les transports réseau natifs désactivés et
`allow_url_fopen=0`. Les quatre fichiers PHP passent la vérification de syntaxe
et Pint ; `git diff --check` est exécuté. La suite PHP applicative n’est pas
lancée ici, car elle exécute des migrations en mémoire, interdites dans cette étape.

Avant une connexion OAuth, il reste donc à confirmer l’état des migrations sur la
base isolée, le client OAuth sandbox et le callback enregistré, puis à traiter
la compatibilité de l’identifiant fictif. Toute exécution réelle demeure différée.
