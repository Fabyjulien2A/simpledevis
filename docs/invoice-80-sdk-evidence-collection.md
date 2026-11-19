# Diagnostic ciblé Tricatel — préparé, non exécuté

La collecte initiale a produit un rapport `refused` le 15 novembre 2026 à
15:03:29 Europe/Paris : les contrôles vendeur sont `passed` et la destination
acheteur est `refused`. Le diagnostic du qualificateur est désormais conservé
dans `checks.buyer_destination.assessment` (`status`, `reason` et, si présent,
`classification_diagnostic`). Le statut de qualification `passed/refused`
et les règles du qualificateur restent identiques. Aucun corps distant, jeton,
en-tête ou secret supplémentaire n'est archivé.

Commande exacte à autoriser, **non exécutée** :

```powershell
php artisan superpdp:collect-invoice-evidence --env=sandbox --execute --buyer-only --source-sha256=94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89 --seller-evidence=invoice-party-checks/v1/3/80/5cfa5ce168f28ba9339bc639db3dd7aab45bcb9089175535eafb0b8848679af6.json
```

Ce mode autorise au plus **un GET** `/directory_entries` avec la connexion
Tricatel 2. Il conserve un rapport privé distinct, de type
`sandbox_buyer_destination_diagnostic`, avec `emission_ready=false` et affiche
le résultat structuré ainsi que sa référence privée. Ce rapport n'est pas une
nouvelle qualification complète de la facture. Aucun GET vendeur, refresh,
CII, émission ou changement de facture n'est effectué.

Les contrôles vendeur sont repris seulement si le contexte, le jeton vendeur
(empreinte uniquement), la provenance live et la validité existante de quinze
minutes concordent. Leurs dates d'origine sont conservées. En cas de preuve
expirée ou incompatible, le rapport indique `seller_evidence.status=unavailable`
et la collecte reste limitée au diagnostic acheteur. Une référence illisible
ou invalide arrête l'opération avant réseau.

Le diagnostic local sans API a confirmé l'empreinte source ci-dessus et
l'expiration des preuves vendeur. Il constate aussi que les jetons ont depuis
expiré : Burger Queen à 15:18:44 et Tricatel à 15:32:45 Europe/Paris.
**La commande s'arrêtera avant tout GET tant qu'un jeton Tricatel valide n'est
pas déjà disponible.** Le renouvellement nécessite une opération distincte ;
il ne fait pas partie de cette correction ou de cette commande.

Recontrôle local autorisé sans API, sans archive :

```powershell
php artisan superpdp:collect-invoice-evidence --env=sandbox --diagnose --buyer-only --seller-evidence=invoice-party-checks/v1/3/80/5cfa5ce168f28ba9339bc639db3dd7aab45bcb9089175535eafb0b8848679af6.json
```

## Préparation initiale (historique, ne pas utiliser pour le diagnostic ciblé)

Référence exacte : `SBX-ea09521e-ab70-4f90-aa63-8268d743d151`.
Contrôle SQL ciblé en lecture seule le 15 novembre 2026 : facture définitive,
propriétaire 3, profil `sandbox_superpdp_bq_tricatel_v1`, contexte et données
figées validés par les builders existants. Aucun appel API effectué.

## Commande soumise à autorisation explicite

```powershell
php artisan superpdp:collect-invoice-evidence --env=sandbox --execute --source-sha256=94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89
```

Cette commande ne doit pas être exécutée avant accord explicite. Elle échoue
actuellement avant tout GET : la connexion Tricatel 2 possède un jeton expiré
le 14 novembre 2026 à 13:02:13 Europe/Paris. Le jeton Burger Queen 1 expire
le 15 novembre à 15:18:44 Europe/Paris. Une marge de cinq minutes est imposée
par le modèle existant. Aucun renouvellement ne fait partie de cette opération.

## Réseau prévu

Le bootstrap sandbox existant sélectionne uniquement la base
`fabyjulien_simpledevis_test`, connexion `superpdp_sandbox`. Les lectures SQL
de contrôle sont faites en transactions READ ONLY puis annulées, sans écriture.

Au plus quatre GET SDK séquentiels, sans paramètres, sur
`https://api.superpdp.tech/v1.beta` :

