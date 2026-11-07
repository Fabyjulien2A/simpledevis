# Troisième référence CII réelle : S1 multi-taux

L'utilisateur rapporte HTTP 200 pour `SANDBOX-FICTIF-MULTITAUX-0003` et fournit
le XML retourné. Cette déclaration constitue la provenance de la réponse ; aucun
en-tête HTTP brut ou rapport de conformité n'a été fourni. Aucun appel distant
n'a été effectué pendant l'intégration de cette référence.

## Octets originaux

Existence vérifiée avant utilisation :
`tests/Feature/SuperPdp/superpdp_multitaux_cii_reponse_2026-11-06.xml`.

SHA-256 réel calculé sur le fichier :

`beb602e30ce56d576783e2ac6116a677bf625930baf13590c679408cbb0068d3`

`CapturedMultiRateCii` lit directement ce fichier et refuse un fichier absent ou
une empreinte différente. Le XML n'est ni copié, ni reconstruit, ni reformaté.
Les fins de ligne et tous les octets sont conservés. Toute nouvelle empreinte
exigerait une revue explicite de provenance et de contenu.

Les deux références précédentes, leurs contrôles et leurs empreintes sont
conservés et vérifiés avec la troisième :

- Standard à 20 % : `3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`.
- Franchise : `c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735`.

## Contrôles locaux

Le contenu intégral du troisième XML a été examiné : parties explicitement
fictives, SIREN/SIRET à zéro, numéro TVA fictif à zéro, adresses fictives à Paris
et Lyon, communications `example.invalid`, deux prestations signalant l'absence
d'opération commerciale. Aucun secret, jeton, donnée bancaire, pièce jointe,
commentaire ou donnée de tiers réel n'a été identifié. Aucun assainissement
du fichier n'a été nécessaire.

Les nouveaux helpers et tests sont exclusivement sous `tests/` ; aucun service
applicatif ni scénario de préparation n'est modifié.

Trois couches séparées sont exécutées sur le XML réel :

1. Structure : chemins ancrés, namespaces fixes et cardinalités. Numéro attendu,
   type 380, date et profil, EUR, identifiants vendeur, exactement deux lignes
   aux identifiants distincts 1 et 2 et exactement deux ventilations. Catégorie S
   et type VAT aux niveaux ligne/document, sans motif d'exonération.
2. Contexte français : S1 et les notes PMT/PMD/AAB déjà contrôlées pour les deux
   premières références, sans les interpréter juridiquement.
3. Montants : décimaux exacts Brick Math, sans float. Ligne 1 à 20 %, ligne 2 à
   10 %, chaque quantité et base de prix à 1, prix et montant HT à 100. Chaque
   ventilation a une base de 100 et une TVA de 20 ou 10. HT 200, TVA 30, TTC/dû
   230. Identités quantité/prix/ligne, ligne/base, base/taux/TVA, sommes des bases
   et TVA, HT + TVA = TTC = dû. Ajustements facultatifs nuls pour cette fiction.

Le parseur existant est réutilisé avec LIBXML_NONET, DTD/entités interdites et
résolution externe désactivée. Aucun chargement de ressource externe.

Les mutations négatives utilisent uniquement des DOM en mémoire : suppressions,
duplications, déplacements, mauvais numéro ou cadre, identifiant de ligne
dupliqué, catégories E/Z, taux incohérents ou dupliqués, bases/TVA erronées,
totaux et devises modifiés, exposants/virgules/négatifs, écart décimal imperceptible
en float, mauvaise quantité de base, motifs d'exonération et ajustements non nuls,
URI incorrectes. Une mutation conserve la TVA globale à 30 mais redistribue
19/11 entre ventilations : elle est refusée. L'ordre des ventilations et les
échelles décimales équivalentes restent acceptés. Les XML ne sont jamais réécrits.

## Limites et environnement

Conversion acceptée pour cette fiction selon le résultat rapporté, sans
certification réglementaire, émission, routage ni validation des identités
fiscales. Ces contrôles ciblés ne sont pas un validateur XSD, Schematron, EN16931
ou français complet. L'éligibilité réelle au taux de 10 % n'est pas déterminée.

Les suites utilisent APP_ENV=testing, SQLite en mémoire, DB_URL vide et conversion
désactivée. L'ouverture URL et les fonctions de transport réseau natif sont
désactivées. Le bootstrap temporaire remplace les fonctions cURL désactivées par
des fonctions levant une exception afin de permettre aux tests HTTP existants
d'initialiser Guzzle avec leurs réponses simulées. Aucun accès MariaDB Alwaysdata,
appel réseau ou émission n'a été effectué.

## Résultats locaux

- 99 nouveaux cas de régression de la troisième référence réelle.
- Tests unitaires SUPER PDP : **825 réussis, 3 423 assertions** (16,22 s).
- Suite PHP complète : **1 303 réussis, 6 124 assertions** (45,87 s).
- Pint : vérification réussie sur les trois nouveaux fichiers PHP.
- Empreintes des trois XML identiques avant et après les suites.
- Bootstrap temporaire de blocage réseau retiré après les tests.

Les seules additions pérennes sont les trois fichiers de support/tests et ce
bilan documentaire. Aucun code applicatif ni test des références précédentes
n'a été modifié.
