# Contrat de capture des parties d'une facture — version 1

La version 1 ci-dessous reste lisible sans réécriture. Les nouvelles
finalisations utilisent la [version 2 avec logos privés](invoice-logo-archive.md).

`App\Services\InvoicePartySnapshot::capture(array $seller, array $buyer)` est
un composant PHP pur et immuable. Il reçoit uniquement des valeurs explicites,
sans modèle Laravel, base, fichier, horloge, session, cache ou réseau. Il ne
prouve pas leur provenance ni leur capture effective lors d'une finalisation.
La capture transactionnelle de finalisation l'utilise désormais. La préparation
électronique valide et consomme les instantanés ; les PDF restent indépendants.
Voir [le contrat de lecture électronique](invoice-party-snapshot-electronic.md).

## Structure

```json
{
  "schema_version": 1,
  "seller": {
    "company_name": "Entreprise fictive",
    "siret": "00123456700012",
    "vat_number": null
  },
  "buyer": {},
  "electronic_qualification": {
    "status": "not_assessed"
  }
}
```

Les clés d'enveloppe sont fixes. `schema_version` est l'entier 1, défini par le
composant, pas une entrée modifiable. Le statut `not_assessed` est une métadonnée
du contrat : aucune qualification électronique n'a été effectuée. Ce n'est ni
un refus métier ni une attestation d'éligibilité.

`toArray()` retourne les parties sous forme de tableaux associatifs PHP.
Pour sérialiser le contrat JSON, utiliser `json_encode($snapshot,
JSON_THROW_ON_ERROR)` : `JsonSerializable` garantit des objets pour les parties,
même vides. Encoder directement `toArray()` ne garantit pas cette distinction
objet/liste pour une partie vide.

## Champs autorisés

Chaque champ est facultatif et accepte exclusivement une chaîne UTF-8 ou `null`.
Les champs inconnus et les types incompatibles sont refusés, sans conversion ni
suppression silencieuse. Les erreurs n'incluent pas les valeurs fournies.

Vendeur :

- Identité : `company_name`, `name`, `user_name`, `legal_status`.
- Fiscalité : `siret`, `vat_number`, `tva_number`, `vat_regime`.
- Coordonnées : `address`, `postal_code`, `city`, `country_code`, `email`, `phone`.
- Paiement : `iban`, `bic`, `payment_terms`.
- Mentions : `invoice_recovery_indemnity_text` (PMT),
  `invoice_late_payment_penalties_text` (PMD),
  `invoice_early_payment_discount_text` (AAB).
- Présentation PDF : `logo`, référence textuelle uniquement.

Acheteur :

- Identité : `company_name`, `first_name`, `last_name`, `full_name`, `client_type`.
- Fiscalité : `siret`, `vat_number`.
- Coordonnées : `address`, `postal_code`, `city`, `country_code`, `email`, `phone`.

`name`, `user_name` et `full_name` ne sont conservés que s'ils sont explicitement
fournis. Le composant ne calcule ni nom complet ni nom de repli. Il n'interroge
pas les accesseurs Eloquent. Les alias TVA sont conservés séparément même s'ils
divergent. Le pays vendeur n'est pas complété par `FR`.

## Fidélité et déterminisme

Une clé absente reste absente ; une clé présente avec `null` reste présente.
Les chaînes vides, espaces, ponctuation, casse, accents, zéros initiaux et sauts
de ligne sont conservés exactement. Aucun texte légal, régime, identifiant ou
code n'est inféré. Les clés suivent un ordre fixe, indépendant de l'ordre des
entrées ; des entrées identiques donnent les mêmes données et le même JSON.
Le composant ne remplit aucune date avec l'horloge courante.

La capture vérifie la forme du contrat, pas la validité métier des déclarations.
Elle accepte des textes incomplets ou fiscalement incohérents ; ils devront
rester distingués d'une préparation validée. Les contraintes de longueur et les
validations fiscales restent du ressort des futurs consommateurs.

## Informations non qualifiées et limites

Ni BT-34, ni BT-49, ni ligne d'annuaire, ni adresse `_replyto`, ni état d'activité
ou de routage ne sont capturés. Fournir de tels champs est refusé. Aucun SIREN,
BT-32 ou numéro de TVA n'est calculé depuis le SIRET ou les alias TVA. L'e-mail
de contact reste une donnée de contact, sans devenir une adresse de facturation.

