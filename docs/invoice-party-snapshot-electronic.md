# Lecture des parties historiques pour la préparation électronique

## Validation et provenance

`InvoiceExportDataBuilder::buildElectronic()` relit la facture persistée puis
examine le JSON brut `party_snapshot`, sans utiliser le cast array Eloquent.
`InvoicePartySnapshot::fromJson()` contrôle :

- Une enveloppe JSON objet avec une version entière exactement prise en charge (1).
- Les quatre clés du contrat : version, vendeur, acheteur et qualification électronique.
- Des objets pour les deux parties, y compris lorsqu'ils sont vides.
- Uniquement les champs du contrat, avec des chaînes UTF-8 ou NULL.
- La qualification fixe `status: not_assessed`, sans propriété supplémentaire.
- L'absence de clés dupliquées, y compris après décodage des échappements Unicode.

L'ordre des clés JSON n'est pas significatif. Les champs absents et les NULL
restent distincts. Aucune validation de conformité ou de routage n'est attestée
par cette lecture ; un instantané structurellement valide peut être incomplet.

Avec un instantané valide, aucune relation Company, Client ou User n'est lue
pour préparer les parties. Le propriétaire utilisé est celui de la facture relue,
indépendamment des attributs locaux ou de l'utilisateur actuellement connecté.
L'autorisation d'accès reste à la charge des contrôleurs.

## Projection partagée

`InvoiceElectronicPartyData::resolve()` est une projection PHP pure, réutilisée
par la source, le diagnostic et le builder. La présence d'un instantané entraîne
le remplacement complet des projections vendeur, acheteur, régime et mentions.
Des tableaux de parties fournis manuellement ne peuvent pas le compléter.

La raison sociale vendeur `company_name`, lorsqu'elle est capturée, est mappée
sur le champ EN16931 `name`, même si elle vaut NULL. Sinon un `name` explicitement
capturé reste accepté ; aucun nom User de repli n'est ajouté. Aucun `full_name`
n'est calculé depuis les prénom/nom acheteur. Aucune TVA n'est reprise depuis
l'autre alias, aucun pays FR ni texte PDF n'est ajouté.

Les clés internes `company_vat_regime` et `company_invoice_mentions` sont conservées
pour compatibilité mais désignent les valeurs de l'instantané. Les mentions PMT,
PMD et AAB sont contrôlées contre cette source historique dans le diagnostic et
dans `FrenchInvoiceContext`, y compris lors d'un appel direct au builder.

`FrenchInvoiceTaxRegime` partage les contrôles fiscaux préexistants entre le
diagnostic et le builder pour les données historisées : régime pris en charge,
présence/forme de TVA, cohérence des lignes de franchise et BT-32. Le diagnostic
refuse également les contextes manuels contradictoires.

Le mapping BT-32 existant peut utiliser le SIREN du SIRET historique pour une
franchise explicitement capturée, seulement si les deux alias TVA ont été
capturés comme absents ou blancs. Un alias non capturé ne prouve pas son absence.
Un contexte fiscal manuel ne peut pas combler un BT-32 historique non préparé.
Ce mapping ne crée aucune adresse BT-34/BT-49 et n'atteste aucune identité distante.

## Absence, corruption et scénarios synthétiques

Les erreurs partagées sont :

| Code | Catégorie | Comportement |
| --- | --- | --- |
| `invoice.party_snapshot.missing` | `missing_data` | Lecture des profils maintenue pour les historiques ; préparation persistée refusée |
| `invoice.party_snapshot.invalid` | `invalid_data` | Arrêt sans repli vers les profils |
| `invoice.party_snapshot.unsupported_version` | `unsupported` | Arrêt sans interprétation d'une autre version |

Un SQL NULL est une absence ; le JSON littéral `null` est une structure invalide.
Aucune lecture ne remplit ou répare l'instantané, et aucune erreur n'en expose
le contenu brut. Le diagnostic collecte toujours le blocage de routage. Les
contrôles historiques restants continuent en cas d'absence, mais la validation
finale du payload n'est pas appelée lorsque les contrôles locaux sont bloqués.

Le builder exige l'instantané pour `build()` sur une facture persistée. Le
diagnostic exige également la métadonnée fournie par la source persistée.
`buildFromData()` conserve les scénarios synthétiques explicitement fournis sans
métadonnée `party_snapshot` ; ce contrat ne prouve pas une provenance persistée.
Si la clé existe, même NULL, la validation de l'instantané s'applique aussi à ce
parcours. Les quatre scénarios CII conservent leur contexte et leurs références.

## Limites et périmètre

Les adresses électroniques restent des arguments explicites, indépendants de
l'instantané et sans déduction. `routing.verification_unavailable` reste inchangé.
Les informations documentaires, lignes, totaux, paiements, avoirs et cadre B1/S1/M1
continuent à être relus sur la facture et ses relations métier.

Le contrat de capture actuel ne fournit pas de pays vendeur implicite : un
instantané sans ce pays reste bloqué même si le lecteur historique utilisait FR.
Modifier les fiches courantes ne complète jamais un instantané incomplet ; une
éventuelle politique de traitement de ces documents nécessite une étape distincte.

`build()` historique, les PDF, calculs, formulaires, finalisation, schéma, réception
SUPER PDP et routage ne changent pas. Les tests utilisent exclusivement des données
fictives et SQLite en mémoire, avec transports réseau et connexions distantes bloqués.
