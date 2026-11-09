# Cadre français explicite sur les factures

`invoices.french_business_process_type` conserve le choix propre à chaque facture :
`B1`, `S1`, `M1` ou `null`. La migration additive
`2026_11_07_000003_add_french_business_process_type_to_invoices_table.php` ajoute
une colonne nullable limitée aux trois codes, sans valeur par défaut et sans
mise à jour des factures historiques.

Le formulaire partagé de création et de modification propose « Non renseigné »,
« Biens — B1 », « Services — S1 » et « Biens et services — M1 ». Ces libellés
reflètent le périmètre local actuel ; leur sélection n’est pas une validation
réglementaire de la qualification de l’opération.

## Brouillons et conservation

Un brouillon peut rester sans cadre, quelle que soit sa version de calcul.
Les deux parcours de modification, historique et `decimal-v1`, acceptent les
trois chaînes autorisées ou un champ vide. Les valeurs inconnues et les types
incorrects sont rejetés avant toute modification de la facture et de ses lignes.
Les middlewares Laravel habituels retirent les espaces périphériques et
convertissent les chaînes vides en `null`.

Une clé absente de la requête conserve le cadre enregistré. Une clé explicitement
vide le remet à `null`. Le formulaire conserve la saisie après une erreur de
validation, y compris un effacement non encore enregistré.

Le cadre n’est jamais déduit du profil entreprise, des descriptions, des unités,
des catégories, taux ou régimes de TVA. La duplication et la conversion d’un
devis produisent un nouveau brouillon sans cadre, même si la facture source avait
un cadre ou si la requête transmet un code. Les parcours existants omettent
déjà cette colonne : aucun changement de leur logique n’est nécessaire.

## Validation définitive

Deux parcours imposent le choix enregistré avant l’attribution d’un numéro :

- **Création directement définitive** : la requête exige un code valide. Dans
  une transaction, le contrôleur verrouille l’utilisateur, crée un brouillon
  avec ce code et ses lignes, puis relit la facture sous verrou. Il vérifie son
  propriétaire, son état et son cadre persisté avant de réserver le numéro et
  de passer à `issued`. Un échec annule la création et les écritures associées.
- **Finalisation d’un brouillon** : la facture est relue sous verrou dans la
  transaction existante. Le contrôle du cadre enregistré s’applique aux versions
  historique et `decimal-v1`, avant toute réservation de numéro. Le cadre envoyé
  uniquement à l’URL de finalisation est ignoré : il faut d’abord enregistrer le
  choix dans le formulaire du brouillon. Les contrôles fiscaux restent inchangés.

L’ordre des verrous reste utilisateur puis facture. Une facture déjà définitive
n’est pas renumérotée lors d’une deuxième finalisation.

## Immutabilité et factures historiques

L’édition HTTP d’une facture définitive reste interdite. Un garde ciblé sur
`Invoice::updating` refuse aussi le changement du cadre d’une facture dont le
statut persisté est `issued`, même si l’instance Eloquent est un ancien brouillon
ou si la même sauvegarde tente de rétrograder le statut documentaire.

Les mises à jour des paiements, montants réglés et statuts PDP restent permises
sans changement de cadre. Les factures historiques définitives sans cadre restent
à `null` : elles ne sont ni remplies automatiquement, ni enrichies après coup par
ce parcours. Les brouillons historiques peuvent être renseignés puis finalisés.

Cette protection est applicative : les écritures SQL directes et les opérations
qui contournent les événements Eloquent ne sont pas couvertes par ce garde.
Aucun trigger SQL n’est ajouté. Les verrous des contrôleurs ne sont pas une
preuve de concurrence MariaDB : les tests utilisent SQLite en mémoire uniquement.

## Raccordement électronique

`InvoiceExportDataBuilder::buildElectronic()` relit le cadre depuis la facture
enregistrée, avec ses autres données, sans se fier aux attributs modifiés en mémoire.
L’export historique `build()` conserve son contrat.

