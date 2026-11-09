# Étape 3 — orchestration sortante fermée

## État d'activation

Le parcours d’émission applicatif reste **fermé**. Les liaisons du conteneur
utilisent `ClosedEmissionPrerequisites` et `DisabledEmissionTransport`. Le diagnostic
global est inchangé. Aucune route, tâche planifiée, commande, émission ou connexion
réseau réelle n’est ajoutée ou activée.

L’orchestrateur exige actuellement `APP_ENV=testing` et un transport de mode `test`.
L’adaptateur SDK `SdkTestEmissionTransport` exige lui aussi cet environnement,
à la construction et avant chaque opération. Il accepte seulement des
`MockResponse` en mémoire, pas de fixture enregistrable ni de callback de transport.
Il ne lit aucun jeton ou paramètre de la réception. Une réponse manquante échoue
sans repli réseau. Aucun paramètre de configuration ne permet d’ouvrir l’émission.

Les références `invoice-cii/v1/simulations/...` de l’étape 2 et tout mode de document
simulé sont refusés avant réservation. Le contrat `EmissionPrerequisites` devra
ultérieurement disposer d’un lecteur d’archives **réelles, privées et validées**,
avec preuves fraîches de routage/habilitation et relecture du CII exact.
Ce lecteur n’est pas remplacé par une simple chaîne de chemin : le défaut reste
un refus systématique. Les tests injectent un faux lecteur exclusivement pour
exercer la mécanique, sans promouvoir leurs données fictives en preuves réelles.

## Réservation et tentatives

`ElectronicInvoice` est réutilisé comme registre d’une chaîne d’émission par facture.
`ElectronicInvoiceAttempt` conserve chaque tentative, son UUID, le SHA-256 du CII,
sa prise en charge et l’historique de ses transitions. Les colonnes `iopole_*`
historiques ne sont ni renommées ni réinterprétées.

Ordre de verrouillage : **utilisateur → facture → registre électronique → tentative**.
La propriété et le statut définitif sont relus sous verrou. Une contrainte unique
sur `dispatch_key` protège le registre ; une autre protège le couple registre/numéro
de tentative. Les UUID de corrélation sont également uniques.
Un historique électronique ancien non qualifié bloque la création d’une nouvelle
chaîne et nécessite un examen explicite, sans modifier ces anciennes lignes.

Le registre fige la référence du document, son empreinte, l’environnement, l’entreprise
SUPER PDP attendue et une référence locale stable. Chaque tentative possède son
propre UUID de 36 caractères, envoyé comme `external_id`. **Ce champ est une
corrélation, pas une garantie d’idempotence de SUPER PDP.**

`reserve()` est idempotent pour les clics répétés. Une nouvelle tentative n’est
créée qu’après un refus certain et une demande explicite `retryRejected=true`.
Un document, environnement ou contexte différent ne remplace jamais la réservation.
Les prérequis et les octets sont contrôlés à nouveau avant la prise en charge.

La réservation et le marquage `transport_started_at` sont commités avant l’appel
SDK, hors transaction SQL. Un jeton de prise en charge empêche deux workers de
finaliser la même tentative. Toute transaction externe englobant réserve/dépôt
est refusée, car elle empêcherait ce commit durable.

## Résultats du dépôt

Les états sont séparés des statuts métier et des paiements :

| État | Signification |
|---|---|
| `pending` | Tentative réservée ; le champ de prise en charge indique si l’appel a commencé. |
| `accepted` | Dépôt accepté et identifié, sans promesse de livraison ou d’acceptation métier. |
| `rejected` | Refus certain avant appel ou réponse 400 conforme au contrat local d’échec. |
| `unknown` | Dépôt potentiellement effectué, résultat non fiable ou non conservé. |

Une réponse 200 n’est acceptée qu’avec identifiant entier positif, entreprise
attendue, direction `out`, corrélation exacte et date de création exploitable.
Les JSON invalides ou à clés dupliquées restent incertains. L’identifiant distant
n’est lié au registre qu’après ces contrôles et avec une contrainte d’unicité
par environnement/entreprise/identifiant.

Un 400 n’est certain que s’il correspond à l’objet `http_ko` documenté, avec statut
400 concordant, message, code numérique s’il existe et aucune identité de facture.
Les autres erreurs HTTP, délais dépassés et erreurs de connexion sont conservés
comme inconnus, sans texte distant ni secret dans les erreurs enregistrées.
Une erreur de persistance après réponse conduit également à l’inconnu si la base
reste accessible. Si elle ne l’est plus, la prise en charge durable permet la
récupération ultérieure.

Aucune expiration de lease ne réautorise l’envoi. `recoverInterrupted()` classe
une prise en charge interrompue depuis cinq minutes en `unknown`. Un worker
revenant tard ne peut pas l’écraser ni relancer l’appel.

## Réconciliation positive seulement

`reconcile()` ne résout l’inconnu qu’avec un identifiant distant explicite, les
métadonnées concordantes relues et le **SHA-256 de l’original distant exact**.
Le SDK est utilisé pour les GET ; `format=original` suit le contrat versionné,
sans utiliser l’ancien endpoint `/download` du SDK absent de ce contrat.

