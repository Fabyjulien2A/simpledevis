# Consultation isolée de l’annuaire français SUPER PDP

`FrenchDirectoryConsultationService` utilise une `FrenchDirectoryResource` du SDK
installé, injectée explicitement. Le POST dédié depuis la fiche client peut
désormais l’appeler via `FrenchDirectoryConsultationFactory` ; aucun chargement
normal de page ni parcours de réception/émission ne le déclenche. Le service
ne lit aucun modèle, ne renouvelle aucun jeton, ne persiste rien et ne sélectionne
aucune destination. Voir [la consultation client](superpdp-client-directory-consultation.md).

## Opérations

Le contrat de référence est l’OpenAPI locale `1.39.0.beta` :

- `searchCompanies($siren)` utilise `GET /v1.beta/french_directory/companies`
  avec `number` et la limite SDK par défaut de 100.
- `listEntries($siren)` utilise `GET /v1.beta/french_directory/entries`
  avec `number`.

Le SIREN doit être une chaîne de neuf chiffres ASCII exactement. Les espaces,
entiers, SIRET et autres formes sont refusés avant toute consultation. Il n’y a
ni normalisation, ni contrôle de clé ou d’existence. Les zéros initiaux sont gardés.

Les deux GET portent `security: []` dans l’OpenAPI locale. Le service n’utilise
pas la connexion OAuth de réception. Toute intégration future devra fournir une
ressource et une configuration de transport dédiées, notamment pour les délais
et la politique de redirection, sans modifier le transport de réception.
La consultation client fournit désormais cette composition dédiée.

## Résultats et contrôles

Les entreprises conservent exactement les champs documentés `number`,
`formal_name`, `address`, `postcode`, `city`, `country`. Les six chaînes sont
obligatoires ; les textes ne sont pas reformulés. Le numéro doit correspondre
au SIREN recherché. Une réponse incohérente est refusée dans son intégralité.

Les lignes conservent `company`, `identifier` brut et `is_active` booléen,
dans l’ordre retourné, y compris les lignes inactives. Un identifiant blanc
est refusé, mais aucun format d’adresse n’est déduit ou imposé. Aucun couple
EN16931 `scheme` / `value`, choix de ligne ou état de routage n’est produit.
Les propriétés supplémentaires non décrites dans ces modèles ne sont pas exposées.

Le service lit le corps JSON complet, sans `dto()` : la conversion SDK en
`EntityCollection` perd l’enveloppe et donc `has_more`.

- Recherche : `['data' => [...], 'has_more' => bool]`, les deux clés étant
  obligatoires dans le contrat local.
- Lignes : `['data' => [...]]`. Si `has_more` est présent, il est conservé et
  doit être booléen ; il n’est pas inventé s’il est absent. Ce champ n’est pas
  déclaré sur cette réponse dans la version locale du contrat.

`has_more=true` signifie une réponse tronquée, pas un curseur de pagination.
L’OpenAPI demande d’affiner les filtres de recherche. L’adaptateur ne prétend
pas avoir une liste exhaustive et ne lance aucun appel supplémentaire.

Une liste vide valide reste un succès. Les erreurs ne deviennent pas des
listes vides : `FrenchDirectoryConsultationException` expose `reason` et
`httpStatus` (nullable), sans corps distant ni exception contenant une requête :

- `authentication` : HTTP 401 ou 403 ;
- `api_error` : autre statut différent du succès documenté 200 ;
- `unavailable` : erreur de transport du SDK ;
- `malformed_response` : JSON, enveloppe, types ou identité incohérents.

## Limites conservées

`is_active` est une observation de l’annuaire, pas une preuve suffisante de
routage. `routing.verification_unavailable` reste inchangé dans le diagnostic.
La correspondance vers BT-34/BT-49, la sélection de ligne, la fraîcheur de la
vérification et la conservation d’un instantané restent à définir et confirmer.
Aucune émission, création d’entrée d’annuaire ou activation automatique n’est ajoutée.

Les tests unitaires emploient le vrai SDK avec `MockClient`, bloquent les
requêtes non simulées et tout SQL, et contrôlent que seuls les deux GET sont
utilisés. Les scénarios incluent les listes vides et multiples, les lignes
inactives, les SIREN invalides, les erreurs, les réponses mal formées et `has_more`.

Vérification du 8 novembre 2026 : 112 cas unitaires dédiés ; 186 tests ciblés
avec le diagnostic existant (1 095 assertions) ; suite complète de 1 916 tests
PHP réussis (10 490 assertions). Pint appliqué/vérifié sur les trois nouveaux
fichiers PHP, `git diff --check` réussi et empreintes des quatre références
CII vérifiées. Le lanceur temporaire bloque les transports réseau, les pilotes
SQL distants et les bases SQLite persistantes ; il est retiré après les tests.
Aucune migration, consultation réelle d’annuaire ou émission n’a été effectuée.
