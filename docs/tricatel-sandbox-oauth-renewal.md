# Renouvellement ciblé OAuth sandbox Tricatel — préparé, non exécuté

Commande soumise à accord explicite :

```powershell
php artisan superpdp:sandbox-refresh-oauth --env=sandbox --connection=2 --execute
```

La commande existante était fixée à Burger Queen. Son option `--connection`
sélectionne désormais une cible dans une liste fermée : 1 (comportement historique)
ou 2 (Tricatel). Pour 2, les contrôles imposent utilisateur 4, entreprise locale 4
appartenant à 4 et nommée Tricatel, connexion 2 rattachée à 4, fournisseur 18171,
identité sandbox/000000001, client OAuth et URLs sandbox attendus. La configuration
d'identité Burger Queen reste inchangée ; aucune autorisation n'est ouverte.

Le mécanisme existant `SuperPdpOAuthService::refreshAccessToken` est réutilisé :
verrouillage transactionnel de la société et de la connexion sélectionnées,
rotation avec vérification de l'identité distante et stockage chiffré des jetons.
La commande utilise le propriétaire 4 en mémoire et restaure ensuite le contexte
d'authentification précédent, sans connexion web ou session persistée.

Après contrôles SQL dans la seule base `fabyjulien_simpledevis_test`, au maximum :

1. `POST https://api.superpdp.tech/oauth2/token`, formulaire contenant
   `grant_type=refresh_token`, le refresh token existant de la connexion 2,
   `client_id` et `client_secret` sandbox. Aucun secret n'est affiché.
2. `GET https://api.superpdp.tech/v1.beta/companies/me`, authentifié avec le nouveau
   jeton Tricatel, pour vérifier fournisseur 18171, sandbox/000000001 et pays FR.

Aucune redirection ni relance automatique. Si le jeton est déjà valide au moment
du verrouillage, aucun appel HTTP et aucun renouvellement supplémentaire.
En cas de refus ou d'incertitude, le mécanisme existant bloque uniquement la
connexion 2 (`status=error`) pour empêcher le rejeu de son ancien refresh token.
Les jetons Burger Queen, factures et autorisations restent inchangés.

Validation exclusivement hors réseau réel : 33 tests ciblés réussis,
170 assertions, couvrant notamment rotation Tricatel, conservation exacte de
la connexion 1, mauvais propriétaire/identité/environnement, échec distant,
absence de rejeu, jeton déjà valide et exécution explicitement requise.
SQL en mémoire et HTTP simulé uniquement. Aucune collecte de preuves,
génération CII, émission ou dépôt n'a été exécuté.