Une absence dans une liste, une erreur de lecture, un document différent ou une
corrélation manquante ne prouvent jamais qu’aucun dépôt n’a eu lieu. Dans ces cas,
l’état reste inconnu et toute nouvelle tentative est interdite. La version actuelle
ne propose donc aucune remise en file après une réconciliation négative.
L’historique conserve l’inconnu puis la réconciliation positive ; aucun nouvel
appel POST n’est effectué pour réconcilier.

## Événements sortants

`ElectronicInvoiceStatus` conserve les événements avec une clé unique
environnement/entreprise/identifiant d’événement et une empreinte canonique du
payload connu. Un doublon identique est ignoré ; un doublon contradictoire refuse
et annule tout le lot. Les identifiants de facture étrangers sont refusés.
Les codes futurs sont conservés sans être forcés dans une machine d’états inventée.

Le code `business_status` est seulement le **dernier événement `fr:*` par identifiant**,
pas un état exclusif de toute la facture. Tous les événements API, français et PPF
restent consultables dans l’historique. Un refus métier après dépôt accepté ne
devient jamais un refus technique certain autorisant une nouvelle tentative.
`fr:211`, `fr:212` et les autres événements ne créent ni paiement ni modification
du statut/montant payé de `Invoice`.

La collecte utilise uniquement `invoice_events` filtré par identifiant sortant.
Elle conserve `has_after`, perdu par le DTO du SDK. Chaque page est validée et
commitée entièrement avant progression du curseur. Les notifications reçues
hors ordre ne font pas avancer ce curseur : elles ne peuvent donc pas faire
sauter des événements encore non collectés. Un appel traite une page, sans boucle
de synchronisation automatique ni changement du curseur de réception.

## Migration et vérification

Migration additive préparée :
`2026_11_09_000002_add_outgoing_emission_orchestration.php`.
Elle ajoute les métadonnées nullables du registre et des événements, la table
des tentatives et les contraintes d’unicité. Aucun remplissage des historiques.
Elle n’est pas appliquée à une base persistante ; les tests migrent uniquement
SQLite en mémoire.

Les tests d’orchestration utilisent une base sans transaction englobante afin de
vérifier les véritables frontières de commit. Ils évitent de lancer les `down()`
anciens sans lien avec cette étape : la base en mémoire est reconstruite et
abandonnée entre cas. Les migrations historiques ne sont pas modifiées.

Les entrelacements de workers/clics et les contraintes sont testés, mais SQLite
ne prouve pas le comportement de `SELECT ... FOR UPDATE` sous MariaDB.
Un test simultané entre processus, les deadlocks et les pannes durables de base
restent à vérifier sur une base MariaDB isolée, sans accès Alwaysdata.

## Avant le premier essai contrôlé

Il faudra autoriser explicitement l’essai, confirmer les preuves réelles de
routage et d’habilitation vendeur, disposer d’un CII réel validé conservé en privé,
et raccorder un lecteur/transport réels avec les contrôles de sécurité équivalents.
Les garde-fous de fermeture ne doivent pas être retirés par une simple assertion
de formulaire ou un booléen de configuration. Il faudra aussi valider la migration
et la concurrence sur une base isolée puis prévoir l’activation contrôlée du
diagnostic et de l’interface. Aucun de ces envois n’est effectué ici.

## Fichiers de cette étape

- `app/Models/ElectronicInvoice.php`, `ElectronicInvoiceStatus.php`, `ElectronicInvoiceAttempt.php`.
- `app/Providers/AppServiceProvider.php`.
- `app/Services/SuperPdp/Emission/EmissionDocument.php`, `EmissionPrerequisites.php`,
  `ClosedEmissionPrerequisites.php`, `EmissionTransport.php`, `DisabledEmissionTransport.php`,
  `SdkTestEmissionTransport.php`, `EmissionJson.php`, `OutgoingEmission.php`.
- `database/migrations/2026_11_09_000002_add_outgoing_emission_orchestration.php`.
- `tests/Unit/Services/SuperPdp/OutgoingEmissionTest.php`.
- `tests/Support/EmissionTestTransport.php`, `IsolatedEmissionDatabase.php`.
- Ce document.

Les services des étapes 1/2, les quatre références CII, les factures historiques,
les PDF, Stripe, les routes et la réception ne sont pas modifiés par cette étape.

## Résultats locaux

- **55 nouveaux cas** d’orchestration, avec contrôles de commits, contraintes,
  entrelacements, erreurs réseau simulées, résultats ambigus, reprise et événements.
- Tests ciblés : **828 réussis, 3 829 assertions**, 67,96 secondes.
- Suite complète `php vendor/bin/pest --compact` : **2 739 réussis,
  15 744 assertions**, 149,94 secondes.
- Code nouveau formaté avec Pint ; `git diff --check` sans erreur d’espacement
  (avertissements de conversion CRLF/LF sur des fichiers préexistants seulement).
- Aucun test de concurrence réel MariaDB ni appel réseau réel. Les seules migrations
  exécutées par cette étape appartiennent aux bases SQLite en mémoire des tests.
