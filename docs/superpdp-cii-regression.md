# Consolidation locale du CII SUPER PDP

## État de la preuve

Le 6 novembre 2026, l'utilisateur rapporte que le deuxième essai de conversion
de `SANDBOX-FICTIF-0001` a retourné **HTTP 200** et un document CII, pour
100,00 EUR HT + 20,00 EUR TVA = 120,00 EUR TTC. Il rapporte aussi la disparition
des cinq erreurs françaises précédentes.

Cette observation est une preuve de conversion acceptée **pour cet essai et
cette fixture uniquement**, pas une certification générale. Aucun nouvel appel
réseau n'a été effectué pendant cette consolidation.

La réponse XML réelle a été fournie par l'utilisateur après récupération de
la sortie de ce deuxième essai. Elle a été trouvée à cet emplacement réel :
`tests/Feature/SuperPdp/superpdp_conversion_cii_fictif_2026-11-06.xml`
(le chemin annoncé sous `tests/Fixtures` n'existait pas).
Le fichier reste à son emplacement, sans modification, régénération ni
normalisation de ses octets. SHA-256 de la réponse examinée :

`3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`

`CapturedSandboxCii` lit directement ce fichier et refuse toute empreinte
différente. Une évolution du fichier nécessite un nouvel examen explicite du
contenu et de sa provenance, y compris pour un changement de fins de ligne.
La provenance et HTTP 200 reposent sur la déclaration de l'utilisateur ; aucun
en-tête HTTP brut ni rapport de validation réglementaire n'a été fourni.

## Contrôles réalisés

`tests/Support/SandboxCiiChecks.php` est un outil de **test uniquement**, sans
branchement dans les parcours de conversion, réception ou émission.

Les tests `SandboxCiiChecksTest.php` appliquent séparément les trois couches au
CII réel, dont les éléments utilisent des namespaces par défaut, sans préfixes.
Les mutations négatives sont exécutées sur des copies en mémoire de ce CII et
du document manuscrit `SyntheticSandboxCii`, qui reste explicitement synthétique.
La fixture sur disque n'est jamais réécrite par ces tests.

Trois couches distinctes sont exposées :

1. **Structure CII locale** : DOM avec `LIBXML_NONET`, DTD et déclarations
   d'entités interdites, résolution externe et substitution désactivées ; XPath
   absolus avec URI CII 100/ram/udt fixes, sans réenregistrement automatique des
   namespaces du document. Numéro, type 380, date et format 102, profil,
   identifiants et schémas vendeur, ligne, unité, devise et catégorie TVA sont
   contrôlés à leurs emplacements attendus, avec cardinalité unitaire.
2. **Contexte français de cette fiction** : S1 et notes PMT/PMD/AAB exactes,
   chacune attachée à son code dans `ExchangedDocument/IncludedNote`.
   Ces vérifications ciblées ne couvrent pas toutes les règles françaises et
   ne constituent pas une appréciation juridique des textes ou identifiants.
3. **Montants locaux** : décimaux exacts `Brick\Math\BigDecimal`, sans flottants.
   Valeurs attendues, quantité × prix = ligne × quantité de base du prix,
   ligne = somme = HT,
   base × taux = TVA × 100, TVA ventilée = TVA totale, HT + TVA = TTC et
   TTC = dû pour cette facture sans règlement, frais, remise ni arrondi.
   La quantité de base du prix réellement retournée est 1, unité C62 ; toute
   autre valeur est refusée pour cette fixture. Les ajustements optionnels
   doivent être nuls s'ils sont présents.

Les cas négatifs couvrent les éléments absents, dupliqués, déplacés, les URI
incorrectes malgré des noms et valeurs conservés, les valeurs incohérentes,
les devises et schémas incorrects, les déclarations DTD/entités, les décimaux
invalides et les écarts qui seraient perdus par un calcul en flottants.
Changer uniquement les préfixes XML reste accepté.

## Examen des données de la réponse réelle

L'intégralité des textes et attributs du XML a été examinée avant utilisation
comme référence. Vendeur et acheteur sont explicitement fictifs, avec SIRET
`00000000000000`, SIREN `000000000` et TVA `FR00000000000`. Les adresses sont
`1 rue Fictive`, Paris, et `2 rue Fictive`, Lyon ; les adresses électroniques
sont `vendeur-fictif@example.invalid` et `acheteur-fictif@example.invalid`.
La ligne est explicitement une prestation fictive sans opération commerciale.

Aucune donnée sensible n'a été identifiée : aucun jeton, secret, mot de passe,
coordonnée bancaire, pièce jointe, commentaire, instruction de traitement ou
donnée de tiers réel n'apparaît dans le document examiné. Aucun assainissement
n'a été nécessaire. Un test contrôle les valeurs des deux parties, le libellé
de ligne, l'échéance et l'absence des éléments annexes précités ; l'empreinte
fige l'ensemble du contenu ayant été examiné.

## Limites réglementaires

Aucune validation externe, XSD, Schematron, EN16931 complète, CIUS française,
annuaire, routage, identité fiscale ou obligation légale n'est exécutée ici.
La disparition des cinq erreurs rapportées et HTTP 200 ne prouvent pas la
conformité réglementaire de toutes les factures ni leur transmission.
Le contrôle superficiel de la réponse dans `SandboxConversionProbe` reste
inchangé ; ces nouveaux contrôles appartiennent exclusivement aux tests.

La réception SUPER PDP, les PDF, les migrations et les parcours d'émission
restent inchangés. Les tests utilisent SQLite en mémoire, sans écriture MariaDB
Alwaysdata. Les essais de transport existants emploient des réponses HTTP
simulées ; ils ne reproduisent pas un nouvel essai distant.

## Résultats locaux du 6 novembre 2026

- Tests de contrôles CII réel et synthétique : **162 réussis, 345 assertions**.
- Tests unitaires SUPER PDP : **565 réussis, 2 360 assertions**.
- Suite PHP complète : **1 043 réussis, 5 061 assertions** (43,74 s).
- Suite JavaScript : **4 réussis**, via
  `node --preserve-symlinks --preserve-symlinks-main --test tests/JavaScript/invoice-form.test.cjs`.
- Vérification Pint réussie pour les trois fichiers PHP concernés par cette étape.

Les suites PHP ont été exécutées directement avec `vendor/bin/pest`, sous
`APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vide
et conversion désactivée. `allow_url_fopen=0` et les fonctions natives
`curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,socket_connect`
étaient désactivées. Un bootstrap temporaire remplaçait les deux fonctions cURL
par des fonctions qui lèvent immédiatement une exception : Guzzle peut ainsi
initialiser son transport pour les réponses simulées, mais aucun appel réseau
ne peut être exécuté par ce transport. Ce bootstrap a été retiré après les tests.

Ces chiffres incluent les contrôles directs du CII réel et les tests négatifs
sur ses copies en mémoire. Le SHA-256 du fichier sur disque a été vérifié à
nouveau après les suites : il reste identique à celui relevé avant les travaux.
