# Préparation documentaire locale depuis une facture définitive

La page **Préparer l’envoi électronique** affiche une préparation en mémoire
des données qui serviront au futur CII. Aucun document XML n'est produit, aucun
appel API ni archivage n'est effectué et aucune écriture SQL n'est nécessaire.

`SuperPdpEmissionEligibilityDiagnostic::prepareLocal()` contrôle l'identité
authentifiée, relit la facture et son propriétaire, refuse les brouillons et
réutilise les mêmes contrôles que `diagnose()`. Le payload déjà construit par
`SuperPdpInvoicePayloadBuilder::buildFromData()` est conservé en mémoire après
validation locale. Il n'y a pas de second builder, de calcul fiscal parallèle,
de substitution des fiches actuelles aux instantanés ou de données sandbox.
Les anciennes factures incomplètes restent bloquées ; aucun payload complet
ni empreinte n'est présenté dans ce cas. Le diagnostic détaillé existant
conserve les motifs d'absence, d'incohérence et de périmètre non pris en charge.

## Représentation et empreinte

La représentation structurée comprend une version locale (`schema_version=1`),
le propriétaire, l'identifiant de facture, le profil et la méthode de calcul,
puis les données EN16931 du builder partagé : numéro, dates, émetteur,
destinataire, adresses déclarées, mentions, lignes, ventilation TVA et totaux.

L'empreinte SHA-256 utilise la sérialisation JSON déterministe existante de
`CiiPreparationEvidence::hash()`. L'ordre de construction du builder et l'ordre
des lignes persistées sont conservés. Les décimaux restent des chaînes ; aucun
recalcul en nombres flottants n'est effectué pour l'affichage ou l'empreinte.
Les horodatages de consultation et les preuves de routage ne sont pas inclus.
Une modification des fiches actuelles ou de l'ordre des clés JSON de
l'instantané ne modifie pas les données normalisées ni leur empreinte.

Cette empreinte identifie **les données préparées et leur rattachement à la
facture**, pas un fichier CII ni une validation réglementaire. Elle n'est pas
enregistrée en base ou sur disque.

## Affichage et limites

La rubrique présente le résultat documentaire local, les données essentielles
dans un détail dépliable, les lignes préparées et l'empreinte. En cas de
blocage, un lien mène aux motifs du diagnostic détaillé de la même page ;
les données figées consultables restent dans les rubriques existantes.

Trois états restent affichés séparément et sans condition : **CII non produit**,
**Validation non effectuée**, **Envoi bloqué**. Les contrôles documentaires
locaux réussis n'attestent ni une conversion CII, ni une validation complète,
ni une qualification de routage ou une autorisation d'émission.

Le XML interne SimpleDevis, les preuves de routage et les scénarios
Burger Queen → Tricatel restent séparés et inchangés.
