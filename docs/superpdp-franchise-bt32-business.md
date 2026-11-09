# BT-32 dans le parcours métier de franchise

## Contrat local vérifié avant modification

La copie OpenAPI SUPER PDP 1.39.0.beta définit
`seller.tax_registration_identifier` comme une chaîne correspondant à BT-32.
Le builder existant transmet ce champ indépendamment de BT-30 et de BT-31.

`SandboxFranchiseInvoiceFixture` et la référence CII franchise décrite dans
`superpdp-franchise-cii-regression.md` portent le même SIREN en BT-30 et BT-32,
sans numéro de TVA vendeur BT-31. Le qualifiant CII `FC` n’est pas un préfixe à
ajouter dans la valeur JSON. Aucun nouveau contrôle distant n’a été effectué.

## Mapping métier

La lecture fraîche `InvoiceExportDataBuilder::buildElectronic()` prépare BT-32
uniquement si le profil enregistré déclare `franchise`, si `vat_number` et
`tva_number` sont tous deux absents ou blancs après retrait des espaces
périphériques, et si le SIRET contient exactement quatorze chiffres après le
même retrait des espaces périphériques que dans le builder.

`FrenchSellerIdentifiers::sirenFromSiret()` partage le contrôle de forme et
l’extraction avec le builder et le diagnostic. Les neuf premiers chiffres sont
conservés comme chaîne, y compris les zéros initiaux. Aucun contrôle de clé ou
d’existence n’est revendiqué. Un SIRET absent ou mal formé ne produit pas BT-32 :
le builder refuse la préparation et le diagnostic garde les blocages adaptés.

L’export historique `build()` reste inchangé. Le mapping existant conserve le
SIRET en BT-29, schéma `0009`, et le SIREN en BT-30, schéma `0002`. BT-32 contient
le même SIREN, sans `FR`. Aucun BT-31 n’est généré en l’absence de numéro de TVA.
Si une seule des deux colonnes contient encore un numéro, l’automatisme BT-32
n’est pas appliqué : cette divergence historique ne prouve pas son absence.

Le diagnostic utilise en priorité l’identifiant préparé, le contrôle contre le
SIRET, et ne le signale plus comme manquant. Un contexte BT-32 contradictoire
reste bloqué par `seller.tax_registration_identifier.invalid` sans remplacement
de la donnée préparée. Les conflits de régime restent bloqués par
`tax.regime.context_conflict`. Les catégories E, taux nuls et justifications de
franchise conservent leurs contrôles existants ; le mapping ne répare pas les lignes.

Aucun champ de formulaire, migration, écriture métier, numéro fiscal inventé,
calcul, PDF, appel réseau ou émission n’est ajouté. La migration distante
`vat_regime` n’est pas exécutée. Les quatre XML CII restent inchangés.
La conversion fictive ne constitue pas une certification réglementaire complète.

## Vérifications du 7 novembre 2026

- 28 nouveaux tests métier SQLite, positifs et négatifs.
- Tests ciblés : 168 réussis, 878 assertions.
- Suite PHP complète : 1 631 réussis, 8 177 assertions (53,79 s).
- Pint `--test` réussi sur les sept fichiers PHP concernés ; `git diff --check` réussi.
- Empreintes des quatre XML CII, du calculateur et du modèle PDF inchangées.

Les suites sont lancées directement avec Pest sous SQLite en mémoire et DB_URL
vide, avec configuration en cache écartée. Le bootstrap temporaire bloque les
résolveurs SQL non SQLite ; l’ouverture URL et les fonctions natives de transport
réseau sont désactivées. Les fonctions cURL sont remplacées par des exceptions
pour permettre les réponses HTTP simulées des tests existants. Ce bootstrap
est supprimé après vérification. Aucun accès MariaDB Alwaysdata n’a lieu.