| Ordre | Endpoint | Connexion OAuth | Contrôle |
| --- | --- | --- | --- |
| 1 | `/companies/me` | Burger Queen 1, société locale 3 | Fournisseur 18172, sandbox/000000002, FR |
| 2 | `/oauth2_sessions/me` | Burger Queen 1 | Même jeton, client OAuth sandbox attendu, statuts de vérification |
| 3 | `/directory_entries` | Burger Queen 1 | Adresse vendeur 0225:315143296_18172 |
| 4 | `/directory_entries` | Tricatel 2, société locale 4 | Fournisseur 18171, sandbox/000000001, Tricatel FR, publication Peppol unique created, adresse 0225:315143296_18171, is_replyto=false |

Les trois contrôles applicatifs nécessitent donc quatre réponses SDK.
L'annuaire vendeur est une condition du service `SandboxInvoiceEvidence::record`.
Le GET Tricatel utilise explicitement sa connexion existante, sans connexion
interactive ni impersonation de l'utilisateur 4.

Une seule tentative par requête, TLS vérifié, aucune redirection, aucun retry,
aucun refresh automatique. Arrêt à la première erreur technique ; les réponses
reçues sont conservées sous forme d'empreintes et de provenance. Les divergences
d'identité ou de qualification donnent un rapport refusé, jamais une permission.
Relecture des empreintes et des jetons avant chaque GET puis avant l'assemblage.

## Empreintes contrôlées

| Élément | SHA-256 |
| --- | --- |
| Source | `94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89` |
| Payload documentaire | `9d7f9085d085fa0e4fe602b42f13c796921aeb230570dae19117bdb8b9dba871` |
| Instantané parties | `f7d36f7ee61b359544e5040e39300b9696d89575123e5015d8f8db955cc35df9` |
| Contexte figé | `e72f8f44d16a16c715b82468d3319615f5a72f376503fb977c17f44f5dd32b34` |
| Déclaration sandbox | `4b51f4547ca3fee0dd8f02b7b8dc8da71c05e8913418f5d55a176d8067381e6c` |
| Document enregistré | `7e3446e484de45bb088910cf67d671b7e70b8604416dd2a286712599b5131251` |

## Conservation et limites

Réutilisation de `SandboxSellerEvidence::fromResponse`,
`SandboxInvoiceEvidence::record`, `SellerEmissionQualification`, du qualificateur
Tricatel et de `VerificationAuditStore`. Pas de nouveau moteur CII ou d'émission.

Le stockage privé `invoice_cii/party-checks/v1/3/80` conserve des checkpoints
immuables adressés par SHA-256, un UUID commun de collecte, les intentions puis
les reçus (horodatages, méthode, URL, paramètres vides, classe SDK, connexion,
empreinte du jeton et du corps, statut HTTP, provenance live/cached/simulated).
Aucun corps distant, en-tête Authorization ou secret OAuth n'est enregistré.
Le rapport final conserve les quatre provenances et la référence de collecte ;
son `audit_reference` est utilisable par les services existants, sous leurs
propres contrôles de fraîcheur maximale de quinze minutes et d'autorisation.
Un échec conserve le dernier checkpoint sans rejouer les GET.

Le stockage est vérifié par une première écriture avant tout appel API. S'il
est indisponible, la collecte s'arrête avant réseau. Ses droits effectifs n'ont
pas été exercés par le diagnostic local sans écriture.

`ready_for_emission=false` et `emission_ready=false` restent explicites.
Aucune génération CII, conversion, validation distante, création de facture,
modification SQL, réservation de dépôt ou émission n'est incluse. Aucune
configuration d'autorisation de préparation ou d'émission n'est ouverte.

Diagnostic ciblé sans API ni archive :
`php artisan superpdp:collect-invoice-evidence --env=sandbox --diagnose`.

Vérification hors API : syntaxe PHP valide ; tests ciblés
`CollectSandboxInvoiceEvidenceTest.php` et `SandboxInvoiceProfileTest.php` :
29 réussis, 354 assertions. Les tests interdisent les appels HTTP réels et
utilisent uniquement SQLite en mémoire et le stockage simulé.
