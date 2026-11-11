# Transport d’émission sandbox — préparation du 11 novembre 2026

Le raccordement est préparé mais reste fermé. Aucun appel réseau réel, dépôt,
conversion ou validation distante n’a été effectué. Aucune migration nouvelle
n’est nécessaire ; les migrations existantes ne sont exécutées que sur SQLite
en mémoire pendant les tests. Stripe, les PDF, la réception et le consentement
OAuth ne sont pas modifiés par cette étape.

## Raccordement et verrou global

`SandboxEmissionFactory::forInvoice($actor, $invoice, $reference)` construit
l’orchestrateur `OutgoingEmission` existant avec les prérequis locaux sandbox
et `SandboxSdkEmissionTransport`. Il n’existe aucun nouveau bouton, formulaire,
contrôleur, commande ou déclencheur planifié. Les liaisons par défaut du conteneur
restent `ClosedEmissionPrerequisites` et `DisabledEmissionTransport`.

La configuration livrée `config/superpdp_emission.php` contient :

```php
'sandbox_enabled' => false,
'sandbox_client_id' => null,
'authorization' => null,
```

Ces trois valeurs ne sont pas alimentées par des variables d’environnement ni
des paramètres HTTP. Aucun test réel n’est autorisé par cette livraison.
Une future ouverture devra faire l’objet d’une autorisation explicite de
l’utilisateur, puis d’un paramétrage de confiance limité au cas approuvé.
Un simple booléen ne suffit pas : l’autorisation doit contenir exactement le
périmètre contrôlé, notamment :

- `approved_by`, `approved_at`, `expires_at` : opérateur identifié et timestamps
  entiers ; durée maximale 15 minutes, sans date future ni autorisation expirée ;
- `user_id`, `invoice_id`, `document_reference`, `cii_sha256`,
  `provider_company_id` : propriétaire, facture, archive et octets approuvés ;
- `environment=sandbox`, `oauth_client_id`, `api_url` : contexte explicite ;
- `attempt_reference` : UUID exact de la tentative, indispensable avant la prise
  en charge du dépôt. Il peut être renseigné après la réservation locale.

La réservation ne transmet rien. Une autorisation de document permet la
réservation ; l’UUID produit doit ensuite être approuvé avant `dispatch()`.
Une nouvelle tentative après refus certain exige une nouvelle approbation de
son UUID. Aucun UUID de tentative n’est réutilisé pour une nouvelle tentative.

## Contrôles avant toute requête

L’environnement applicatif doit être `local`, `staging`, `sandbox` ou `testing` ;
`production` est refusé, même avec une connexion présentée comme sandbox.
`services.superpdp.environment` et l’environnement enregistré sur la connexion
doivent être explicitement `sandbox`. Les connexions historiques non qualifiées
sont refusées. Client OAuth désigné, client courant, client de la connexion,
autorisation et preuves archivées doivent concorder.

L’URL API requise est exactement `https://api.superpdp.tech/v1.beta`, sans suffixe,
autre hôte ou repli implicite. Le SDK reçoit l’origine
`https://api.superpdp.tech`, car ses requêtes ajoutent déjà `/v1.beta`. Il n’est
pas inventé d’hôte sandbox distinct : l’environnement est aussi attesté par la
connexion OAuth qualifiée et les preuves authentifiées d’identité de société.

Le jeton doit être actif, déchiffrable et valable au-delà de la marge de cinq
minutes. Aucun refresh automatique n’est effectué par ce transport ; la réception
conserve son parcours OAuth existant. Avant l’envoi SDK, le verrou, les droits
locaux, le contexte, les preuves et les octets sont revérifiés. Une rotation du
jeton entre construction du connecteur et requête provoque un refus.

Le POST exige une tentative `pending` réellement réservée et prise en charge,
avec empreinte concordante, puis commitée hors transaction. L’UUID approuvé est
transmis comme `external_id`. TLS reste vérifié, les redirections sont interdites,
le SDK ne fait qu’une tentative HTTP et les précontrôles fournisseur ne sont pas
désactivés. Aucun corps distant, XML ou secret n’est journalisé par le transport.

## Lecture des archives validées

`PreparedCiiArchive::readValidated()` réutilise le stockage privé, les contrôles
de propriétaire, chemins, taille et intégrité déjà développés. Seul le namespace
`invoice-cii/v1/validated/{user}/{invoice}/{sha256-manifeste}` est accepté.
Les archives `simulations`, chemins étrangers, liens symboliques, stockage public
ou servi, octets altérés et JSON ambigus sont refusés.

Le manifeste de contrat version 1 doit attester `mode=validated_private_document`,
`provenance=live`, `ready_for_emission=true`, l’utilisateur, la facture, le contexte
sandbox/client/API/société et les empreintes du CII, des sources historiques, de
l’instantané des parties, du contexte figé et du payload. Le lecteur ne crée ni
ne requalifie aucune archive. Le writer existant conserve exclusivement son
contrat de simulation.

Le [producteur d’archives CII](superpdp-cii-producer.md) dispose désormais d’un
parcours séparé de conversion/validation API, fermé par défaut. Son writer déduit
la provenance des réponses SDK et publie dans `validated` uniquement après une
validation effectivement réelle ; simulations et documents en attente restent
dans des namespaces non autorisés.

