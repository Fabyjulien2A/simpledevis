# Consultation temporaire depuis une fiche client

Le bouton « Consulter l'annuaire » apparaît sur `clients.show` pour les clients
professionnels français. Il soumet un formulaire POST protégé par CSRF vers
`/clients/{client}/annuaire` (`clients.directory`). Les GET de fiche, édition
et liste clients ne déclenchent aucune consultation.

`ClientDirectoryController` vérifie la propriété (403), puis le type
`professional`, le pays `FR` et le SIRET enregistré de quatorze chiffres ASCII
(422 en cas de donnée locale incompatible). Les paramètres SIREN/SIRET ou
destination envoyés dans la requête ne sont pas utilisés. Le SIREN est extrait
des neuf premiers caractères du SIRET, sans conversion numérique.

Le limiteur `client-directory` autorise cinq tentatives par minute et par
utilisateur, tous clients confondus. Les tentatives refusées localement peuvent
également être comptées. Une sixième tentative reçoit 429 avec `Retry-After`.
Seuls les compteurs de limitation de fréquence utilisent le cache ; aucun
résultat d’annuaire n’y est conservé.

## Transport séparé de la réception

`FrenchDirectoryConsultationFactory` construit un connecteur SDK neuf, sans
jeton ni identifiant OAuth. Aucun appel à `SuperPdpOAuthService`, aucune lecture
ou mise à jour de `SuperPdpConnection` n’est nécessaire. Une connexion de réception
absente, expirée ou déconnectée ne bloque pas les GET publics d’annuaire.

La configuration dédiée est `services.superpdp.directory_base_url`, variable
`SUPERPDP_DIRECTORY_BASE_URL`, par défaut `https://api.superpdp.tech`.
Elle doit être une origine HTTPS sans chemin, identifiants, query ou fragment.
Le SDK ajoute `/v1.beta` : une configuration qui contient déjà ce chemin est
refusée avant tout appel. L’ancienne `services.superpdp.api_url` de réception
reste indépendante et inchangée.

Le transport impose un délai total de quinze secondes, un délai de connexion
de cinq secondes, aucune redirection et aucune relance automatique. Un clic
valide déclenche uniquement le GET documenté des lignes par SIREN, puis l’analyse
PHP pure des données retournées. Aucun GET supplémentaire de recherche de société
ni interrogation de session OAuth n’est effectué.

## Résultats éphémères

Le contrôleur rend directement `clients.show` après le POST, avec le partiel
`clients.partials.directory-results`. Les résultats restent dans cette réponse
HTTP (`Cache-Control: private, no-store`) : aucune session, flash, cache,
écriture de modèle ou facture ne les conserve. Le GET suivant de la fiche
ne les affiche plus. Un rafraîchissement du POST peut proposer une nouvelle
soumission dans le navigateur ; cette nouvelle tentative reste limitée en fréquence.

Les identifiants bruts sont affichés échappés dans l’ordre reçu, sans suppression
des doublons. L’activité déclarée est distincte de l’analyse : actif compatible,
inactif, format non pris en charge, données invalides/incohérentes. Une ligne
inactive conserve aussi l’appréciation de son identifiant. Aucun couple candidat
EN16931 n’est présenté comme une destination sélectionnée, aucun contrôle de
sélection ou d’enregistrement n’est proposé.

Un avertissement rappelle qu’une ligne active ne garantit pas la réception et
qu’aucune destination n’est sélectionnée ou enregistrée. `has_more=true`
signale une réponse incomplète ; aucun curseur ou pagination distante n’est
inventé. Une liste vide ne signifie pas une destination inexistante.

Les erreurs de consultation utilisent les messages locaux expurgés de
`FrenchDirectoryConsultationException` : refus d’authentification/autorisation,
erreur API ou réponse mal formée (502), transport inaccessible (503). Un défaut
de configuration reçoit un message local 503. Aucun refus distant ne déclenche
de connexion ou renouvellement OAuth. Les corps d’erreur distants ne sont pas
affichés. Les contrôles de propriété restent les erreurs 403 habituelles.

La réception, les factures, le builder EN16931, le diagnostic, les PDF, les
calculs et les quatre références CII restent inchangés.
`routing.verification_unavailable` est conservé. La consultation ne sélectionne
ni ne certifie une destination et n’autorise aucune émission.

## Vérifications

Les tests HTTP utilisent le vrai SDK avec réponses Saloon simulées, refusent
les requêtes non simulées et les appels HTTP Laravel imprévus. Les tests CSRF
lèvent uniquement le raccourci Laravel des tests pour exercer réellement les
cas sans jeton, jeton erroné et jeton valide. Les tests vérifient la limitation
par utilisateur, les validations avant appel, le transport sans authentification,
l’ordre et l’échappement des lignes, la pagination, les erreurs, l’absence
d’écriture métier et l’absence de résultats en session/cache.

Résultats : 52 nouveaux tests HTTP et unitaires ; 403 tests ciblés réussis
(2 095 assertions) ; suite PHP complète réussie, 2 133 tests et 11 490 assertions.
Pint appliqué puis vérifié sur les sept fichiers PHP concernés et
`git diff --check` réussi. Les quatre empreintes CII sont intactes. Les tests
utilisent exclusivement SQLite en mémoire et un lanceur temporaire bloquant
les transports réseau et les autres bases ; il est retiré après vérification.
Aucune consultation réelle, émission ou migration distante n’a été effectuée.
