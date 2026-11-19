# Routage sandbox Tricatel : limite du contrat disponible

Analyse locale du 13 novembre 2026. Aucun appel distant. Source : copie officielle
OpenAPI `resources/schemas/superpdp/1.39.0.beta/openapi.json`, récupérée le
6 novembre 2026, SDK installé et intégration Laravel existante. Cette conclusion
ne prétend pas décrire des endpoints ultérieurs ou non documentés.

Scénario autorisé : Burger Queen, entreprise locale 3, vers Tricatel,
`sandbox / 000000001`, adresse `0225:315143296_18171`. Les adresses `_replyto`
sont interdites. La confirmation existante provient de l'interface SUPER PDP,
statut OK ; `api_verified=false` et `ready_for_emission=false` restent inchangés.

| Consultation documentée | Portée et limite pour Tricatel |
| --- | --- |
| `GET /v1.beta/directory_entries` | OAuth ; liste les adresses de l'entreprise authentifiée. Avec la connexion de Burger Queen, ce n'est pas une recherche des adresses de Tricatel. Aucun filtre destinataire n'est documenté. |
| `GET /v1.beta/directory_entries/{id}` | OAuth ; exige un ID d'entrée d'annuaire connu. Le contrat ne documente ni recherche par identité/adresse ni droit de consultation interentreprises. Ne pas confondre l'ID d'entreprise 18171 avec un ID d'entrée. |
| `GET /v1.beta/french_directory/companies` | Public ; paramètre `number` défini comme SIREN. Pas de paramètre `number_scheme=sandbox`. |
| `GET /v1.beta/french_directory/entries` | Public ; même exigence SIREN. `is_active` décrit une adresse active dans cet annuaire français, pas une preuve de réception sandbox Peppol de Tricatel. |

Le schéma `directory_entry` expose notamment `company`, `directory`, `identifier`,
`is_replyto` facultatif et `status` (`pending`, `created`, `error`). Il ne décrit
pas de capacités de réception par type de document/processus. La correspondance
exacte d'une identité et d'une adresse, ou le seul statut `created`, ne suffit
donc pas à certifier la capacité demandée. L'absence de `is_replyto` ne constitue
pas non plus une preuve positive de capacité.

Aucun endpoint de consultation Peppol tierce, de découverte des capacités ou de
vérification à blanc du routage sandbox n'est déclaré dans cette version.
Les opérations de génération, conversion et validation documentaire n'attestent
pas le routage ; le POST `/invoices` constitue un dépôt et n'est pas un diagnostic
en lecture seule. Il n'est pas utilisé pour cette analyse.

## Conséquence

Aucune commande réseau de vérification fiable de Tricatel n'est préparée sur la
base de ce contrat incomplet pour le besoin. Ne pas essayer de filtre non
documenté, de deviner un ID d'entrée ou de soumettre `000000001` comme SIREN.
La qualification manuelle existante et les verrous d'émission restent fermés.

## Information nécessaire auprès de SUPER PDP

Demander l'endpoint et son contrat permettant à Burger Queen authentifiée en
sandbox de vérifier, sans dépôt ni modification :

- l'association de Tricatel `sandbox / 000000001` à `0225:315143296_18171` ;
- l'environnement du réseau/annuaire réellement consulté et l'exclusion des
  adresses techniques `_replyto` ;
- la capacité actuelle de cette destination à recevoir notre facture B2B sandbox,
  les types de documents et processus pris en charge, et la signification exacte
  des statuts retournés ;
- les droits OAuth nécessaires, la fraîcheur de l'observation et, si un ID
  d'entrée est exigé, la méthode documentée pour l'obtenir en lecture seule.

Si aucune consultation API n'est proposée, demander quelle preuve alternative est
officiellement disponible. Une réponse du support ne devient pas automatiquement
une preuve API live : son éventuelle prise en charge nécessiterait un contrat
explicite distinct, sans modification implicite des exigences de l'orchestrateur.