Ce contrat ne contient pas les lignes, montants, dates documentaires, cadre
B1/S1/M1 ou règlements de la facture. Il ne remplace pas le futur instantané
complet du document ni le payload EN16931. Nature, type et devise électroniques
ne sont pas qualifiés par cette capture des seules parties.

La référence `logo` ne fige ni l'image ni ses octets. Le contrat conserve les
mentions fournies, sans copier les mentions fixes ou valeurs de repli du PDF.
Il ne garantit donc ni un PDF historique identique, ni un archivage documentaire.

La date de capture, les protections de suppression et le raccordement des PDF
restent à développer séparément.
Une évolution des champs ou de leur sens devra faire évoluer explicitement la
version du contrat. Aucun mécanisme de lecture d'anciennes versions n'est ajouté.

Les PDF, la réception SUPER PDP et quatre références CII ne sont pas modifiés.
Le builder et le diagnostic utilisent désormais la lecture historique validée. Le blocage
`routing.verification_unavailable` reste inchangé.

## Stockage et capture transactionnelle — migration préparée uniquement

La migration `2026_11_08_000001_add_party_snapshot_to_invoices_table.php` prépare
une colonne JSON nullable `invoices.party_snapshot`, sans remplissage historique.
Elle n'est pas à exécuter sans autorisation distincte. Son `down()` supprime la
colonne et détruirait tous les instantanés enregistrés : ce n'est pas une méthode
de retour arrière préservant ces données.

`InvoicePartySnapshotCapture` relit la facture sous verrou, puis l'entreprise du
propriétaire et le client de ce même propriétaire, sur la même connexion.
La capture exige une transaction déjà ouverte et un brouillon. Elle est appelée
avant le passage à `issued`, dans les transactions des deux parcours : création
directement définitive et finalisation d'un brouillon. Un échec ultérieur annule
également l'instantané, l'attribution du numéro et les autres écritures associées.
La protection du cadre B1/S1/M1 et les contrôles fiscaux sont conservés.

Seuls les attributs bruts persistés figurant dans le contrat sont copiés, sans
accesseur ni donnée de requête HTTP. Une colonne nullable présente est capturée
avec sa valeur, y compris `null` ; une colonne absente reste absente. Les noms
User de repli, `Client::full_name`, le pays vendeur `FR` et les alias TVA ne sont
pas calculés ou rapprochés. Une entreprise absente produit `seller: {}`.
Les valeurs incomplètes ne sont pas qualifiées ou complétées ; un type incompatible
avec le contrat fait échouer la capture et donc la finalisation atomique.

Le JSON est produit par `jsonSerialize()` afin de conserver les parties vides
comme objets. Le cast Eloquent `array` permet sa consultation, mais une partie
vide est alors un tableau PHP vide ; les octets JSON persistés gardent `{}`.

Un instantané non NULL n'est jamais remplacé lors d'une capture. Un garde Eloquent
refuse son remplacement ou son effacement, y compris sur une instance périmée,
et interdit de remplir après coup une facture déjà définitive sans instantané.
Le champ n'est pas assignable en masse. Les règlements et statuts peuvent évoluer
sans modifier l'instantané. Ces gardes ne protègent pas contre les écritures SQL
directes, les mises à jour de masse ou les sauvegardes sans événements.

Les factures déjà définitives restent NULL. Les brouillons existants reçoivent
un instantané s'ils sont finalisés après déploiement. La duplication et la
conversion d'un devis créent un brouillon sans instantané. Les lecteurs électroniques
utilisent l'instantané lorsqu'il est valide et bloquent son absence. Les PDF
continuent à utiliser leurs sources précédentes : leur stabilité après changement
de profil n'est pas garantie par cette étape.

Déploiement : préparer et autoriser la modification de schéma avant d'activer le
code de capture sur une base réelle. Sans la colonne, les nouvelles finalisations
échoueront et leur transaction sera annulée. Les verrous/concurrences MariaDB
devront être vérifiés séparément ; les tests applicatifs sont limités à SQLite
en mémoire et ne valident pas le DDL MariaDB ni son temps de verrouillage.
