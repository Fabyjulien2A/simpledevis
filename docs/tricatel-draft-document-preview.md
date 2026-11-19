# Aperçu électronique de test des brouillons Tricatel

Depuis la page de détail d'un brouillon Tricatel, l'action **Aperçu électronique
(test)** ouvre `GET /invoices/{invoice}/sandbox-electronic-preview`.
La route nommée est `invoices.sandbox-electronic-preview`. Il n'est pas nécessaire
de finaliser le brouillon ni de lui attribuer un numéro.

## Périmètre et accès

`TricatelDraftPreviewScope` exige le sandbox SQL isolé selon
`SandboxDatabaseIsolation`, l'environnement SUPER PDP sandbox, l'URL attendue,
l'utilisateur authentifié 4, son entreprise existante 4 nommée Tricatel et un
brouillon appartenant à ce propriétaire. Le modèle Invoice actuel rattache
l'entreprise par `user_id` ; un éventuel `company_id` contradictoire est également
refusé. Le client est relu séparément et son propriétaire est contrôlé avant toute
projection. Un client absent reste une donnée manquante à afficher.

La connexion OAuth locale de l'entreprise doit porter `directory_identifier`
`000000001`, un identifiant fournisseur positif, le statut `connected` et le même
environnement, client OAuth et URL API que la configuration. Le schéma `sandbox`
vient du lien explicite entreprise 4 / propriétaire 4 existant dans
`SuperPdpOAuthService::identity()`. Aucun appel de vérification distante, lecture
de jeton, renouvellement ou synchronisation n'est effectué.

Les attributs de la facture sont relus et réautorisés après la liaison de route.
Les factures définitives sont refusées. Un brouillon contenant déjà un
`party_snapshot` ou un `emission_context` est refusé avec HTTP 409 : les données
figées ne sont ni remplacées ni réinterprétées à partir des fiches actuelles.
Les paramètres de requête ne fournissent aucune donnée à l'aperçu.

## Données et limites

`TricatelDraftDocumentPreview` réutilise `InvoiceFinalizationSummary`, sa
projection des parties, `InvoiceLinePreparationService` et le diagnostic existant.
Les règles de SIRET, TVA et adresse 0225 restent donc visibles comme blocages.
La déclaration d'adresse est affichée séparément même lorsqu'elle est refusée.

Les totaux calculés utilisent les chaînes décimales des lignes enregistrées.
Les écarts avec les totaux ou montants de ligne stockés sont signalés sans
correction. Si les lignes sont invalides, leurs valeurs enregistrées restent
consultables et les totaux calculés sont indiqués indisponibles.

L'identité `sandbox / 000000001` apparaît exclusivement dans les métadonnées
sandbox, sans transformation en SIRET, SIREN ou TVA. Le résultat n'est ni un
payload CII, ni un instantané, ni une preuve de publication ou de routage.
Les mentions suivantes restent affichées :

- Document CII non produit.
- Conformité électronique non établie.
- Envoi électronique interdit.

La réponse est privée et non stockable en cache (`private, no-store`). Aucun
service d'archive, de génération CII, d'émission ou de transport SUPER PDP n'est
appelé. Les validateurs, contrats d'instantané et règles de finalisation de
production ne sont pas modifiés.

## Vérification hors ligne

`tests/Unit/Http/TricatelDraftPreviewTest.php` parcourt les routes, le contrôleur,
les vrais calculs et diagnostics avec `ReadOnlyDraftPreviewDatabase` : uniquement
des tableaux PHP, aucun PDO ni SQL. Toute écriture, transaction, verrou ou
exécution SQL échoue. Les transports HTTP Laravel et SDK sont bloqués, et tout
accès à un disque via Storage est interdit. Les tables simulées sont comparées
avant et après chaque test, y compris les factures, instantanés et jetons.

```powershell
php vendor/bin/pest tests/Unit/Http/TricatelDraftPreviewTest.php --bootstrap tests/Support/ExperimentalOfflineBootstrap.php --compact
```

La couverture comprend l'accès autorisé, l'action sur le brouillon et son absence
hors périmètre, les refus interutilisateurs et interentreprises, les connexions
OAuth incompatibles, les modèles périmés, les données figées, les champs absents,
les lignes invalides, le calcul avec plusieurs taux de TVA, les écarts stockés,
l'échappement HTML et l'absence d'effets secondaires.
