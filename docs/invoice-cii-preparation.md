# Étape 2 : routage et préparation CII hors ligne

Le parcours de simulation décrit ici est conservé. Le nouveau
[producteur d’archives CII](superpdp-cii-producer.md) ajoute une préparation API
sandbox séparée, fermée par défaut, avec une provenance calculée depuis le SDK.
Aucun résultat simulé de cette étape n’est promu en validation réelle.

## Périmètre et sécurité

Le parcours interne `InvoiceCiiPreparation::prepare(User, Invoice,
OfflinePreparationGateway, clientId, environment)` prépare une **simulation** à
partir d’une facture définitive enregistrée et qualifiée à l’étape 1.
Il n’est raccordé à aucun bouton, route, tâche planifiée ou commande d’émission.
Il ne change aucune facture ni aucun statut SQL.

Le transport réutilise le SDK installé, mais accepte exclusivement des
`MockResponse` en mémoire, pour cinq classes de requêtes autorisées :

- GET annuaire des destinations ;
- GET entreprise liée au jeton (`companies/me`) ;
- GET session OAuth (`oauth2_sessions/me`) ;
- POST conversion EN16931 → CII ;
- POST rapport de validation, avec le CII exact en multipart.

Il ne reçoit aucun jeton et ne possède aucune opération de dépôt. Les callbacks,
fixtures enregistrables, connecteurs externes et requêtes d’envoi ne sont pas
acceptés. Une réponse manquante échoue sans repli réseau. Aucun appel réel, même
de consultation ou de conversion, n’est activé par cette étape.

Les états de routage et d’habilitation restent **`not_verified`**. Une réponse
simulée cohérente peut seulement autoriser `qualified_for_simulation` et un
artefact `prepared_simulation`. `ready_for_emission` reste toujours `false`.
Le diagnostic global existant conserve son blocage inconditionnel de routage.

## Routage de l’acheteur historique

Le SIREN est extrait du SIRET de l’instantané validé de la facture. Aucune
information du Client actuel ne remplace l’identité ni le BT-49 figés.

`InvoiceRoutingQualification` réutilise `FrenchDirectoryEntriesAnalysis` et
`FrenchPeppolBt49Candidate`. La qualification de simulation exige :

- une réponse complète, bien formée et concordante avec ce SIREN ;
- exactement une destination active au format déjà pris en charge `0225:SIREN` ;
- une concordance exacte avec le BT-49 explicitement capturé ;
- aucune autre destination active inconnue, composée ou contradictoire.

Les lignes inactives ne sont pas sélectionnées. Une ligne invalide, étrangère,
technique `_replyto`, plusieurs candidats même identiques, un résultat partiel
ou une autre adresse active non prise en charge provoquent un refus.
La déclaration d’un suffixe ou d’un schéma EM ne donne lieu à aucune simplification
vers le SIREN et ne permet pas de substituer une autre adresse.

Le contrat local 1.39.0.beta décrit `french_directory/entries` comme une liste
complète, sans pagination. L’absence de marqueur est interprétée uniquement selon
ce contrat ; `has_more=true` reste bloquant. Tout nouveau champ d’enveloppe est
refusé par la consultation au lieu de perdre silencieusement un éventuel curseur.
Une évolution du contrat devra donc être revue explicitement.

Le résultat est lié au SIRET/SIREN historique et à l’empreinte SHA-256 de
`party_snapshot`. Il ne constitue pas une preuve réelle ni durable de routage.

## Conditions vendeur séparées

`SellerEmissionQualification` compare l’entreprise retournée au SIREN historique
du vendeur : `number_scheme=fr_siren`, pays FR, environnement explicitement
attendu, identifiant d’entreprise entier. Elle exige aussi le bon client OAuth,
`company_verification_status=verified` et une identité utilisateur `verified`.
Tout statut absent, `needs_review`, `failed` ou identité divergente reste bloquant.
La délégation à une autre entreprise n’est pas prise en charge dans ce premier parcours.

Même lorsque ces réponses simulées passent les contrôles, il manque toujours :

- leur vérification réelle via une connexion authentifiée du bon propriétaire ;
- la confirmation du droit d’émission et de l’autorisation ou du mandat nécessaire ;
- le rattachement du BT-34 déclaré au vendeur chez le fournisseur.

Le contrat de session local ne retourne pas une preuve explicite du droit `send`
ni de ce rattachement BT-34. Un mandat `in`, une connexion de réception, un BT-34
déclaré et un KYB vérifié ne sont jamais transformés en autorisation d’émission.
La réception OAuth existante n’est pas modifiée ou réutilisée implicitement.

## Conversion et validation

Le diagnostic local et le builder existants sont réutilisés sans nouveaux calculs.
Seules les factures du profil initial complet avec contexte figé sont préparées.
Le JSON exact est passé au SDK (`from=en16931`, `to=cii`, Content-Type JSON,
Accept XML). Le CII retourné n’est ni reconstruit localement ni expurgé avant archivage.

