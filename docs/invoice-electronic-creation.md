# Nouvelle facture et données électroniques figées

Le profil `fr_b2b_380_eur` réutilise les données des fiches entreprise et client.
Les formulaires disposent déjà des champs nécessaires : identité, SIRET, pays,
adresse postale, TVA, adresses BT-34/BT-49 ; côté vendeur, régime de TVA, conditions
de paiement et mentions PMT/PMD/AAB. Le champ vendeur `tva_number` est normalisé
et enregistré également dans `vat_number` par le contrôleur existant. Aucune
nouvelle colonne ni aucun identifiant fictif n'est ajouté.

## Choix du profil

Le récapitulatif local est consultable avant de choisir un profil électronique.
Il expose les données manquantes, les incompatibilités et les informations qui
seront figées. Le numéro de TVA déclaré du client y est maintenant visible.
Il ne constitue ni une vérification des identifiants ni une preuve de routage.

`InvoiceFinalizationSummary` calcule `profileCompatible` depuis les blocages du
diagnostic partagé : cas `unsupported`, pays vendeur/acheteur ou type de client
manquants. Les règles France/B2B ne sont pas recopiées dans JavaScript. Les autres
manques documentaires peuvent encore être complétés sur un brouillon ; ils restent
bloquants à la finalisation électronique via `InvoiceEmissionPreparation`.

Le navigateur attend ce résultat pour proposer le profil. Une sélection devenue
incompatible est signalée comme invalide et conservée jusqu'au choix explicite de
l'utilisateur : aucune conversion silencieuse en facture classique. Une erreur
de récapitulatif n'autorise pas le profil. Les réponses annulées ou périmées ne
remplacent pas le dernier résultat.

La création et la modification de brouillons vérifient aussi cette compatibilité
côté serveur. Le client est relu dans les clients du propriétaire et les données
vendeur proviennent de son entreprise. Les valeurs HTTP des parties, montants ou
du routage ne peuvent pas remplacer ces sources. Une sélection incompatible ne
crée pas de facture et ne remplace pas les lignes d'un brouillon existant.

« Sans qualification électronique » reste disponible pour créer une facture PDF
classique, y compris pour un particulier, un client étranger ou une entreprise
sans données électroniques complètes. Les contrôles PDF et de calcul existants
restent appliqués. L'absence de JavaScript ne contourne pas les contrôles serveur.

## Finalisation et immutabilité

Les deux parcours existants (création définitive directe et finalisation d'un
brouillon) capturent les parties avec `InvoicePartySnapshotCapture`, dans la
transaction de finalisation, sous verrous. Le contrat existant est conservé :

- `party_snapshot` contient les identités vendeur/acheteur, pays, adresses,
  SIRET, TVA, régime vendeur, mentions et conditions de paiement.
- `emission_context` conserve les adresses électroniques validées, le profil,
  le type 380, la devise EUR et `routing_status=not_verified`.
- La facture conserve son numéro serveur, ses dates et son cadre B1/S1/M1 ;
  ses lignes et montants décimaux sont contrôlés avant finalisation.

Une finalisation électronique incomplète ou devenue incompatible annule toute
la transaction : ni instantané définitif ni consommation de numéro. Après
finalisation, les fiches actuelles ne remplacent pas les données historiques.
Aucune facture définitive n'est enrichie rétroactivement.

Les tests passent par les formulaires entreprise/client et les deux parcours de
finalisation, puis modifient les seules fiches de test pour vérifier que la source
électronique et la facture restent identiques. Ils utilisent SQLite `:memory:`.
Les tests JavaScript utilisent des réponses de récapitulatif simulées.

## Ce qui reste distinct

Un document localement complet n'est pas une facture autorisée pour préparation
distante : OAuth sandbox valide, qualification vendeur `fr_siren` correspondant
au SIREN figé, session vendeur, destination réelle, preuves récentes et autorisation
serveur bornée restent nécessaires. Le CII de référence documentaire ne remplace
aucun de ces contrôles. Les configurations CII/émission ne sont pas activées ici.
