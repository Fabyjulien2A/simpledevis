# Diagnostic local d’éligibilité à la préparation B2B France

## Source historique prioritaire des parties

La lecture électronique utilise désormais exclusivement `invoices.party_snapshot`
lorsqu'il est présent et valide. Version, structure, valeurs et absence de clés
dupliquées sont contrôlées ; Company et Client ne sont alors pas consultés.
Les métadonnées de compatibilité `company_vat_regime` et `company_invoice_mentions`
proviennent de cet instantané, malgré leur nom. Les mentions et le régime manuels
ne remplacent pas les valeurs historiques. Le cadre B1/S1/M1 reste lu sur la facture.

Un instantané absent ajoute `invoice.party_snapshot.missing` : la lecture historique
depuis les profils reste disponible et les autres contrôles continuent, mais le
builder refuse une préparation persistée. Les références au profil dans les
paragraphes ci-dessous décrivent ce parcours historique, désormais bloqué.
Une structure invalide produit `invoice.party_snapshot.invalid`, une version non
prise en charge `invoice.party_snapshot.unsupported_version`, sans aucun repli.
Le blocage de routage est conservé dans tous les cas.

Le contrat détaillé, les projections partagées avec le builder et les limites
figurent dans [la lecture électronique des instantanés](invoice-party-snapshot-electronic.md).

`SuperPdpEmissionEligibilityDiagnostic::diagnose(Invoice $invoice, array $context = [])`
relit une facture enregistrée avec `InvoiceExportDataBuilder::buildElectronic()`.
L’appelant reste responsable de l’autorisation d’accès à la facture. Le service
ne modifie ni le modèle fourni, ni ses relations, ni le stockage. Il ne fait aucun
appel réseau, OAuth, conversion, rendu, dépôt ou émission.

## Contrat de résultat

```php
[
    'eligible' => false,
    'local_checks_passed' => true,
    'blockers' => [[
        'code' => 'routing.verification_unavailable',
        'category' => 'verification_unavailable',
        'field' => 'buyer.electronic_address',
        'message' => '...',
    ]],
]
```

- `eligible` n’est vrai qu’en l’absence de blocage. Il reste **toujours faux dans
  cette version**, car aucune vérification de destination n’est implémentée.
- `local_checks_passed` indique que les contrôles locaux ont tous réussi ; il
  n’écarte que le blocage de routage, pas les autres vérifications impossibles.
- Chaque blocage comporte un code stable, une catégorie, un chemin et un message.
  Les consommateurs doivent utiliser `code` et `field`, jamais analyser le message.
- Catégories : `missing_data`, `unsupported`, `invalid_data`,
  `verification_unavailable`. Plusieurs blocages indépendants sont collectés.

Le diagnostic ne retourne pas le payload temporaire et n’autorise aucun envoi.

## Contexte explicite

À l’exception du cadre lu sur la facture et du régime et des mentions lus dans le profil persisté, ces
informations ne sont pas conservées par le modèle actuel. Elles sont à fournir
explicitement pour évaluer les contrôles locaux, sans valeurs par défaut :

| Clé | Valeur prise en charge |
| --- | --- |
| `document_kind` | `ordinary` ; les acomptes et autres natures sont exclus |
| `type_code` | entier `380` |
| `currency_code` | `EUR` |
| `vat_regime` | facultatif ; sert uniquement à vérifier la concordance avec le régime du profil |
| `seller_electronic_address` | tableau `scheme` / `value` explicites |
| `buyer_electronic_address` | tableau `scheme` / `value` explicites |
| `french_context` | facultatif ; cadre B1/S1/M1 relu sur la facture, notes PMT/PMD/AAB lues depuis le profil ; les valeurs manuelles doivent concorder |
| `tax_registration_identifier` | facultatif ; doit concorder avec le BT-32 préparé depuis le profil s’il est fourni |

Les données d’identité, les lignes, totaux et règlements proviennent exclusivement
de la relecture du stockage. Le contexte ne permet pas de les remplacer.
Les mentions proviennent de l’entreprise du propriétaire enregistré de la facture,
indépendamment de l’utilisateur connecté et des relations déjà chargées.
Chaque mention absente ou blanche produit `company.mentions.{code}.missing`.
Les notes manuelles doivent être structurées, complètes et exactement cohérentes
avec les textes persistés, sinon le diagnostic signale une erreur de contexte
ou `company.mentions.{code}.context_conflict`. Elles ne comblent pas un profil vide.
Le cadre est relu depuis `invoices.french_business_process_type`, indépendamment
des attributs locaux et de l’utilisateur connecté. Son absence produit
`invoice.business_process_type.missing` (« Cadre de facturation non renseigné »),
une valeur invalide `invoice.business_process_type.invalid`, et un contexte
contradictoire `invoice.business_process_type.context_conflict`. Ces contrôles
sont partagés avec la préparation du payload d’une facture persistée. Aucun
contexte manuel ne comble une valeur absente ou invalide.
Le cadre n’est jamais déduit des lignes, du taux de TVA ou du régime fiscal. La présence des textes ne valide pas
leur contenu juridique. Voir [le contrat des mentions](company-french-invoice-mentions.md).
BT-32 est désormais préparé par la source électronique pour la franchise
explicitement déclarée, sans numéro dans les deux colonnes TVA, à partir du
SIRET de forme valide. Aucun numéro de TVA ni texte légal n’est généré. Le
contexte explicite cohérent reste accepté si la source fournie n’a pas préparé
BT-32 ; il ne remplace jamais un identifiant préparé contradictoire.