Le diagnostic complète le contexte français avec ce cadre et les mentions du profil.
`FrenchInvoiceContext` partage les contrôles avec `SuperPdpInvoicePayloadBuilder` :
la préparation d’une facture persistée doit utiliser exactement le cadre enregistré.
Un contexte manuel cohérent reste accepté ; une valeur différente, même vide,
produit un conflit explicite et ne remplace jamais le stockage.

Les blocages sont `invoice.business_process_type.missing` (« Cadre de facturation
non renseigné »), `invoice.business_process_type.invalid` et
`invoice.business_process_type.context_conflict`. L’absence ou la corruption ne
peut pas être corrigée par un contexte manuel. Aucun cadre n’est déduit des lignes,
des taux ou du régime fiscal. Les factures synthétiques et instantanés sans
métadonnée persistée conservent leur contexte explicite obligatoire.

Les PDF, services de calcul fiscal et quatre XML CII de référence restent
inchangés. Aucun appel réseau, accès MariaDB Alwaysdata ou émission électronique
n’est réalisé. Aucun texte des scénarios fictifs n’est utilisé comme valeur de
remplacement.

## Migrations distantes en attente

Les migrations suivantes restent à appliquer ultérieurement sur Alwaysdata,
dans cet ordre, avant l’utilisation des fonctionnalités correspondantes :

1. `2026_11_07_000001_add_vat_regime_to_companies_table.php`.
2. `2026_11_07_000002_add_french_invoice_mentions_to_companies_table.php`.
3. `2026_11_07_000003_add_french_business_process_type_to_invoices_table.php`.

Cette liste reprend l’état documenté localement, sans interrogation distante.
Aucune de ces migrations n’a été exécutée sur Alwaysdata pendant cette étape.

## Vérifications

Raccordement électronique vérifié le 8 novembre 2026 : 1 170 tests ciblés
(5 619 assertions), puis 1 804 tests PHP complets (9 664 assertions).
Pint appliqué et vérifié sur les dix fichiers PHP de cette étape ;
`git diff --check` réussi. Le lanceur temporaire impose SQLite en mémoire,
refuse les autres pilotes et bloque les transports réseau tout en permettant
les réponses HTTP simulées. Il est retiré après les vérifications.
Les quatre références CII et les services de calcul/PDF sont inchangés.
Aucune migration nouvelle ou distante, aucune émission électronique.

Les suites utilisent SQLite en mémoire exclusivement, `DB_URL` vide et une
configuration en cache écartée. Un bootstrap temporaire bloque les résolveurs SQL
non SQLite et toute base SQLite persistante. Les transports réseau sont bloqués
par les options PHP ; seules les réponses HTTP simulées restent utilisables.

Les tests de création, finalisation et PDF existants fournissent désormais un
cadre explicite dans leurs données lorsqu’ils passent par la validation
définitive. Les tests dédiés couvrent aussi l’absence de cadre et son effacement.
Pour le test du paiement après finalisation, la fixture SQLite ajoute localement
`payments.method` si nécessaire, comme le test de paiement existant : une migration
historique utilisant `change()` ne crée pas cette colonne sur une base SQLite
neuve. Aucune migration de paiement applicative n’est modifiée.

Résultats du 7 novembre 2026 :

- 64 nouveaux tests dédiés au cadre de facturation.
- Tests ciblés : 227 réussis, 1 973 assertions.
- Suite PHP complète : 1 789 réussis, 9 529 assertions, 58,37 s.
- Pint appliqué puis `--test` réussi sur les huit fichiers PHP concernés.
- `git diff --check` réussi.
- Empreintes des quatre services électroniques protégés, des services de
  calcul/PDF, des modèles PDF, des migrations préexistantes et des quatre XML CII
  inchangées. Seule la migration additive prévue a été ajoutée.
- Bootstrap temporaire, journaux de test et fichier d’empreintes retirés après
  vérification. Aucune migration distante exécutée.
