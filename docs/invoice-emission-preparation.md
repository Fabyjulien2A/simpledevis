# Données de préparation de l’émission

Cette étape ne fait aucun appel réseau et n’autorise aucun envoi. Le blocage
`routing.verification_unavailable` demeure inconditionnel.

## Stockage et compatibilité

La migration additive `2026_11_09_000001_add_invoice_emission_preparation_fields`
ajoute uniquement des colonnes nullables, sans valeur par défaut ni reprise de données :

- `companies.country_code` : pays explicitement saisi, code ISO 3166-1 alpha-2 attribué ;
- `companies.billing_electronic_address` et `clients.billing_electronic_address` :
  objets `{scheme, value}` déclarés séparément des emails de contact ;
- `invoices.emission_profile` : choix explicite sur le brouillon ;
- `invoices.emission_context` : contexte versionné figé dans la transaction de finalisation.

Le pays vendeur est capturé dans `party_snapshot.seller.country_code` lorsqu’il
est renseigné. Les contrats d’instantanés v1/v2 restent inchangés, ainsi que les
PDF, la réception, les références CII et les parcours sans qualification.
Aucune facture définitive existante n’est enrichie après coup.

Le profil initial `fr_b2b_380_eur` signifie **facture ordinaire**, type **380**,
devise **EUR**, vendeur français et acheteur professionnel français. Il n’est
pas déduit de la devise du builder, du pays, d’un SIRET ou d’une connexion OAuth.
Il est sélectionné explicitement dans le formulaire de facture. Sans ce choix,
la facturation classique reste disponible, sans qualification électronique implicite.

## Adresses déclarées BT-34 / BT-49

Les deux champs de chaque adresse doivent être renseignés ensemble ou laissés
vides ensemble. Les schémas de saisie actuellement pris en charge sont :

- `0225` : SIREN correspondant au SIRET de la partie, éventuellement suivi d’un
  suffixe introduit par `_` ; contrôle local de forme et de concordance ;
- `EM` : adresse email de facturation explicitement déclarée, contrôle de forme
  RFC sans DNS ni validation d’existence.

Ces déclarations ne constituent **ni une inscription à l’annuaire ni une preuve
de destination ou d’habilitation**. Les adresses avec suffixe et EM ne deviennent
pas routables par cette étape. Aucun email de contact n’est repris automatiquement.
Modifier le SIRET impose de conserver une adresse cohérente, de la remplacer
explicitement ou de l’effacer.

Le contexte d’émission version 1 conserve exactement :

```json
{
  "schema_version": 1,
  "profile": "fr_b2b_380_eur",
  "document_kind": "ordinary",
  "type_code": 380,
  "currency_code": "EUR",
  "seller_electronic_address": {"scheme": "0225", "value": "732829320"},
  "buyer_electronic_address": {"scheme": "0225", "value": "552100554"},
  "routing_status": "not_verified"
}
```

Les valeurs ci-dessus sont fictives. Les références sont relues depuis le
stockage et validées contre les SIRET de l’instantané historique. Le diagnostic
et le builder utilisent ce contexte ; une surcharge contradictoire, un contexte
corrompu ou une version inconnue entraîne un refus sans recours aux profils actuels.
Les anciens instantanés sans contexte conservent le contrat explicite du builder
et les blocages du diagnostic ; leur absence n’est jamais réparée automatiquement.

## Complétude à la finalisation

Pour le profil choisi, les deux parcours de finalisation réutilisent le diagnostic
et le builder existants dans la transaction SQL. Les contrôles couvrent :

- identité historique des parties, adresses, codes postaux, villes, pays FR, SIRET ;
- type client professionnel, type 380, EUR et facture ordinaire ;
- régime de TVA explicite, identifiants fiscaux et lignes compatibles ;
- processus français B1/S1/M1 et mentions PMT/PMD/AAB capturées ;
- date, numéro, calcul `decimal-v1`, échéance ou conditions de paiement si dû positif ;
- adresses BT-34/BT-49 explicites et absence de règlements ou d’avoirs définitifs.

La vérification de routage est le seul blocage exclu du contrôle de **complétude** ;
elle reste présente dans le diagnostic d’émission. Tout autre refus annule la
finalisation, le numéro et les captures SQL. Comme pour les logos, un rollback
peut laisser une archive privée orpheline ; aucun fichier potentiellement partagé
n’est supprimé automatiquement.

Le nom acheteur est projeté exclusivement depuis l’instantané : le builder garde
la priorité au nom d’entreprise ; à défaut il utilise le nom complet historique,
ou les prénom/nom historiques disponibles, sans texte de remplacement ni profil actuel.

## Déploiement

La migration est préparée, pas appliquée à la base habituelle ou à la production.
Les tests l’exécutent uniquement dans SQLite en mémoire. L’application de cette
migration est nécessaire avant le déploiement du code. Aucun backfill n’est prévu.
La concurrence MariaDB n’est pas démontrée par SQLite ; l’ordre de verrouillage
existant de finalisation est conservé et les fiches sont relues sous verrou.

## Fichiers de cette étape

- Contrôleurs : `app/Http/Controllers/CompanySettingController.php`,
  `ClientController.php`, `InvoiceController.php`.
- Modèles : `app/Models/Company.php`, `Client.php`, `Invoice.php`.
- Validation : `app/Rules/IsoCountryCode.php`.
- Capture et projection : `app/Services/InvoicePartySnapshotCapture.php`,
  `InvoiceElectronicPartyData.php`, `InvoiceExportDataBuilder.php`.
- Préparation SUPER PDP : `app/Services/SuperPdp/DeclaredBillingAddress.php`,
  `InvoiceEmissionContext.php`, `InvoiceEmissionPreparation.php`,
  `SuperPdpEmissionEligibilityDiagnostic.php`, `SuperPdpInvoicePayloadBuilder.php`.
- Formulaires : `resources/views/company/edit.blade.php`, `clients/_form.blade.php`,
  `invoices/_form.blade.php`, `components/billing-electronic-address.blade.php`.
- Migration : `database/migrations/2026_11_09_000001_add_invoice_emission_preparation_fields.php`.
- Tests : `tests/Feature/BillingIdentitySettingsTest.php`,
  `tests/Feature/InvoiceEmissionPreparationTest.php`,
  `tests/Unit/Services/SuperPdp/InvoiceEmissionContextTest.php`,
  `tests/Unit/Services/InvoiceElectronicPartyDataTest.php`.
- Documentation : ce fichier.

Les modifications préexistantes de `ProfileController`, `QuoteController` et leurs
tests ne font pas partie de cette étape.

## Vérification locale

- Tests ciblés (préparation, paramètres, projection, instantanés, builder,
  diagnostic, mentions et quatre références CII) : **1 183 réussis,
  5 492 assertions**.
- Suite complète `php vendor/bin/pest --compact` : **2 600 réussis,
  14 799 assertions**, 96,01 secondes.
- Exécution de tests sur SQLite `:memory:` ; aucune migration sur base persistante,
  aucun appel réel à SUPER PDP. Les tests des nouveaux parcours interdisent
  les requêtes HTTP non simulées et vérifient qu’aucune requête n’a été envoyée.
- `git diff --check` : aucune erreur d’espacement ; avertissements de conversion
  CRLF/LF uniquement. Les références CII, schémas, routes, PDF et OAuth réception
  n’ont pas été modifiés.
