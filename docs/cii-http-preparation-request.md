# Demande HTTP de préparation CII : activation sandbox explicite

Route : `POST /invoices/{invoice}/electronic-preparation/prepare-cii`, nommée
`invoices.request-cii-preparation`. Elle appartient au groupe authentifié `web`,
avec le CSRF Laravel existant et une limite de trois demandes par minute et par
utilisateur. Une demande limitée reçoit HTTP 429 et un message français ; les
contrôles applicatifs renvoient HTTP 303 vers la page de préparation, ancre
`#cii-document-title`, avec les messages de résultat dans la session.

## Contrôles

`InvoiceCiiPreparationRequestController` relit la facture et son propriétaire,
exige une facture définitive, puis recherche l'entreprise du propriétaire.
Le schéma actuel lie l'entreprise par `user_id` ; un éventuel `company_id`
contradictoire sur la facture est refusé. La connexion doit appartenir à cette
entreprise et correspondre au contexte OAuth exigé.

La demande utilise les diagnostics documentaires et `CiiPreparationReadiness`
existants. Aucun instantané, profil, référence, montant, identifiant, booléen de
qualification ou autorisation ne provient du corps HTTP. Les empreintes sont
calculées à partir d'une relecture de la facture figée. Une différence avec
l'empreinte contrôlée par le bilan entraîne un refus.

Avant toute décision finale, le contrôleur réutilise
`CiiPreparationGuards::configuration()`, `connection()`, `authorization()` et
`CiiPreparationEvidence::assess()` pour recontrôler l'autorisation configurée côté
serveur et les preuves. Les exigences SIRET, TVA, adresse 0225, profil, fiscalité,
identité vendeur et routage ne sont pas modifiées.

Le contrôleur consulte d’abord le registre avec `CiiPreparationRegistry::consult()` :
un résultat terminé et intègre est signalé comme déjà disponible, une réservation
en cours bloque la demande, une réservation incertaine reste à examiner. Le contrôleur
ne crée pas lui-même de réservation et ne relance aucune conversion existante.

## Raccordement fermé par défaut

`superpdp_preparation.web_enabled=false` ferme le raccordement HTTP indépendamment
de `live_enabled`. Même avec des preuves et une autorisation valides, le producteur
n’est pas résolu lorsque ce nouveau verrou est fermé. Le statut est alors `closed`.

L’activation web exige simultanément `app()->environment('sandbox')`,
`config('app.env') === 'sandbox'`, `web_enabled === true` et les contrôles partagés
de contexte/client. Local, testing, staging et production ne peuvent pas ouvrir ce
parcours HTTP. Aucun paramètre de formulaire ne constitue une autorisation.

Après tous les contrôles et une dernière vérification du verrou web, le contrôleur
résout `InvoiceCiiPreparation` et appelle uniquement
`produce($actor, $invoice, $verificationReference)` avec la référence serveur.
Il ne fournit ni passerelle, ni payload, ni autorisation issue du navigateur.
Le producteur conserve la réservation atomique, la relecture des empreintes, les
contrôles de passerelle, la conversion, la validation et l’archivage existants.

La réponse redirige vers la même section Document CII : préparation validée,
résultat non validé, simulation explicitement identifiée, opération en cours ou
état à examiner. Une exception après l’appel n’est jamais présentée comme une
preuve d’absence d’appel distant. Aucune relance automatique. La consultation et
le téléchargement privés existants contrôlent l’intégrité du résultat ; aucun
chemin d’archive ou contenu d’erreur distant n’est exposé dans les messages.

Le GET reste local et sans préparation. Le bouton devient un formulaire POST avec
CSRF uniquement si tous les prérequis passent, le verrou web est ouvert et aucun
résultat/réservation n’est affiché. Sinon, il reste désactivé. Le serveur recontrôle
tout au POST, même si la page affichée était valide quelques instants auparavant.

## Conditions avant une première préparation réelle sandbox

Avant d’activer une première préparation depuis l’interface, toutes les conditions
suivantes sont nécessaires :

- Environnement sandbox isolé ; aucun verrou de production ou d'émission ouvert.
- Facture définitive possédée par le demandeur, instantanés et contexte figés
  exploitables, contrôles documentaires existants réussis. Aucun identifiant
  fictif de remplacement ; Tricatel sans SIRET/TVA ne bénéficie d'aucune exception.
