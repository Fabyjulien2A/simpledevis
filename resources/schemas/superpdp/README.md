# Contrat local SUPER PDP

- Source officielle : https://api.superpdp.tech/openapi/superpdp.json
- Copie exacte : `1.39.0.beta/openapi.json`, récupérée le 6 novembre 2026.
- Version API : **1.39.0.beta** ; OpenAPI **3.0.4**.
- SHA-256 des octets de la source : `e071e043eee9d26ea667eb577d75de25fc9eb4641d0dd2c4516f2d0f874230e9`.
- Racine de conversion JSON : `#/components/schemas/en_invoice`.
- Dérivé : `1.39.0.beta/en-invoice.schema.json`, JSON Schema draft-07, politique locale `strict-v1`.

La dérivation conserve toutes les propriétés, listes `required`, types, enums,
formats et contraintes des 50 composants accessibles depuis `en_invoice`.
Les références deviennent internes (`#/definitions/...`). Les mots-clés OpenAPI
non pris en charge entraînent un échec de génération, pas une suppression silencieuse.

Deux renforcements **locaux**, absents des contraintes OpenAPI originales :

1. `additionalProperties: false` sur les objets pour détecter les noms inconnus,
   notamment les fautes sur les propriétés fiscales facultatives.
2. Un `pattern` pour `string/decimal` : chiffres avec point décimal facultatif et
   signe moins facultatif, sans virgule, exposant, espaces, signe plus ou saut de ligne.
   Aucune précision ou borne numérique n'est ajoutée au contrat structurel.

Les propriétés facultatives ne deviennent pas obligatoires. Les objets et tableaux
vides sont distingués. Les dates sont validées par le validateur JSON Schema.
Le format OpenAPI `byte`, hors payload produit actuellement, reste une annotation.

Reproduire ou vérifier, **sans réseau**, depuis la racine du dépôt :

```sh
php tools/build-superpdp-schema.php
php tools/build-superpdp-schema.php --check
```

Le validateur applicatif lit exclusivement le dérivé versionné. Son chargeur n'a
aucun résolveur externe ; il ne télécharge ni références ni métaschémas.
Le champ `$schema` identifie un dialecte, sans provoquer de requête HTTP.

Cette validation prouve uniquement le respect de ce contrat structurel local.
Elle ne certifie ni EN 16931, ni la validité fiscale, ni un profil français,
ni la possibilité de routage ou l'acceptation par SUPER PDP. Les contrôles métier
du builder et de `InvoiceFiscalDataPreparer` restent distincts et obligatoires.
Les catégories S/E/Z, unités, taux, motifs et égalités monétaires continuent à
être contrôlés par ces services. La liste VATEX n'est pas validée exhaustivement.

Une mise à jour exige une nouvelle copie officielle, sa version et son empreinte,
la revue des différences et de la dérivation, puis l'exécution des tests. Aucune
mise à jour automatique du schéma ne se produit lors de la construction d'une facture.