`CiiDocumentChecks` refuse les XML vides, trop volumineux, mal formés, les DTD,
entités et namespaces incorrects. Le parseur utilise `LIBXML_NONET` sans résolution
des entités. Il compare les informations essentielles au payload : numéro,
dates, type, devise, processus, mentions, parties, BT-34/49, identifiants et
adresses, lignes, quantités, prix, catégories de TVA, ventilations et totaux.
Les valeurs décimales sont comparées avec Brick Math, sans recalculer les montants.

Le rapport SDK doit concerner exactement `invoice.xml` et sa taille, annoncer
`is_valid=true`, le format CII attendu et un niveau de conformité non vide, sans
erreur ni échec, avec au moins un contrôle effectif dans ses sous-rapports.
Un HTTP 200 seul, un rapport vide ou un simple XML bien formé ne suffisent pas.
Ces vérifications ne constituent pas un validateur local XSD/Schematron complet.
Les intitulés et la couverture réelle des validateurs du fournisseur restent à
confirmer lors d’un essai ultérieur expressément autorisé ; un rapport simulé
n’est jamais présenté comme une conformité réelle.

Une seconde lecture des données détecte les changements observables pendant la
préparation, notamment les règlements. Aucun verrou SQL n’est maintenu pendant
les opérations SDK. La réservation transactionnelle et le verrouillage nécessaires
à l’envoi relèvent de l’étape 3 ; ce contrôle de relecture n’en tient pas lieu.

## Conservation privée

Le disque `invoice_cii` est local, privé, non servi, hors du répertoire public :
`storage/app/invoice-cii`. Aucun lien ni téléchargement public n’est ajouté.

Le paquet contient les octets exacts `document.xml` et `manifest.json`, sous
`v1/simulations/{user}/{invoice}/{sha256_du_manifeste}`. Le manifeste versionné
conserve les identifiants propriétaire/facture, date, empreintes du CII, payload,
données sources, instantané et contexte, ainsi que les qualifications simulées
et le rapport de validation. Il indique explicitement l’absence d’autorisation d’envoi.

Les fichiers sont écrits avec permissions privées dans un temporaire dédié,
vérifiés puis publiés ensemble. La lecture revérifie propriétaire, facture,
chemin, liens symboliques et empreintes. Les paquets existants ne sont jamais
réparés ou écrasés. Seuls les temporaires propres à l’opération sont nettoyés.
La préparation est versionnée par manifeste ; plusieurs contrôles à des dates
différentes peuvent conserver plusieurs paquets, sans politique de purge automatique.

Aucune migration supplémentaire. La migration de l’étape 1 reste nécessaire pour
le déploiement futur ; aucune migration n’est exécutée sur une base persistante.
Les historiques sans contexte complet restent bloqués sans enrichissement rétroactif.

## Fichiers de cette étape

- `app/Services/SuperPdp/OfflinePreparationGateway.php`
- `app/Services/SuperPdp/InvoiceRoutingQualification.php`
- `app/Services/SuperPdp/SellerEmissionQualification.php`
- `app/Services/SuperPdp/CiiDocumentChecks.php`
- `app/Services/SuperPdp/PreparedCiiArchive.php`
- `app/Services/SuperPdp/InvoiceCiiPreparation.php`
- `app/Services/SuperPdp/FrenchDirectoryConsultationService.php`
- `config/filesystems.php`
- `tests/Feature/InvoiceCiiPreparationTest.php`
- `tests/Unit/Services/SuperPdp/CiiDocumentChecksTest.php`
- `tests/Support/PreparedCiiFixture.php`
- Ce document.

Les quatre références CII, les calculs et builders validés, les PDF, les routes,
Stripe et la réception ne sont pas modifiés par cette étape.

## Avant un dépôt (étape 3)

Il faudra autoriser et raccorder un transport réel séparé, puis contrôler les
réponses effectives d’annuaire et d’habilitation, la couverture des rapports et
leur fraîcheur. Les artefacts de simulation ne doivent jamais être envoyés.
Le dépôt nécessitera sa propre réservation durable, la conservation du document
réel validé, une corrélation stable, la gestion des résultats incertains sans
relance aveugle, et le suivi des événements sortants. Aucun de ces envois n’est
activé ici.

## Vérification locale

- Tests ciblés de routage, session vendeur, préparation, instantanés et références
  CII : **1 066 réussis, 4 657 assertions**.
- Suite complète `php vendor/bin/pest --compact` : **2 684 réussis,
  15 239 assertions**, 102,38 secondes.
- Les **84 nouveaux cas** couvrent notamment les réponses valides, ambiguës,
  inactives, partielles et non prises en charge, les identités historiques,
  conditions vendeur insuffisantes, erreurs SDK, XML/rapports refusés, absence
  de repli réseau, données devenues caduques, isolation et intégrité des archives,
  stockage exposé et erreurs d’écriture.
- Tests SQL sur SQLite en mémoire, réponses SDK en mémoire et stockage de test.
  Les quatre références CII sont inchangées et leurs tests de non-régression passent.
- `git diff --check` : aucune erreur d’espacement, avertissements CRLF/LF seulement.
