# Mentions françaises dans les paramètres entreprise

Les paramètres permettent la saisie et le stockage explicites des mentions.
Le diagnostic électronique utilise désormais ces valeurs persistées.

| Code | Colonne nullable dans `companies` | Libellé du formulaire |
| --- | --- | --- |
| PMT | `invoice_recovery_indemnity_text` | Indemnité forfaitaire pour frais de recouvrement |
| PMD | `invoice_late_payment_penalties_text` | Pénalités de retard |
| AAB | `invoice_early_payment_discount_text` | Escompte pour paiement anticipé |

Chaque champ accepte un texte de 2 000 caractères maximum, ou `null`. Les tableaux,
booléens, nombres et textes trop longs sont rejetés avant toute modification du
profil. Les champs restent facultatifs à cette étape, tant que l’émission
électronique n’est pas activée. Aucun mécanisme d’activation n’est ajouté ici.

Une clé absente de la requête conserve le texte enregistré. Un champ explicitement
vidé le remet à `null` via les middlewares Laravel habituels de suppression des
espaces périphériques et de conversion des chaînes vides. Les sauts de ligne
intérieurs et la ponctuation sont conservés. Une erreur de validation conserve
les saisies dans le formulaire sans enregistrer partiellement le profil.
La lecture et l’écriture passent exclusivement par l’entreprise de l’utilisateur
authentifié ; les identifiants d’entreprise ou d’utilisateur soumis sont ignorés.

## État examiné et limites de cette étape

Le PDF facture affiche actuellement `payment_terms`, avec une valeur de repli
« Paiement à réception de facture. », ainsi que trois mentions fixes pour le
recouvrement, les pénalités et l’escompte. Le PDF devis utilise également les
conditions de paiement du profil. Ces PDF restent inchangés.

`payment_terms` reste un champ général distinct : il n’est ni découpé, ni analysé,
ni remplacé par les nouveaux champs. Les notes libres des factures ne sont pas
utilisées pour les alimenter.

Le contrat actuel `FrenchInvoiceContext` exige un cadre B1, S1 ou M1 explicite et
une liste contenant une seule note non blanche pour chacun des codes PMT, PMD et
AAB. Il ne lit pas le profil et n’applique aucune valeur par défaut. Ce contrat
reste inchangé. `InvoiceExportDataBuilder::buildElectronic()` relit l’entreprise
du propriétaire persisté de la facture et expose `company_invoice_mentions`,
une métadonnée locale associant PMT, PMD et AAB aux textes enregistrés.
L’export historique ne contient pas cette métadonnée.

Le diagnostic construit la liste `subject_code` / `note` attendue lorsque les
notes ne sont pas fournies manuellement. Il transmet les textes persistés au
builder partagé pour sa dernière validation locale, sans retourner ni conserver
le payload temporaire. Le cadre B1/S1/M1 reste à fournir explicitement dans
`french_context.business_process_type` : il n’est déduit ni des mentions, ni des
lignes ou du régime de TVA.

Chaque mention absente ou blanche, y compris les espaces insécables, produit
`company.mentions.{PMT|PMD|AAB}.missing`, avec le champ du profil et un message
français précis. Un type invalide ou un texte dépassant 2 000 caractères produit
le code correspondant `.invalid`. Aucun contexte manuel ne peut combler un profil
incomplet. Si des notes manuelles sont présentes, leur structure complète reste
contrôlée par `FrenchInvoiceContext` ; une valeur différente du texte persisté
produit `.context_conflict`, sans modifier le profil. La comparaison est exacte,
y compris la ponctuation et les sauts de ligne. Un ordre différent des trois
notes cohérentes est accepté. Une liste manuelle vide ou malformée reste bloquante.

La présence des mentions ne rend pas une facture éligible : le routage reste
indisponible et les autres contrôles sont maintenus. Le builder direct conserve
son contrat de contexte explicite ; cette étape raccorde seulement le diagnostic.
Les PDF, calculs fiscaux, quatre XML CII de référence et scénarios SUPER PDP
restent inchangés.

