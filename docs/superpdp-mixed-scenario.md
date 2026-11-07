# Quatrième scénario fictif : biens et services, cadre M1

`SandboxMixedInvoiceFixture` prépare `SANDBOX-FICTIF-MIXTE-0004`, uniquement en
mémoire. Le cadre est fourni explicitement par `frenchContext()` :
`process_control.business_process_type = M1` (BT-23). Le builder existant accepte
déjà B1, S1 et M1 via `FrenchInvoiceContext`. BT-24 reste
`urn:cen.eu:en16931:2017`. Aucun cadre n'est déduit des descriptions de lignes.

Les mentions PMT, PMD et AAB sont réutilisées du contexte fictif existant.
La nouvelle fixture possède sa propre instance de modèle, son numéro et ses
lignes ; elle conserve les contrôles refusant une autre facture ou un modèle
persisté. Les trois fixtures précédentes restent inchangées.

| Ligne fictive | Quantité | Prix et montant HT | Catégorie | Taux |
|---|---|---|---|---|
| Fourniture d'un robinet | 1.00 | 100.00 EUR | S | 20.00 % |
| Prestation d'installation | 1.00 | 150.00 EUR | S | 20.00 % |

Le moteur fiscal et le préparateur existants produisent une seule ventilation
S/20.00 : base 250.00, TVA 50.00 EUR. Total HT 250.00, TTC et dû 300.00 EUR.
Tous les montants sont des chaînes décimales, sans float. Aucun règlement,
avoir, acompte, remise, frais ou motif d'exonération n'est ajouté.

Les identités, adresses et numéros de TVA à zéro sont ceux de la fiction de
base, avec des noms signalant l'essai mixte et des communications
`example.invalid`. Ils ne représentent aucune entreprise réelle.

## Essai manuel

Simulation locale, sans réseau (environnement local/testing) :

```sh
php artisan superpdp:sandbox-convert --scenario=mixed
```

La simulation reste le comportement par défaut même si la configuration de
conversion est activée. Un futur essai distant nécessitera une autorisation
distincte, le drapeau `SUPERPDP_SANDBOX_CONVERSION_ENABLED=true` et l'option
`--execute`. Aucune conversion distante M1 n'a été lancée et aucun XML M1
synthétique n'est fourni comme preuve de conversion.

## Vérifications locales

Les 28 nouveaux cas vérifient les deux lignes, les identifiants et unités,
prix et montants exacts, la ventilation unique, les totaux, BT-23/BT-24,
les mentions françaises, l'absence de mutation des données et l'isolation
des trois scénarios précédents. Les cas négatifs couvrent la persistance,
les lignes manquantes, les taux/catégories/motifs incompatibles, les montants
incohérents, le contexte absent ou invalide et les mentions absentes ou vides.

Le payload passe le validateur local issu de l'OpenAPI SUPER PDP 1.39.0.beta ;
les mutations de types, décimales et propriétés obligatoires sont refusées.
Le schéma ne contraint BT-23 qu'au type chaîne : la sélection B1/S1/M1 relève
du contexte français existant. Cette vérification est structurelle et ne
constitue pas une certification EN16931 ou réglementaire.

Les tests M1 interdisent tout SQL et vérifient qu'aucune requête HTTP n'est
envoyée. Les suites utilisent SQLite en mémoire, DB_URL vide, APP_ENV=testing
et conversion désactivée. `allow_url_fopen=0` et les fonctions
`curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,socket_connect`
sont désactivées. Un bootstrap temporaire remplace les deux fonctions cURL par
des fonctions levant une exception, permettant les transports HTTP simulés des
tests existants. Ce bootstrap est retiré après les vérifications.

Les factures existantes, PDF, réception et migrations ne sont pas modifiés par
cette étape. Aucun accès MariaDB Alwaysdata, appel réseau ou émission.

## Références CII protégées

Empreintes SHA-256 inchangées avant et après les tests :

- Standard : `3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`.
- Franchise : `c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735`.
- Multi-taux : `beb602e30ce56d576783e2ac6116a677bf625930baf13590c679408cbb0068d3`.

## Résultats

- Tests unitaires SUPER PDP : **853 réussis, 3 677 assertions** (18,98 s),
  dont les 28 nouveaux cas M1.
- Suite PHP complète : **1 331 réussis, 6 378 assertions** (59,08 s).
- Pint `--test` : réussi sur les quatre fichiers PHP concernés (fixture,
  sélection du probe, aide de commande et tests M1).
- Trois XML et empreintes de référence préservés.

Le scénario M1 est prêt pour un essai manuel. Son acceptation par la conversion
distante et le contenu du futur CII restent à vérifier lors d'une étape autorisée.
