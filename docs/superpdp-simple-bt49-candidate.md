# Candidat BT-49 Peppol français simple

`FrenchPeppolBt49Candidate::fromIdentifier($identifier, $expectedSiren)` est un
composant PHP pur et immuable. Il n’est raccordé à aucun formulaire, modèle,
adaptateur d’annuaire, builder EN16931 ou diagnostic.

Il accepte uniquement une chaîne `0225:` suivie de neuf chiffres ASCII et un
SIREN destinataire attendu, fourni explicitement sous forme de chaîne de neuf
chiffres. Les deux SIREN doivent être strictement identiques.

Exemple fictif :

```php
$candidate = FrenchPeppolBt49Candidate::fromIdentifier('0225:001234567', '001234567');
// originalIdentifier : '0225:001234567'
// scheme             : '0225'
// value              : '001234567'
```

Aucun espace n’est retiré, aucun préfixe n’est ajouté, aucune conversion
numérique ni normalisation n’est réalisée. L’identifiant original est conservé.
Les entrées non textuelles, préfixes différents, caractères et séparateurs
supplémentaires sont refusés par `InvalidArgumentException`. Les identifiants
français sans préfixe et toutes les formes composées sont hors périmètre.
Toute présence de `_replyto`, sans distinction de casse, est refusée avec un
message explicite. Ce refus conservateur n’est pas une grammaire générale des
adresses techniques : aucune forme suffixée n’est acceptée dans cette étape.

Le contrat EN16931 local définit `electronic_address.scheme` et `.value`
(`resources/schemas/superpdp/1.39.0.beta/en-invoice.schema.json`,
`definitions.electronic_address`). L’OpenAPI locale décrit les identifiants
Peppol sous la forme `scheme_id:participant_id` et l’exemple `0225:853322915`
(`components.schemas.create_directory_entry.properties.identifier` et
`components.schemas.french_directory_entry.properties.identifier`). L’extrait
SUPER PDP fourni par l’utilisateur confirme le Scheme ID français `0225` et
le Participant ID comme adresse française de facturation. Seule la forme
simple est prise en charge ici.

Le résultat est un **couple candidat BT-49**, pas une destination vérifiée.
Aucun contrôle de clé SIREN, d’existence, d’inscription, d’activité, de format
accepté ou de joignabilité n’est réalisé. Les neuf chiffres, y compris des
valeurs entièrement fictives, ne prouvent aucune identité réelle.
Le composant ne choisit aucune ligne et ne concerne pas BT-34.
`routing.verification_unavailable` reste inchangé.

Les formes SIREN_SIRET, SIREN_SUFFIXE et SIREN_SIRET_CODEROUTAGE, leurs limites
et normalisations, la sélection de destination et le choix de l’adresse de
retour nécessitent des travaux distincts.

Vérifications : 79 nouveaux cas unitaires PHP purs ; 265 tests ciblés réussis
avec l’adaptateur d’annuaire et le diagnostic (1 219 assertions) ; suite PHP
complète réussie, 1 995 tests et 10 614 assertions. Pint appliqué et vérifié
sur les deux nouveaux fichiers PHP ; `git diff --check` réussi. Les quatre
empreintes CII sont intactes. Le lanceur temporaire de la suite interdit les
transports réseau, les pilotes SQL distants et toute base SQLite persistante,
puis est retiré. Aucun raccordement applicatif, migration ou émission.