Un numéro de TVA vendeur est requis pour `subject_to_vat`. La franchise exige
des lignes E avec `VATEX-FR-FRANCHISE`, un taux nul contrôlé par le préparateur
fiscal et, sans TVA vendeur, un BT-32 cohérent avec le SIRET. Le service ne déduit
jamais l’éligibilité à la franchise d’un taux nul ou d’un numéro de TVA absent.
La qualification du régime est une déclaration enregistrée par l’entreprise,
pas une certification fiscale par le service. Les autres régimes sont exclus.

## Régime enregistré dans le profil

`buildElectronic()` ajoute la métadonnée locale `company_vat_regime` depuis
`invoice → user → company.vat_regime`, dans la même relecture du stockage que les
autres données. L’export historique `build()` et le payload EN16931 gardent leur
contrat : cette métadonnée ne devient pas un nouveau champ EN16931.

Le diagnostic utilise exclusivement cette valeur persistée :

- `subject_to_vat` et `franchise` déclenchent leurs contrôles existants, sans
  exiger le paramètre manuel `context.vat_regime` ;
- `null` conserve le code stable `context.vat_regime.missing`, catégorie
  `missing_data`, avec le message « Régime de TVA non renseigné dans les
  paramètres de votre entreprise. » ;
- un contexte explicite différent, même vide, produit
  `tax.regime.context_conflict`, catégorie `invalid_data`, champ `vat_regime`.
  Les contrôles continuent avec le régime persisté, sans le remplacer ;
- un contexte manuel ne peut pas lever l’absence de régime dans le profil ;
- un régime persisté non pris en charge reste bloqué par `tax.regime.unsupported`.

Aucun régime n’est déduit des identifiants ou des lignes. La franchise sans TVA
utilise le SIREN extrait du SIRET comme BT-32 dans la lecture électronique.
Un changement de profil est relu au diagnostic suivant et ne
modifie pas les données de la facture. L’autorisation reste à la charge de
l’appelant ; la fiche facture conserve ses contrôles de propriétaire avant
l’appel au diagnostic.

Le diagnostic impose un SIRET acheteur pour ce périmètre initial. C’est une
politique de préparation plus restrictive que le builder générique, qui garde
ses identifiants acheteur facultatifs. Ni un SIRET ni une adresse électronique
ne prouvent une destination inscrite et joignable. Aucun paramètre tel que
`routing_verified=true`, identifiant annuaire ou jeton OAuth ne retire le blocage
de routage. Les clés de contexte non utilisées n’attestent rien.

Vérification du raccordement au régime persisté, le 7 novembre 2026 :
**20 nouveaux tests**, **111 tests ciblés réussis (564 assertions)**,
**1 603 tests PHP réussis (7 986 assertions, 58,07 s)** et Pint `--test` réussi
sur les six fichiers PHP concernés. Les suites utilisent SQLite en mémoire et
un bootstrap temporaire bloquant les connexions SQL non SQLite et les transports
réseau, retiré après vérification. Aucun accès MariaDB, aucune migration distante
ni émission. Les quatre XML CII, le calculateur, le builder de payload et le
modèle PDF conservent leurs empreintes.

## Réutilisation et périmètre

- `FrenchInvoiceContext::fromArray()` valide le cadre et les trois mentions.
- `InvoiceFiscalDataPreparer::prepare()` réutilise les calculs décimaux, les
  ventilations, catégories, unités et contrôles des montants persistés.
- `SuperPdpInvoicePayloadBuilder::buildFromData()` partage exactement la
  construction et la validation structurelle avec `build()`, sans nouvelle
  lecture SQL. `build()` conserve ses contrôles préalables avant toute lecture.
- Les contrôles fiscaux et français sont indépendants. Le contrôle final du
  builder est exécuté seulement après résolution des prérequis locaux ; ses
  exceptions métier restent à arrêt au premier défaut. Le diagnostic doit donc
  être réexécuté après correction des blocages pour révéler les défauts suivants.

