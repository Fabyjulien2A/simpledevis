# Producteur d’archives CII — préparation locale du 11 novembre 2026

Le producteur est raccordé sans activer de dépôt. Aucun appel réseau réel,
conversion réelle ou validation fournisseur effective n’a eu lieu pendant ce
développement. Aucune archive n’a donc été créée comme réellement validée par
ce nouveau producteur lors de cette étape. Les tests emploient des réponses SDK
simulées et un cas de cache construit en mémoire, toujours non autorisés à l’envoi.

## Deux niveaux de validation

Les sources sont exclusivement celles de `InvoiceExportDataBuilder::buildElectronic()` :
facture définitive enregistrée, instantané historique des parties et contexte
d’émission figé. Le producteur réutilise le diagnostic local, le builder EN16931,
son validateur structurel et `CiiDocumentChecks`. Il ne modifie ni la facture,
ni ses lignes, ni son historique, ni la connexion ou les curseurs de réception.

Les contrôles locaux vérifient l’éligibilité du périmètre, la structure du payload,
les calculs déjà enregistrés et la concordance du XML avec le payload : identité,
adresses électroniques, références, mentions, lignes, taxes et totaux. Ils ne
constituent pas une validation XSD/Schematron complète ou une certification fiscale.

La copie officielle OpenAPI locale `1.39.0.beta` décrit deux opérations distinctes :

- `POST /v1.beta/invoices/convert?from=en16931&to=cii`, avec le JSON EN16931 ;
- `POST /v1.beta/validation_reports`, avec les octets exacts du CII dans une pièce
  multipart nommée `invoice.xml`.

Ces opérations portent toutes deux `security: []` dans ce contrat. Le connecteur
de préparation ne transmet donc aucun jeton OAuth ni secret à ces deux endpoints.
La connexion OAuth qualifiée reste nécessaire pour contrôler le contexte et la
cohérence avec les preuves d’identité et de destination obtenues antérieurement.
Le code ne suppose pas qu’une simple conversion équivaille à une validation.

## Points d’entrée et verrou indépendant

Le parcours historique `InvoiceCiiPreparation::prepare()` et son
`OfflinePreparationGateway` restent des simulations, sans changement de contrat.
Le nouveau parcours est :

```php
app(InvoiceCiiPreparation::class)->produce($actor, $invoice, $verificationReference);
```

Sans autorisation API, le résultat est bloqué, avec `requires_api=true`, les
contrôles locaux effectués et aucune archive XML inventée. Il n’existe pas de
convertisseur CII local ajouté à ce projet : une véritable conversion et une
validation fournisseur nécessitent les appels API ci-dessus.

La configuration livrée `config/superpdp_preparation.php` reste :

```php
'live_enabled' => false,
'sandbox_client_id' => null,
'authorization' => null,
```

Elle est indépendante du verrou d’émission. Aucun paramètre HTTP ou simple flag
d’environnement ne remplit l’autorisation. Il n’y a aucune nouvelle route, commande,
planification ou action automatique. Le transport d’émission conserve sa
configuration fermée et n’est jamais appelé par ce producteur.

Pour une future exécution autorisée, l’approbation de préparation doit désigner
`approved_by`, `approved_at`, `expires_at` (timestamps entiers, durée maximale
15 minutes), `user_id`, `invoice_id`, `source_sha256`, `verification_reference`,
`provider_company_id`, `environment=sandbox`, `oauth_client_id` et `api_url`.
Client désigné, client applicatif et client enregistré doivent concorder.
L’URL est exactement `https://api.superpdp.tech/v1.beta` ; le SDK reçoit l’origine
officielle, ses requêtes ajoutant déjà le préfixe `/v1.beta`.

Les environnements applicatifs autorisés sont local, staging, sandbox et testing.
Production, connexion sans environnement, client différent, jeton expiré ou proche
de l’expiration, preuve étrangère/périmée ou données historiques incohérentes sont
refusés. Aucun refresh OAuth n’est déclenché. Seules les deux opérations POST
ci-dessus sont permises ; redirections et retries automatiques sont désactivés.

## Qualification des parties et cohérence

`CiiPreparationEvidence` relit la référence privée de `VerificationAuditStore`.
Le bilan, les empreintes des sources, les parties figées, le contexte, la société,
l’environnement, le client OAuth et l’URL doivent concorder. Destination acheteur,
identité vendeur et session vendeur doivent être qualifiées et horodatées depuis
moins de 15 minutes. Pour un appel API réel, les trois preuves doivent être
`source=live` et `verified=true`. Les sources de simulation ou de cache restent
utilisables uniquement pour des résultats non autorisés.

L’identité et le routage ne sont pas déduits d’un SIRET, d’une adresse déclarée ou
d’un booléen de l’appelant. Aucune permission API `send` ou activation BT-34 n’est
ajoutée. Les profils courants ne remplacent jamais l’instantané historique.

Avant chaque requête, le contexte et les empreintes sont relus. Le payload soumis
doit correspondre au builder historique et le XML soumis à validation doit lui
correspondre. Après les réponses, les sources sont relues encore une fois. Le writer
relit les données et verrouille utilisateur/facture avant publication locale ; une
modification détectée fait échouer l’opération sans réparer ni écraser la facture.

## Provenance et stockage privé

