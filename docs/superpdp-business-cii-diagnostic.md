# Préparation métier fictive SimpleDevis → EN16931 → CII

Ce scénario est préparé exclusivement hors ligne. Aucun nouvel appel SUPER PDP
n'a été autorisé ou exécuté pour lui. Le succès du diagnostic technique précédent
ne constitue pas une validation distante de ce nouveau scénario.

## Parcours réutilisé et frontière de l'essai

`InvoiceController::store()` traduit les choix fiscaux du formulaire, appelle
`InvoiceLinePreparationService`, enregistre un brouillon puis capture les parties
et le contexte lors de sa finalisation. La traduction auparavant privée est
extraite à l'identique dans `InvoiceFiscalItemTranslator` ; le contrôleur et la
fixture appellent désormais ce même service. Les calculs et règles sont inchangés.

`BusinessInvoiceFixture` appelle cette traduction, le préparateur de lignes et le
calculateur décimal existants. Elle renseigne un modèle `Invoice` non enregistré
avec les mêmes champs et montants que la création, puis utilise les contrats purs
`InvoicePartySnapshot::capture()` v2 et `InvoiceEmissionContext::capture()`.
Le statut `issued` et le numéro sont des valeurs fictives assignées en mémoire,
sans prétendre à une finalisation réelle ni consommer une numérotation métier.

Le builder existant est appelé via `build(Invoice, ...)`, avec des adresses
explicites conformes au contexte figé. Il réutilise la projection des parties,
les mentions françaises, le contrôle du régime fiscal, la vérification des
montants stockés et le schéma JSON EN16931. Le contexte figé n'est pas remplacé.

L'adaptateur de fixture accepte uniquement son propre modèle non enregistré.
L'export de production `InvoiceExportDataBuilder::buildElectronic()` n'est pas
appelé : il relit volontairement la base pour prouver l'absence de règlements.
Cette relecture, les transactions, la capture persistée, les autorisations HTTP,
la numérotation, la qualification d'annuaire et `InvoiceCiiPreparation::produce()`
ne sont donc pas testés ici. Aucun service d'archive officielle n'est utilisé.

## Scénario fixe

Facture `SANDBOX-METIER-0001`, émission fictive du 11 novembre 2026, échéance du
11 décembre 2026, EUR, type 380, prestations S1, aucun règlement ni avoir.

| Ligne | Quantité | Prix HT | Montant HT | TVA |
| --- | ---: | ---: | ---: | ---: |
| Prestation fictive A | 2,50 | 40,00 | 100,00 | 20 % |
| Prestation fictive B | 4,00 | 25,00 | 100,00 | 10 % |

Attendus indépendants : HT **200,00**, TVA **30,00** (20,00 + 10,00),
TTC et dû **230,00**. Vendeur et acheteur entièrement fictifs, identifiants de
zéros repris du diagnostic technique, adresses `@example.invalid`. Ces
identifiants ne sont pas qualifiés pour une opération commerciale ou un routage.

## Vérifications et provenance

Les tests contrôlent les parties, identifiants, adresses, dates, référence,
quantités, unités, prix, lignes, catégories et taux de TVA, ventilations et totaux.
Un cas distinct vérifie l'arrondi HT à la ligne puis TVA par catégorie/taux et
l'ignorance des montants fournis par le navigateur. Les contradictions fiscales,
montants altérés, brouillons, règlements, avoirs et preuves figées manquantes
sont refusés. Toute tentative SQL/PDO dans le périmètre de fixture est bloquée
par une connexion sans PDO ; Laravel n'est pas démarré par le lanceur.

Le XML de `tests/Fixtures/SuperPdp/business-simulated.cii.xml` est un **double
synthétique**, adapté d'une capture multitaux historique. Il n'est jamais
présenté comme une nouvelle réponse SUPER PDP. Le rapport positif simulé indique
un seul contrôle fictif, pas les 140 contrôles du premier essai réel.
Les mutations du XML doivent interrompre la chaîne avant la validation.

Le transport du diagnostic conserve ses deux POST publics maximum, dans l'ordre
conversion puis validation des octets exacts du XML contrôlé, sans OAuth,
redirection ou retry. Aucun transport d'émission n'est modifié ou activé.
Les artefacts restent privés, portent `fixture_scenario=business`,
`official_archive=false`, `ready_for_emission=false` et des empreintes SHA-256
du payload, du XML et de la réponse de validation, ainsi que des définitions de
fixture et de traduction. Le rapport brut demeure non persisté conformément au
diagnostic existant ; seule sa synthèse est conservée.

## Commandes

Contrôle hors ligne (ne crée pas de XML ni de rapport fournisseur) :

```powershell
php .\tools\superpdp-cii-diagnostic.php --check --business-fixture
```

Tests unitaires isolés avec fonctions réseau désactivées :

```powershell
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail vendor/bin/pest tests/Unit/Tools/SuperPdpBusinessCiiDiagnosticTest.php tests/Unit/Tools/SuperPdpCiiDiagnosticTest.php --compact
```

Après une **nouvelle autorisation explicite** seulement, un futur essai unique
pourrait utiliser :

```powershell
php .\tools\superpdp-cii-diagnostic.php --execute --confirm-fictional-sandbox --business-fixture
```

Sans `--business-fixture`, le scénario technique initial demeure sélectionné.
Les endpoints publics n'attestent pas eux-mêmes un environnement sandbox ; le
profil local est vérifié avant tout appel. Un futur résultat réel reste inconnu.

Vérifications réalisées lors de cette préparation : 465 tests unitaires exécutés
sans échec, 2 116 assertions. Parmi eux, 86 signalent la dépréciation préexistante
de `BigDecimal::stripTrailingZeros()`. Le périmètre inclut les deux diagnostics,
les préparateurs de lignes/fiscalité, le calculateur, les instantanés et les tests
de payload du builder. Les deux commandes `--check` (scénarios métier et technique)
réussissent avec zéro requête ; les fichiers PHP modifiés passent le contrôle de
syntaxe. Aucun test Feature avec base ni aucune migration n'a été exécuté.