Les brouillons, versions historiques non qualifiées, professionnels étrangers,
particuliers, règlements même nuls ou futurs, statuts payé/partiellement payé et
avoirs définitifs sont bloqués. Les avoirs brouillons ne sont pas appliqués.
La nature ordinaire est déclarée explicitement : le stockage actuel ne permet
pas de détecter automatiquement toute facture d’acompte autonome.

La lecture fraîche n’est pas un instantané transactionnel destiné à l’envoi et
ne réserve aucune émission. Aucun contrôle d’idempotence, habilitation OAuth,
XSD/Schematron/EN16931 complet, conformité réglementaire complète ou identité
fiscale distante n’est ajouté. Les quatre CII de référence restent inchangés.

## Vérification

Les tests unitaires remplacent uniquement la lecture par une fixture et
interdisent tout SQL et HTTP. Les tests d’intégration utilisent SQLite en mémoire,
vérifient la relecture de relations périmées et l’absence d’écriture pendant le
diagnostic (requêtes exclusivement SELECT et comparaison du stockage).
Les fixtures enregistrées pour ces tests sont créées uniquement dans SQLite.

Résultats du 7 novembre 2026 :

- Nouveaux cas de diagnostic : **63** (unitaires et intégration SQLite).
- Tests ciblés du diagnostic et du builder : **128 réussis, 425 assertions**.
- Suite PHP complète : **1 507 réussis, 7 292 assertions** (58,39 s).
- Pint `--test` : réussi sur les cinq fichiers PHP ajoutés ou modifiés.
- `git diff --check` : réussi ; empreintes des quatre XML CII inchangées.

Les suites ont été lancées directement avec `vendor/bin/pest`, sans script
Composer ni commande Artisan métier. Le bootstrap temporaire imposait
APP_ENV=testing, SQLite en mémoire, DB_URL vide, conversion désactivée et absence
de configuration applicative en cache. Les résolveurs SQL non SQLite levaient
une exception avant connexion. `allow_url_fopen=0` et les fonctions de transport
natif réseau étaient désactivés ; les deux fonctions cURL étaient remplacées
par des exceptions pour permettre l’initialisation des transports HTTP simulés.
Ce bootstrap a été retiré après les vérifications. Aucun accès MariaDB Alwaysdata
ni appel réseau ; aucune migration ajoutée ou déployée sur une base métier.

## Affichage sur la fiche facture

La page existante `invoices.show` permet une intégration directe :
`InvoiceController::show()` contrôle le propriétaire, relit la facture et
contrôle à nouveau son propriétaire avant d’appeler le diagnostic. Le résultat
est passé au fragment Blade `invoices/partials/emission-diagnostic.blade.php`.
Le fragment est affiché pour les factures définitives et les brouillons.

L’appel ne fournit aucun contexte : le régime déclaré est relu par le service,
tandis que nature/type de document, devise, adresses électroniques et contexte
français ne sont pas déduits des montants, du profil, de l’e-mail ou des notes
libres. Les paramètres de la
requête HTTP ne servent pas de contexte et ne prouvent aucune vérification.
Les blocages correspondants restent visibles.

La vue ne valide aucune donnée : elle traduit les messages techniques en
libellés français, regroupe les blocages selon leurs catégories et regroupe les
messages identiques dans chaque catégorie. Les codes et champs originaux restent
intacts dans le résultat du service. Les messages sont échappés par Blade.

L’encart distingue les contrôles locaux et le résultat global, précise qu’il
ne vaut pas autorisation d’émettre ni validation réglementaire complète, et
rappelle que la génération d’un XML ne prouve pas la transmissibilité. Aucun
bouton, lien, formulaire, route, appel distant ou nouvelle écriture n’est ajouté
par cet encart. Les actions existantes de la page, y compris l’envoi par e-mail,
les exports, paiements, avoirs, duplication et validation de brouillon, restent
en place. Le service de diagnostic et ses règles sont inchangés par cette étape.

`InvoiceEmissionDiagnosticDisplayTest` couvre l’affichage réel, le contexte absent
et les paramètres HTTP ignorés, les contrôles locaux réussis avec routage bloqué,
l’échappement HTML, les brouillons, paiements et avoirs, l’absence d’écriture sur
les tables métier/électroniques et les autorisations (visiteur, autre propriétaire,
facture inexistante et propriétaire changé depuis le chargement du modèle).

Résultats de cette intégration du 7 novembre 2026 : **12 nouveaux tests**,
**75 tests ciblés réussis (351 assertions)** et **1 519 tests PHP réussis
(7 401 assertions, 45,75 s)**. Pint `--test` réussit sur le contrôleur et le nouveau
fichier de tests ; `git diff --check` réussit. Les quatre empreintes CII sont
inchangées. Les suites utilisent le même isolement SQLite en mémoire et blocage
réseau que ci-dessus, via un bootstrap temporaire supprimé après vérification.