Les preuves nécessaires sont :

- une préparation et une validation datées de moins de 15 minutes, une validation
  `passed` de provenance `live`, son rapport et l’empreinte du CII exact ;
- une référence privée `verification_reference` vers `VerificationAuditStore`,
  avec bilan `passed`, sources identiques, environnement, société, client OAuth
  et URL API concordants ;
- trois contrôles `passed`, `source=live`, `verified=true`, horodatés récemment
  et accompagnés d’une empreinte de réponse : destination acheteur, identité
  vendeur et session vendeur. Simulation, cache et preuve partielle sont refusés.

Le service réutilise le diagnostic local, le builder et `CiiDocumentChecks` pour
comparer les octets aux données persistées et contrôler le rapport. Ces contrôles
ne remplacent pas la validation fournisseur. La provenance des fichiers privés
est une frontière de confiance du backend : le producteur réel devra conserver
la provenance effective et ne jamais étiqueter une simulation comme preuve réelle.
Les champs `expected_oauth_client_id` et `expected_api_url` sont désormais figés
dans les nouveaux comptes rendus de vérification ; les anciens comptes rendus
incomplets ne suffisent pas.

Aucune permission API `send` ni activation BT-34 n’est ajoutée. La publication
d’une adresse de réception n’est pas une exigence d’émission. Aucune valeur de
`superpdp_send_and_receive` n’est inventée.

## Doublons, inconnu et suivi

La réservation, les verrous, UUID, contraintes d’unicité, transitions et règles
de reprise sont ceux de l’orchestrateur existant. Le registre distingue maintenant
les exécutions `test` et `sandbox` ; un registre de test ou de production ne peut
pas être repris par ce transport sandbox.

Les doubles clics et appels répétés réutilisent la même tentative sans nouveau
POST. Les timeouts, exceptions, redirections et réponses ambiguës deviennent
`unknown` sans renvoi automatique. Seul un refus certain permet une demande
explicite de nouvelle tentative, elle-même soumise à une nouvelle autorisation
de son UUID. Une prise en charge interrompue ne revient jamais dans la file.

La réconciliation réutilise les GET du SDK, le contrôle de société, de corrélation
et l’empreinte de l’original exact. Elle ne fait jamais de POST. Les événements
restent rattachés au registre sandbox autorisé. Ces lectures restent soumises au
verrou global et à une autorisation fraîche, mais ne nécessitent pas de requalifier
l’ancienne archive CII : une autorisation renouvelée peut donc servir à examiner
un résultat inconnu longtemps après le dépôt, sans réémettre.

## Avant un premier dépôt autorisé

1. Choisir un environnement applicatif hors production, appliquer séparément les
   migrations antérieures dans la base sandbox autorisée et qualifier/reconnecter
   l’entreprise via OAuth. Confirmer le comportement émission/réception du
   consentement sans inventer de paramètre.
2. Désigner le client OAuth sandbox attendu ; vérifier connexion, société, URL
   officielle et validité du jeton. Préserver les données et connexions de réception.
3. Obtenir les vraies preuves de destination et d’identité, puis produire et
   archiver les octets CII réellement validés avec le contrat ci-dessus. Le
   producteur est maintenant raccordé, mais ses appels API réels restent à autoriser
   séparément. Ses résultats simulés ou en attente sont refusés pour le dépôt.
4. Présenter à l’utilisateur facture, destinataire, société, environnement,
   référence et empreinte de l’archive. Obtenir son autorisation explicite, limitée
   à ce test, avant d’ouvrir le verrou et de renseigner l’autorisation de confiance.
5. Réserver localement la tentative, figer son UUID dans l’approbation, puis
   autoriser son unique dépôt. En cas d’inconnu, examiner et réconcilier ; ne pas
   renvoyer. Replacer le verrou global en position fermée après le test.

La contention réelle entre processus MariaDB/MySQL et les réponses réelles SUPER PDP
restent à vérifier lors d’une intervention distincte autorisée. Cette livraison
teste les chemins avec SQLite en mémoire et le SDK simulé exclusivement.

## Vérification locale

Résultats du 11 novembre 2026 :

- 80 nouveaux tests sandbox réussis, 444 assertions ;
- 270 régressions ciblées réussies, 2 507 assertions : orchestration, jonction de
  bout en bout, préparation CII, vérification des parties, OAuth et réception ;
- suite PHP complète : **2 959 tests réussis, 17 717 assertions**, 283,24 secondes ;
- Pint `--test` réussi sur les neuf fichiers PHP de cette étape et
  `git diff --check` réussi.

Le bootstrap temporaire imposait SQLite `:memory:`, DB_URL vide et absence de
cache de configuration applicative. Les résolveurs SQL non SQLite et toute base
SQLite persistante étaient refusés. `allow_url_fopen=0` et la désactivation des
transports réseau natifs empêchaient tout repli réel ; cURL était remplacé par
des exceptions. Les réponses du SDK étaient exclusivement simulées en mémoire.
Ce bootstrap a été retiré après la vérification. Les fixtures privées utilisées
dans les tests ne constituent aucune preuve réelle et ne sont pas promues vers
le stockage applicatif.
