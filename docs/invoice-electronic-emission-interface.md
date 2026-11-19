# Interface d'émission : consultation et confirmation fermées

La page d'une facture définitive affiche une section « Envoi électronique » avec
l'action « Envoyer électroniquement », désactivée. Un formulaire POST authentifié,
protégé par CSRF et limité à trois demandes par minute et par utilisateur, recueille
une confirmation explicite. Il renvoie uniquement un bilan local et ne peut ni
réserver une émission ni effectuer un dépôt, même si toutes les configurations et
preuves sont valides. Il n'existe aucun interrupteur ouvrant le transport web dans
cette livraison.

Route : `POST /invoices/{invoice}/electronic-emission`, nom
`invoices.request-electronic-emission`. Retour HTTP 303 vers la facture, ancre
`#electronic-emission`. La confirmation JavaScript et la case à cocher ne remplacent
jamais le contrôle serveur `confirm_emission`. Le corps HTTP ne fournit aucune
autorisation, référence d'archive, identité fournisseur ou tentative exploitable.

## Réutilisation des composants

`InvoiceEmissionReadiness` vérifie le propriétaire et la facture définitive relue,
ainsi que tout rattachement explicite à une entreprise. Une entreprise absente reste
un blocage pour l'envoi ; la lecture d'une facture PDF classique reste disponible.

- `CiiPreparationRegistry::consult()` retrouve et vérifie le CII privé lié aux
  empreintes et au contexte courants. Seul un résultat terminé `prepared_validated`
  avec validation `passed` peut passer ce contrôle. Aucune conversion n'est lancée.
- `CiiPreparationGuards::connection()` contrôle le jeton sans renouvellement.
  Le client épinglé ici est celui de `superpdp_emission`, indépendamment de
  l'autorisation de préparation CII. Le contexte UI reste strictement sandbox.
- `SandboxEmissionAuthorization` contrôle l'autorisation d'émission distincte et
  sa fenêtre maximale de quinze minutes.
- `SandboxEmissionPrerequisites::authorize()` est réutilisé en lecture locale pour
  contrôler le document exact, les instantanés, les empreintes, les preuves réelles
  vendeur/session/destination et leur fraîcheur. Ses erreurs sont présentées en
  français sans corps distant, jeton ni chemin privé. La qualification `fr_siren`
  et les identifiants légaux ne sont pas modifiés.
- `OutgoingEmission::consult()` ajoute une lecture du registre existant, sans
  utiliser un second registre. Les accès sont bornés à la facture du propriétaire,
  à l'entreprise courante et à sa connexion fournisseur. Un historique ambigu ou
  d'un autre contexte est présenté comme à examiner, sans exposer son contenu.

Les états présentés sont : aucune émission, tentative réservée, dépôt commencé,
dépôt accepté, refus, résultat incertain ou historique indisponible/à examiner.
Les simulations sont explicitement identifiées. Un dépôt accepté n'est jamais
présenté comme une preuve de livraison au destinataire. Les erreurs affichées sont
des messages locaux autorisés, jamais `last_error`, un payload ou un jeton de claim.

## Transport et doublons

Les bindings `ClosedEmissionPrerequisites` et `DisabledEmissionTransport` restent
inchangés. Le nouveau contrôleur ne résout pas `SandboxEmissionFactory`, n'appelle
ni `OutgoingEmission::reserve()` ni `dispatch()`, et ne lance aucune récupération,
réconciliation ou synchronisation. Le GET ne transforme pas un pending interrompu
en nouvelle tentative et ne met pas à jour le registre.

Avant un premier dépôt web, une étape distincte devra raccorder explicitement
l'orchestration existante, avec :

1. Une facture réellement complète, un CII validé et intègre issu de ses seules
   données figées, OAuth qualifié, preuves réelles et récentes de vendeur, session
   et destination ; pas le document documentaire `first-reference`.
2. Une autorisation serveur d'émission limitée à l'utilisateur, à la facture, à
   l'archive, au fournisseur, au client OAuth, à l'environnement sandbox et au
   SHA-256 exact. La préparation CII n'accorde pas cette autorisation.
3. Une réservation via `OutgoingEmission::reserve()` et une autorisation explicite
   supplémentaire de son UUID `attempt_reference`, exigée par le transport existant.
   Cette autorisation ne doit jamais être construite depuis le formulaire.
4. Une confirmation utilisateur pour ce document et cette tentative, puis les
   recontrôles existants avant `dispatch()`. Une tentative pending/accepted/unknown
   ne doit jamais devenir un nouveau dépôt ; aucune reprise automatique du refus.
5. Une activation sandbox revue du transport HTTP, en conservant la réservation
   durable commitée avant réseau, les verrous et les protections d'OutgoingEmission.

Il faudra également vérifier le schéma du registre et les droits du stockage sur
l'environnement cible, sans appliquer de migration réelle durant cette étape.
La production reste fermée. Aucun paramètre de cette interface ne l'active.

## Tests isolés

`InvoiceElectronicEmissionInterfaceTest` utilise uniquement SQLite `:memory:`,
des archives temporaires et des preuves synthétiques. Les transports et la factory
réelle échouent s'ils sont invoqués. Les tests couvrent la fermeture même lorsque
les autres prérequis passent, l'intégrité des archives, OAuth et preuves expirés,
autorisations hors périmètre, CSRF, confirmation, propriétaires, brouillons, statuts,
simulations, doublons et interruptions, sans écriture pendant les lectures/POST fermés.

Les suites existantes `OutgoingEmissionTest`, `SandboxEmissionTransportTest` et
`ElectronicEmissionEndToEndTest` restent la référence pour les réservations, les
claims et les dépôts simulés. Aucun appel réel n'est exécuté ici.
