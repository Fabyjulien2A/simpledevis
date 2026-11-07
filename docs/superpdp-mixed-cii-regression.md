# Quatrième référence CII : facture mixte M1

La conversion HTTP 200 a été rapportée par l'utilisateur. La référence reçue
est conservée sans réécriture dans
`tests/Feature/SuperPdp/superpdp_mixte_m1_cii_reponse_2026-11-07.xml`.
Son SHA-256 réel, calculé avant les modifications, est :

`ee67303fefc843b00febce015e149331fea0b22da24524cc5eebab4b0457917e`

`CapturedMixedCii` exige cette empreinte à chaque chargement et refuse un
fichier absent ou altéré. Aucun original n'est enregistré par les tests.

## Contrôles de régression

`MixedCiiChecks` est un support de tests indépendant des trois contrôles
précédents. Il réutilise le parseur `SandboxCiiChecks` : URI de namespaces
fixes, LIBXML_NONET, résolution externe désactivée et refus des DTD/entités.

- Structure : numéro `SANDBOX-FICTIF-MIXTE-0004`, type 380, BT-24 EN16931,
  devise EUR, deux lignes identifiées 1 et 2 et une seule ventilation TVA.
  Les descriptions distinguent la fourniture du robinet et l'installation.
  Catégorie S et type VAT aux trois emplacements fiscaux, sans exonération.
- Contexte français : M1 explicite, mentions PMT/PMD/AAB exactes.
- Montants : calculs décimaux exacts Brick Math, sans float. Quantité et base
  de prix 1 pour chaque ligne, prix et montants HT respectivement 100 et 150.
  Taux de 20 % sur les deux lignes et la ventilation. Base 250, TVA 50,
  HT 250, TTC et dû 300 EUR. Les identités arithmétiques sont vérifiées et
  les ajustements facultatifs doivent être nuls.

La date d'émission contenue dans le CII est le **6 novembre 2026**, comme dans
la fixture. Le 7 novembre du nom de fichier désigne la référence de réponse ;
aucune correction de date n'est appliquée au XML.

Les 113 nouveaux cas incluent les trois couches positives, la vérification
conjointe des quatre références, les suppressions, duplications et déplacements
de nœuds, les erreurs de numéro/cadre/descriptions, de catégorie/taux/devise,
de quantité/base de prix, de ventilation et de totaux. Des copies vérifient
aussi le refus des virgules, exposants, montants négatifs, écarts invisibles
en float, exonérations et ajustements non nuls. Une redistribution 110/140
conservant le HT global est refusée. Les échelles décimales équivalentes et
le changement d'ordre des lignes identifiées restent acceptés.

Toutes les mutations portent sur des DOM ou chaînes en mémoire. Après chaque
cas, les empreintes des quatre fichiers sur disque sont contrôlées.

## Intégrité et limites

Les quatre empreintes de référence sont :

- Standard : `3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`.
- Franchise : `c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735`.
- Multi-taux : `beb602e30ce56d576783e2ac6116a677bf625930baf13590c679408cbb0068d3`.
- Mixte M1 : `ee67303fefc843b00febce015e149331fea0b22da24524cc5eebab4b0457917e`.

Ces contrôles ciblés ne sont pas une validation complète XSD, Schematron,
EN16931 ou réglementaire, et ne prouvent pas une émission ou un routage.
L'empreinte protège les octets reçus ; elle ne certifie pas leur provenance.

Les tests utilisent APP_ENV=testing, SQLite en mémoire, DB_URL vide et la
conversion désactivée. `allow_url_fopen=0` et les fonctions
`curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,socket_connect`
sont désactivées. Un bootstrap temporaire remplace les deux fonctions cURL par
des exceptions pour permettre les transports HTTP simulés des tests existants.
Il est retiré après vérification. Aucun appel réseau, émission ou accès MariaDB
Alwaysdata. Aucun changement du code applicatif.

## Résultats locaux

- Nouveaux tests M1 : **113 réussis, 672 assertions** (0,52 s).
- Tests unitaires SUPER PDP : **966 réussis, 4 349 assertions** (16,75 s).
- Suite PHP complète : **1 444 réussis, 7 050 assertions** (47,23 s).
- Pint `--test` réussi sur les trois nouveaux fichiers PHP.
- Quatre XML et empreintes identiques avant et après les suites.

Seuls les trois nouveaux fichiers de support/tests et ce bilan documentaire
sont ajoutés durablement pour cette étape.