La migration additive
`2026_11_07_000002_add_french_invoice_mentions_to_companies_table.php` ajoute trois
colonnes `text` nullables, sans valeur par défaut ni mise à jour des lignes.
Les entreprises historiques et les nouveaux profils restent à `null` jusqu’à une
saisie explicite. Aucun texte fictif des fixtures SUPER PDP, ni texte fixe du PDF,
n’est repris automatiquement ou proposé comme contenu du formulaire.

## Renseignée ne signifie pas juridiquement appropriée

Une mention renseignée signifie seulement qu’un utilisateur a enregistré un texte.
La validation technique contrôle son type et sa longueur, sans apprécier sa
conformité juridique, son taux, son montant ou son applicabilité à l’entreprise,
au client et à l’opération. Un texte non vide, y compris « Néant », ne constitue
pas une preuve de conformité. L’artisan doit déterminer les mentions adaptées à
sa situation et peut les faire vérifier par son conseil. Cet avertissement figure
également dans le formulaire. Aucun texte fictif n’est une recommandation juridique.

## Migrations distantes restant à appliquer

Aucune migration n’a été exécutée sur MariaDB Alwaysdata pendant cette étape.
Les migrations suivantes restent à appliquer avant d’utiliser les paramètres
correspondants en production, dans cet ordre :

1. `2026_11_07_000001_add_vat_regime_to_companies_table.php` (étape précédente).
2. `2026_11_07_000002_add_french_invoice_mentions_to_companies_table.php` (cette étape).

Cette liste reprend l’attente documentée localement, sans interrogation distante
de la table des migrations. Aucun appel réseau ni émission n’est effectué.

## Vérifications de la saisie initiale

Les tests utilisent exclusivement SQLite en mémoire, avec `DB_URL` vide et sans
configuration en cache. Un bootstrap temporaire refuse les connexions SQL non
SQLite et les bases SQLite persistantes ; les transports réseau sont bloqués.
Les réponses HTTP simulées des tests existants restent utilisables.

Résultats du 7 novembre 2026 :

- 44 nouveaux tests ; 108 tests ciblés réussis, 785 assertions.
- Suite PHP complète : 1 675 tests réussis, 8 501 assertions, 53,64 s.
- Pint appliqué puis `--test` réussi sur les quatre fichiers PHP concernés.
- `git diff --check` réussi ; empreintes du contexte français, de l’export,
  du diagnostic, des deux modèles PDF et des quatre XML CII inchangées.
- Bootstrap temporaire et fichiers de vérification supprimés après les contrôles.

## Vérifications du raccordement au diagnostic

Résultats du 7 novembre 2026 :

- 50 nouveaux tests positifs et négatifs, dont l’isolation des propriétaires,
  les relations périmées, les espaces Unicode et les conflits explicites.
- Tests ciblés : 247 réussis, 1 207 assertions.
- Suite PHP complète : 1 725 réussis, 8 881 assertions, 55,02 s.
- Pint réussi sur les cinq fichiers PHP concernés ; `git diff --check` réussi.
- Tests exclusivement sous SQLite en mémoire, SQL distant et transports réseau
  bloqués par le bootstrap temporaire et les options PHP. Aucun appel ni émission.
- Contrat `FrenchInvoiceContext`, builder de payload, préparateur fiscal, PDF,
  migrations et quatre XML CII inchangés, empreintes vérifiées.
- `.task-mentions-bootstrap.php` était déjà absent au début de cette étape.
  Le bootstrap temporaire propre à cette vérification et ses fichiers de contrôle
  ont été retirés après les tests, sans nettoyage d’autres fichiers.

Limites maintenues : cadre, qualifications du document et adresses électroniques
restent explicites ; routage non vérifié ; aucune appréciation juridique des
mentions. Les deux migrations distantes listées plus haut restent à appliquer.
