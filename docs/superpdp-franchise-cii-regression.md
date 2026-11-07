# Deuxième référence CII réelle : franchise S1

L'utilisateur rapporte HTTP 200 pour la conversion SUPER PDP de
`SANDBOX-FICTIF-FRANCHISE-0002`, prestation S1 en franchise en base :
100 EUR HT, 0 EUR TVA, 100 EUR TTC et dus. Le XML fourni est la référence
de régression locale. Aucun appel réseau n'a été effectué pour cette intégration.
La provenance et le statut HTTP reposent sur la déclaration de l'utilisateur ;
aucun en-tête HTTP brut ni rapport réglementaire n'a été fourni.

## Fichier original et empreinte

Le chemin annoncé `tests/Fixtures/SuperPdp/superpdp_franchise_cii_reponse_2026-11-06.xml`
n'existe pas. Le fichier réel a été trouvé sous :

`tests/Feature/SuperPdp/superpdp_franchise_cii_reponse_2026-11-06.xml`

SHA-256 calculé avant toute utilisation :

`c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735`

Le fichier est lu directement à cet emplacement, sans déplacement, copie,
reconstruction, reformatage ou normalisation. `CapturedFranchiseCii` refuse
un fichier absent ou dont l'empreinte diffère. Une modification, même des fins
de ligne, nécessite une revue explicite avant de changer l'empreinte attendue.

Le premier CII réel à 20 %, ses contrôles et son chargeur restent inchangés.
Son empreinte reste :

`3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`

## Contenu examiné et contrôles

L'ensemble du XML a été lu avant utilisation. Identités entièrement fictives,
SIREN/SIRET à zéro, adresses fictives à Paris et Lyon, communications sous
`example.invalid`. Le vendeur ne porte pas de numéro de TVA ; l'acheteur garde
le numéro fictif `FR00000000000`. La ligne indique explicitement une prestation
fictive sans opération commerciale. Aucun secret, jeton, donnée bancaire,
pièce jointe ou donnée de tiers réel n'a été identifié. Aucun assainissement
du fichier n'a été nécessaire.

Les nouveaux contrôles appartiennent exclusivement aux tests :

- `CapturedFranchiseCii.php` : chargement et empreinte du XML fourni.
- `FranchiseCiiChecks.php` : trois couches indépendantes, structure, contexte
  français et montants. Le parseur sûr et les contrôles S1/PMT/PMD/AAB existants
  sont réutilisés, sans modifier les contrôles spécialisés sur le premier CII.
- `FranchiseCiiChecksTest.php` : contrôles positifs et mutations négatives en
  mémoire, avec vérification de l'intégrité des références sur disque.

La lecture DOM emploie `LIBXML_NONET`, interdit DTD/entités et ne résout pas
les ressources externes. XPath utilise des URI de namespaces fixes, des chemins
ancrés et des cardinalités unitaires. Les valeurs fiscales vérifiées sont :

- S1, type 380, profil EN16931, numéro et date de cette référence.
- E sur la ligne et sur la ventilation ; taux nul aux deux niveaux.
- Code `VATEX-FR-FRANCHISE` et texte distinct
  `TVA non applicable, art. 293 B du CGI`, sur la ligne et sur la ventilation.
- BT-30 : `SellerTradeParty/SpecifiedLegalOrganization/ID`, SIREN `000000000`,
  schéma `0002` ; BT-32 : `SellerTradeParty/SpecifiedTaxRegistration/ID`,
  même SIREN sans FR, schéma `FC`. Le rôle fiscal reste distinct du rôle légal.
- EUR, base et HT 100, TVA 0, TTC et dû 100, quantité/base de prix 1, prix et
  montant de ligne 100. Décimaux exacts Brick Math, sans float ; égalités entre
  quantité, prix, ligne, base, TVA, HT, TTC et dû. Les ajustements optionnels
  doivent être nuls pour cette fiction sans règlement, frais ou remise.

Les mutations portent sur des DOM reconstruits en mémoire à partir des octets
lus : éléments absents/dupliqués/déplacés, URI erronées, mauvais identifiants ou
schémas, SIREN préfixé FR, catégorie Z/S, code ou texte d'exonération modifié,
taux positif, montants incohérents, formats décimaux invalides, écart au-delà de
la précision des flottants, devise et ajustements non nuls. Aucune mutation
n'est sauvegardée et aucun XML synthétique n'est présenté comme réponse réelle.

## Limites

Ce succès est une **conversion acceptée pour cette fixture uniquement**.
Les contrôles ciblés de BT-30/BT-32 et de l'exonération sont des régressions
locales ; ils ne constituent pas des implémentations complètes de BR-E-02,
BR-FR-CO-16 ou de toutes les règles fiscales françaises.

Aucune validation XSD/Schematron complète, EN16931 complète, CIUS française,
identité fiscale, annuaire ou routage n'est exécutée ici. Aucune certification
réglementaire, éligibilité réelle à la franchise, transmission ou émission
n'est démontrée. Aucun parcours applicatif, PDF, réception, migration ou facture
existante n'a été modifié.

Les suites PHP sont exécutées avec APP_ENV=testing, DB_CONNECTION=sqlite,
DB_DATABASE=:memory:, DB_URL vide et conversion désactivée. L'ouverture URL et
les transports natifs sont bloqués (`allow_url_fopen=0`, fonctions cURL et socket
désactivées). Un bootstrap temporaire fournit des fonctions cURL qui lèvent
immédiatement une exception, permettant aux tests HTTP existants d'utiliser
leurs réponses simulées. Aucun accès MariaDB Alwaysdata n'est effectué.

## Résultats locaux

- 102 nouveaux cas de régression du CII franchise.
- Tests unitaires SUPER PDP : **704 réussis, 2 926 assertions** (23,21 s).
- Suite PHP complète : **1 182 réussis, 5 627 assertions** (46,38 s).
- Pint réussit sur les trois nouveaux fichiers PHP.
- Empreintes des deux XML vérifiées après les tests : identiques aux empreintes
  relevées avant les travaux.
- Bootstrap temporaire supprimé après les suites ; aucun code de test n'est
  branché sur les parcours applicatifs de conversion, réception ou émission.