Le gateway retourne des `VerificationObservation` existantes. La provenance est
calculée à partir de `Response::isMocked()` et `isCached()`, jamais d’un champ JSON
`source`, d’un drapeau de formulaire ou d’un manifeste fourni par l’appelant.
Une réponse manquante du mock ne possède aucun repli réseau.

`PreparedCiiArchive::storeProduced()` construit lui-même le manifeste à partir des
sources persistées, des reçus SDK et des preuves privées. Il vérifie le lien entre
l’empreinte du payload soumis, les octets convertis et ceux soumis à validation.
Le XML n’est ni réécrit ni reformaté après conversion. Le manifeste conserve :

- propriétaire, facture, société, environnement, client et URL API ;
- SHA-256 des sources, du payload, de l’instantané et du contexte figés ;
- SHA-256 des octets XML exacts ;
- provenance et horodatage des deux opérations, empreintes des réponses et des
  données d’entrée, rapport de validation et référence des preuves de parties ;
- distinction entre cohérence locale et validation fournisseur.

Les espaces d’archivage sont strictement séparés :

| Espace privé | Conditions | Autorisation documentaire |
| --- | --- | --- |
| `v1/simulations` | Au moins une conversion, validation ou preuve des parties simulée | `offline_simulation`, `ready_for_emission=false` |
| `v1/pending` | Octets connus, mais validation absente/échouée, source de cache ou preuve réelle insuffisante | `pending_validation`, `ready_for_emission=false` |
| `v1/validated` | Conversion, validation et preuves effectivement réelles ; rapport valide lié aux octets exacts | `validated_private_document`, `ready_for_emission=true` |

Le dernier indicateur qualifie le paquet documentaire ; il n’ouvre pas le transport
et ne remplace pas l’autorisation distincte de dépôt. Le résultat du producteur
précise toujours `deposit_performed=false`.

Les contrôles privés de chemins, symlinks, tailles et exposition sont réutilisés.
La publication temporaire puis le renommage atomique préservent les octets et le
manifeste immuables. Une publication identique réutilise la même référence ; une
archive altérée est refusée et laissée intacte. Aucune archive de simulation n’est
déplacée, renommée ou promue en archive validée. Une nouvelle exécution produit
de nouveaux reçus et peut créer une nouvelle référence, sans écraser l’ancienne.

## Échecs et résultats incertains

Une conversion indisponible, vide, malformée, de mauvais type ou étrangère à la
facture n’engendre aucune archive autorisée. Si des octets convertis cohérents
sont connus mais que la validation échoue ou reste incertaine, ils sont conservés
en état non autorisé, sous réserve que les sources et preuves restent cohérentes
et fraîches. Aucun succès de validation n’est déduit du simple contrôle local.

Les erreurs retournées ne comprennent pas le corps distant ou les secrets.
Aucun retry de conversion ou validation n’est automatique. Les opérations de
dépôt et leurs registres ne sont pas invoqués ni modifiés : protections contre
les doublons et résultats d’émission inconnus restent inchangées.

## Conditions du premier essai sandbox

1. Appliquer séparément les migrations antérieures dans un environnement autorisé,
   qualifier la connexion OAuth sandbox, confirmer le parcours de consentement
   et désigner le client attendu, sans modifier la réception.
2. Autoriser les lectures réelles de qualification des parties puis obtenir les
   preuves fraîches correspondant exactement à la facture historique.
3. Obtenir l’autorisation explicite de transmettre ce payload aux deux API de
   préparation, renseigner l’approbation limitée aux sources et à la référence de
   preuve, puis exécuter le producteur avec le verrou d’émission toujours fermé.
4. Contrôler les vrais rapports et l’archive obtenue. Seul un paquet du namespace
   `validated` effectivement réel pourra être présenté pour une autorisation de
   dépôt distincte, portant sur son empreinte puis sur l’UUID de la tentative.

Les appels réels, la conformité de leurs réponses au contrat local et la contention
entre processus sur la base cible restent à vérifier ultérieurement. Aucune
migration supplémentaire n’est nécessaire pour ce producteur.

## Résultats de vérification

Le 11 novembre 2026 : **203 tests ciblés réussis, 1 532 assertions**, dont
**61 nouveaux tests** du producteur. La suite PHP complète réussit avec
**3 020 tests, 18 379 assertions**, en 293,41 secondes. Pint `--test` sur les six
fichiers PHP de cette étape et `git diff --check` réussissent.

Les cas couvrent notamment les archives exactes et idempotentes, données
historiques incomplètes, XML malformé/étranger, erreurs et redirections API,
rapports négatifs ou indisponibles, environnements de production ou inconnus,
preuves incohérentes/périmées, modifications concurrentes et refus des archives
altérées. Les tests démontrent aussi l’absence de promotion des mocks, même
lorsque le contenu du rapport prétend être réel, et le maintien d’un reçu de cache
dans le namespace non autorisé `pending`.

Le bootstrap temporaire imposait SQLite `:memory:`, DB_URL vide et l’absence de
configuration applicative en cache. Tous les résolveurs SQL non SQLite et toute
base SQLite persistante étaient refusés. `allow_url_fopen=0` et les transports
réseau natifs désactivés empêchaient tout repli réel ; cURL était remplacé par des
exceptions. Les réponses SDK étaient en mémoire. Le bootstrap est supprimé après
la vérification. Aucun appel API effectif ni migration persistante n’a eu lieu.

Le parcours positif avec réponses réseau réellement reçues n’a donc pas été
éprouvé ici ; il constitue le prochain essai distinct à autoriser, sans dépôt.
