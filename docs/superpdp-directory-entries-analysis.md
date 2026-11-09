# Analyse pure des lignes d’annuaire françaises

`FrenchDirectoryEntriesAnalysis::analyze($expectedSiren, $lines, $pagination)`
analyse des lignes déjà récupérées. Aucun transport, SDK, modèle, stockage,
sélection de destination ou contrôle de routage n’est utilisé.
Le POST de consultation client l’utilise désormais après récupération des lignes,
sans raccordement au builder ou au diagnostic. Voir
[la consultation client](superpdp-client-directory-consultation.md).

## Entrée et conservation

`FrenchDirectoryConsultationService::listEntries()` retourne `data`, liste
de lignes `company`, `identifier`, `is_active`, et éventuellement `has_more`.
L’appelant fournit explicitement cette liste et ses métadonnées, par exemple :

```php
// $response contient des données déjà récupérées ; cet exemple ne consulte rien.
$analysis = (new FrenchDirectoryEntriesAnalysis)->analyze(
    '001234567',
    $response['data'],
    array_diff_key($response, ['data' => true]),
);
```

Le SIREN attendu doit être une chaîne de neuf chiffres ASCII. La collection
doit être une liste ordonnée ; `has_more`, lorsqu’il est fourni, doit être
booléen. Une entrée globale invalide provoque `InvalidArgumentException`.

Le résultat contient uniquement `lines` et `pagination`. Chaque ligne possède
`source_index`, `line` (donnée originale intégrale, dont identifiant brut et
activité), `classification`, `identifier_status`, `reason`, `bt49_candidate`.
L’ordre global, les doublons et les textes sont conservés, sans normalisation.
La pagination est conservée telle quelle, y compris l’absence de `has_more`.
Les métadonnées supplémentaires restent opaques ; aucun curseur n’est suivi.

## Classement

| Classification | Sens local |
| --- | --- |
| `active_candidate` | Ligne active, identité cohérente et identifiant simple accepté par `FrenchPeppolBt49Candidate` |
| `inactive` | Ligne inactive avec identité et activité structurées ; le diagnostic de l’identifiant reste disponible |
| `unsupported_format` | Ligne active dont la famille de format est hors du périmètre simple |
| `invalid_identifier` | Ligne mal structurée, identité incohérente ou identifiant actif invalide/technique |

`identifier_status` distingue `compatible`, `unsupported` et `invalid`.
`reason` précise notamment `malformed_line`, `invalid_company_siren`,
`company_siren_mismatch`, `malformed_identifier`, `identifier_siren_mismatch`,
`technical_reply_address`, `composed_format`, `missing_peppol_prefix` et
`other_peppol_scheme`. Un identifiant simple cohérent porte
`simple_peppol_identifier`.

La structure essentielle (`is_active` booléen et `company.number` cohérent)
est contrôlée avant le classement d’activité. Une identité de société invalide
reste donc une erreur même sur une ligne inactive. Pour une identité cohérente,
une ligne inactive reste `inactive` même si son identifiant est invalide ;
`identifier_status` et `reason` conservent cette seconde information.
Les autres coordonnées d’entreprise sont conservées depuis la source normalisée,
sans nouvelle qualification d’identité ou de routage.

Seul `0225:SIREN` simple est transmis à `FrenchPeppolBt49Candidate`, avec le
SIREN attendu explicite. Le candidat n’est exposé que pour une ligne active
compatible ; il est nul pour les autres classements. La seule présence d’un
candidat, même unique, ne constitue aucune sélection.

Les formes composées françaises, préfixées ou non, sont reconnues comme
familles hors périmètre. Leur reconnaissance ne certifie ni la grammaire des
suffixes ni leur validité métier. Le SIREN initial doit néanmoins correspondre
au destinataire attendu. Les autres préfixes numériques Peppol reconnus sont
également hors périmètre, sans validation de leur Participant ID.
Les formes `_replyto` sont refusées sans distinction de casse, avant le
classement des formats composés. Une ligne non prise en charge ne signifie
jamais que sa destination est inexistante.

## Limites conservées

Aucun candidat n’est fabriqué à partir du seul SIREN lorsque la liste est vide.
Une liste vide ne prouve pas l’inexistence d’une destination ; `has_more=true`
reste visible et aucune exhaustivité supplémentaire n’est supposée.
`is_active=true` ne prouve ni joignabilité, ni compatibilité de document,
ni succès de transmission. Le résultat ne comporte aucun état sélectionné,
éligible ou routage vérifié. `routing.verification_unavailable` reste inchangé.
BT-34 et la sélection/conservation d’une destination demeurent hors périmètre.

Les tests sont PHP purs, sans démarrage Laravel, SQL ou réseau. Ils couvrent
les lignes actives, inactives, multiples, incohérentes, mal formées, composées,
techniques, les listes vides et la conservation de la pagination.

Vérifications : 86 nouveaux cas unitaires ; 351 tests ciblés réussis avec les
composants candidat, consultation et diagnostic (1 540 assertions) ; suite PHP
complète réussie, 2 081 tests et 10 935 assertions. Pint appliqué puis vérifié
sur les deux nouveaux fichiers PHP ; `git diff --check` réussi. Les empreintes
des quatre références CII sont intactes. Le lanceur temporaire de la suite
bloque les transports réseau, les pilotes SQL distants et les bases SQLite
persistantes, puis est retiré. Aucune migration ni émission n’est effectuée.
