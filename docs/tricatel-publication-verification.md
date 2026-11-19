# Publication de l'adresse Tricatel dans le sandbox

Cette vérification utilise exclusivement la connexion OAuth existante de
Tricatel : utilisateur local 4, entreprise locale 4, identité SUPER PDP
`sandbox / 000000001`, adresse autorisée `0225:315143296_18171`.
L'identifiant distant d'entreprise doit correspondre à celui enregistré dans
sa connexion ; il n'est pas déduit du suffixe de l'adresse.

## Une vérification réelle, uniquement après autorisation explicite

Pour diagnostiquer d'abord les prérequis locaux, sans appel API, renouvellement,
ni écriture de rapport :

```powershell
php artisan superpdp:verify-tricatel-publication --env=sandbox --diagnose
```

Ce mode lit exclusivement les données sandbox dans une transaction en lecture
seule. Il distingue notamment `access_token_expired` (déjà expiré),
`access_token_expiring_soon` (marge de sécurité de cinq minutes), l'absence de
date d'expiration, une connexion inactive, un contexte OAuth incohérent et un
jeton illisible ou absent. Il ne fournit que des codes, des indicateurs de
concordance et des dates ; jamais de jeton, secret ou message d'exception brut.
Les options `--diagnose` et `--execute` sont mutuellement exclusives.

Un résultat `prerequisites_passed` ne confirme ni la publication ni le routage.
Un jeton expiré ne peut pas être rendu valide en modifiant sa date stockée :
une obtention de jeton valide par le parcours OAuth est une action distincte,
qui exige son autorisation et n'est jamais déclenchée par ce diagnostic.

Depuis le dossier du projet, après autorisation de l'utilisateur :

```powershell
php artisan superpdp:verify-tricatel-publication --env=sandbox --execute
```

**Cette commande n'a pas été exécutée pendant le développement.**
Sans `--execute` ni `--diagnose`, la commande ne lit ni la base ni l'API et ne crée aucun rapport.
Aucun drapeau permanent ni modification de `.env.sandbox` n'est nécessaire.

L'exécution autorisée lit les trois modèles dans une transaction SQL explicitement
en lecture seule sur la base sandbox isolée, puis annule cette transaction avant
les appels HTTP. L'authentification de l'utilisateur 4 reste en mémoire, sans
événement de connexion ni écriture de session. La connexion de Burger Queen
n'est ni sélectionnée ni modifiée. Les modèles existants et les curseurs de
réception restent inchangés.

Le client SDK dédié peut faire **au plus deux GET**, chacun une seule fois :

1. `/v1.beta/companies/me` avec le jeton de Tricatel ; identité, propriétaire,
   identifiant distant enregistré, nom et environnement doivent correspondre.
2. `/v1.beta/directory_entries`, uniquement après identité conforme ; rechercher
   l'adresse exacte dans l'annuaire `peppol`, avec `status=created` et
   `is_replyto=false`. Les adresses techniques `_replyto`, les doublons,
   une identité étrangère ou une enveloppe ambiguë ne confirment rien.

Les redirections et retries sont désactivés. Aucun appel `/oauth2/token`,
renouvellement ou rotation n'est permis. Si le jeton est expiré ou arrive à
expiration dans les cinq minutes, la vérification est indisponible et s'arrête.
La gestion OAuth ordinaire reste une action séparée ; cette commande ne la
déclenche pas.

## Blocage `replyto_classification_missing`

Dans le contrat local 1.39.0.beta, `directory_entry.is_replyto` est un **booléen
facultatif**, sans valeur `default`. `true` désigne une adresse technique de
réponse à exclure ; `false` explicite permet de poursuivre la vérification de
l'adresse attendue. Un champ absent, `null`, une chaîne `"false"` ou un entier
`0` ne constituent pas un booléen `false`. Le schéma ne documente aucun autre
champ qui remplacerait cette classification.

Le traitement lit le JSON brut via `EmissionJson`, sans DTO : le SDK n'efface
donc pas ce champ dans ce parcours. Les suffixes `_replyto` sont une exclusion
supplémentaire, jamais une règle permettant de certifier les autres adresses.
`status=created`, une identité correcte et l'absence de ce suffixe ne remplacent
pas la classification manquante.

Les nouveaux rapports bloqués conservent `classification_diagnostic` : ID réel
de l'entrée, adresse exacte, statut d'enregistrement, présence du champ, type de la valeur décodée et état
`undetermined`. Aucune valeur distante libre n'est ajoutée au diagnostic.
Les anciens rapports restent inchangés et ne permettent pas de distinguer
rétroactivement une omission d'un `null` ou d'un type incorrect.

Prochaine vérification, uniquement après autorisation d'une lecture réelle :
examiner `is_replyto` dans la réponse brute de l'entrée exacte. Le contrat
documente également `GET /v1.beta/directory_entries/{id}`, mais ce dernier
utilise le même schéma et ne garantit pas davantage la présence du champ.
L'ID à utiliser est celui de l'entrée (`entry.id`), jamais l'ID d'entreprise
18171 déduit du suffixe. Ce GET de détail n'est pas ajouté automatiquement au
parcours à deux lectures.

Si la liste et le détail ne fournissent pas de booléen explicite, obtenir de
SUPER PDP la classification officielle de cette entrée ou une précision
contractuelle sur le sens de l'omission. Sans cette preuve, la publication
reste non confirmée et aucune règle de production n'est assouplie.

## Résultat et preuve privée

« **Adresse publiée confirmée** » exige deux observations SDK réelles, cohérentes.
Une réponse simulée ou issue du cache ne peut jamais confirmer la publication.
« **Routage depuis Burger Queen vérifié** » reste toujours faux : ce contrôle
constate uniquement la publication chez Tricatel et ne prouve pas une
transmission depuis Burger Queen, ni une capacité documentaire particulière.

Les rapports JSON immuables et vérifiés par empreinte sont enregistrés sur le
stockage privé existant `invoice_cii`, dans
`company-publication-checks/v1/4/4/`. Ils sont séparés des rapports de facture.
Ils contiennent les dates, la provenance, les endpoints/méthodes/statuts HTTP,
les empreintes des réponses et du contexte local, l'identité observée et
l'entrée retenue. Aucun jeton, secret, en-tête Authorization ou corps HTTP
intégral n'est conservé. La référence privée est affichée par la commande.

La méthode `TricatelPublicationVerification::read()` relit une référence sans
API, vérifie son intégrité et son contexte et expire les observations après
quinze minutes, réglables par `publication_validity_minutes`. Ce délai est un
choix local, pas une exigence officielle. Les rapports ne sont pas raccordés
à la qualification de production ni au transport d'émission.