- Client `superpdp_preparation.sandbox_client_id` explicite, identique au client
  applicatif et à celui de la connexion ; environnement et URL sandbox attendus.
- Connexion OAuth qualifiée, identifiant fournisseur positif, jetons présents et
  expiration à plus de cinq minutes, sans renouvellement automatique.
- Bilan privé correspondant à la facture et à ses empreintes : identité vendeur,
  session vendeur et destination acheteur qualifiées par des preuves réelles,
  ni simulées ni en cache, datant d'au plus quinze minutes.
- `superpdp_preparation.web_enabled=true`, `live_enabled=true` et autorisation serveur bornée :
  `approved_by`, `approved_at`, `expires_at` (durée maximale quinze minutes),
  `user_id`, `invoice_id`, `source_sha256`, `verification_reference`,
  `provider_company_id`, `environment=sandbox`, `oauth_client_id`, `api_url`.
- Stockage privé partagé par les processus, verrous fiables et registre disponible.
  Aucun état en cours ou à examiner ne doit être réinitialisé pour forcer un essai.

Une première décision réelle doit couvrir explicitement les deux opérations
distantes de conversion et de validation exécutées par `produce()`. Elle n’autorise
aucun dépôt de facture. Le verrou web n’ouvre aucun transport d’émission.
`superpdp_documentary`, `first-reference` et son rapport validé n’entrent jamais
dans ce parcours : ils ne sont ni une source de facture ni des preuves de parties.
La configuration livrée reste fermée et aucune de ces opérations n'est exécutée
par cette étape.

## Tests

### Diagnostics privés des échecs

Le producteur conserve les codes de résultat existants, puis ajoute `failure_stage`,
`failure_kind`, `diagnostic_status` et, si le stockage réussit, `diagnostic_reference`.
Le lanceur `tools/prepare-invoice-80-cii.php` affiche ces champs. Une référence
`invoice-party-checks/v1/3/80/<sha256>.json` correspond au fichier privé
`storage/app/invoice-cii/party-checks/v1/3/80/<sha256>.json` ; le rapport est aussi
consultable avec `VerificationAuditStore::read($actor, $invoice, $reference)`,
qui vérifie propriétaire et intégrité. Aucun accès public n'est ajouté.

Le rapport `kind=cii_preparation_diagnostic` distingue transport/timeout, HTTP
non-200, contenu inattendu, XML invalide, contrôles CII locaux et validation.
Il conserve le statut HTTP disponible, le type MIME normalisé, la taille,
l'empreinte SHA-256 et la provenance de la réponse. Aucun XML, corps HTTP,
payload, en-tête d'authentification ou exception brute n'est journalisé.
Le filtre extrait les champs documentés de `http_ko` (`code`, `http_status_code`,
`message`) et reconnaît des extensions de validation courantes sans les présenter
comme garanties contractuelles. Il conserve les champs et types d'erreur utiles,
masque les valeurs rejetées, secrets, données personnelles et valeurs du payload
éventuellement répétées dans les messages. Il ne copie ni headers ni payloads.
Un format inconnu produit `remote_error_format=unrecognized` avec un motif explicite.
Les anciens messages déjà remplacés par `[filtered]` ne sont pas récupérables.
Un échec de stockage est
signalé par `diagnostic_status=unavailable` et ne débloque jamais le registre.
Les anciennes tentatives sans diagnostic restent inchangées ; aucune relance
ou réinitialisation de `review_required` n'est automatique.

`tests/Unit/Http/CiiPreparationRequestTest.php` utilise des tables simulées en PHP,
un cache/session en mémoire, un disque de test et des rapports synthétiques. Le
CSRF réel est exercé en désactivant seulement son exemption automatique de test.
Les transports Laravel et SDK sont interdits ; la résolution du producteur
échoue volontairement pour les parcours fermés ou refusés. Les autorisations forgées/périmées/incompatibles,
empreintes modifiées, doublons, limites et redirections sont couverts. Les données
des factures restent identiques et les journaux/archives des doublons sont
comparés avant et après le POST. Aucun SQL réel, appel réseau ou véritable CII
n'est produit. `RealCiiPreparationTest` utilise exclusivement SQLite `:memory:` et
des réponses SDK simulées pour exercer le POST, le vrai producteur, les réservations,
les interruptions, la conservation du CII, la consultation et le téléchargement.
Les simulations ne sont jamais promues en documents réellement validés.
