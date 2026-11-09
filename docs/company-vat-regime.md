# Régime de TVA déclaré dans les paramètres entreprise

La migration additive `2026_11_07_000001_add_vat_regime_to_companies_table.php`
ajoute `companies.vat_regime`, nullable et limité à `subject_to_vat` et `franchise`.
Elle n’attribue aucun régime aux entreprises existantes et ne modifie aucune
autre donnée. Les profils existants restent à `null`.

Le formulaire propose « Non renseigné », « Entreprise redevable de la TVA » et
« Franchise en base de TVA (déclarée par l’entreprise) ». Le contrôleur accepte
seulement ces valeurs ou une absence de sélection. Une clé absente conserve la
déclaration existante ; une sélection vide la remet à `null`.

Le régime n’est déduit ni du numéro de TVA ni des factures. Choisir la franchise
ne supprime pas le numéro enregistré. Si les deux informations sont présentes,
une note distincte invite à vérifier leur cohérence sans bloquer l’enregistrement
ni affirmer qu’elles seraient nécessairement incompatibles.

Le diagnostic relit maintenant le régime enregistré via la métadonnée locale
`company_vat_regime` de `InvoiceExportDataBuilder::buildElectronic()`.
Un contexte manuel contradictoire reste bloquant et ne remplace pas le profil.
Cette métadonnée n’ajoute aucun champ au payload EN16931. Les calculs fiscaux,
PDF et quatre références CII restent inchangés.

## Application distante en attente

**La migration n’a pas été exécutée sur MariaDB Alwaysdata. Elle reste à appliquer
ultérieurement, après validation, avant d’utiliser ce formulaire en production.**

Les tests exécutent les migrations uniquement dans SQLite en mémoire. Les
connexions SQL non SQLite et les transports réseau sont bloqués pendant les
suites. Aucun appel distant, émission ou accès MariaDB ne fait partie de cette étape.

Résultats du 7 novembre 2026 : 28 nouveaux tests, 64 tests ciblés réussis
(461 assertions), suite PHP complète de 1 583 tests réussis (7 862 assertions,
56,74 s), et Pint `--test` réussi sur les cinq fichiers PHP concernés.
`git diff --check` réussit. Les empreintes des services d’export, du diagnostic,
du calculateur, du modèle PDF et des quatre XML CII restent inchangées.
Le bootstrap temporaire d’isolation des tests a été supprimé après vérification.
