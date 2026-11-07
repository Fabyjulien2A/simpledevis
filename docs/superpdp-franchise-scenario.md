# Deuxième scénario fictif : S1 en franchise en base

Préparation locale du 6 novembre 2026, sans appel réseau ni émission par l'agent.
L'utilisateur rapporte d'abord HTTP 400 et BR-E-02, puis HTTP 200 après la
correction BT-32 et fournit le XML réel. Son intégration comme deuxième référence
est décrite dans [le bilan CII franchise](superpdp-franchise-cii-regression.md).
Les sections de préparation et leurs résultats ci-dessous retracent les étapes
locales antérieures à cette réponse. La référence CII réelle à
20 % reste inchangée, de même que `SandboxInvoiceFixture` et ses tests.

## Fixture et périmètre

`SandboxFranchiseInvoiceFixture` utilise une instance non persistée, sans ID,
et refuse tout autre modèle. Numéro distinct : `SANDBOX-FICTIF-FRANCHISE-0002`.
Toutes les parties restent fictives : identifiants à zéro, adresses fictives,
communications `example.invalid`. Le vendeur de cette fiction n'a pas de numéro
de TVA ajouté implicitement. Aucun profil réel n'est lu ni qualifié.

Une prestation, quantité 1.00, prix 100.00 EUR HT ; catégorie **E**, taux 0.00,
TVA 0.00, TTC et dû 100.00. Pas de règlement, acompte, avoir, remise ou frais.
Le contexte S1 et les mentions PMT/PMD/AAB sont fournis explicitement par la
fixture, en réutilisant le contexte fictif du premier scénario. Le texte de
franchise n'est pas inséré à la place d'une de ces trois mentions.

Le vrai builder, le préparateur fiscal et le validateur OpenAPI existants sont
réutilisés. Aucune règle de calcul ou d'éligibilité fiscale n'est ajoutée.

## Correction ciblée de BR-E-02 et BR-FR-CO-16

L'utilisateur fournit la règle BR-FR-CO-16 de la norme AFNOR XP Z12-012 :
pour la franchise en base, BT-118 = E, BT-121 = VATEX-FR-FRANCHISE et,
en l'absence de numéro de TVA vendeur, le SIREN est répété en BT-32.
Source indiquée : [XP Z12-012 sur impots.gouv.fr](https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/specification_externes_b2b/afnor/norme-afnor-factures.pdf).
L'extrait fourni est utilisé pour cette correction ; aucun téléchargement ni
vérification indépendante de la version complète du document n'a été effectué.

La fixture franchise supprime le numéro de TVA fictif hérité. Avant correction,
elle ne fournissait donc aucun BT-31, BT-32 ou BT-63, d'où BR-E-02 selon le
diagnostic distant rapporté. Le SIREN en BT-30 ne satisfait pas seul cette règle.

Le builder accepte maintenant `seller.tax_registration_identifier` fourni
explicitement par sa source et le transmet comme chaîne facultative en BT-32.
Il ne déduit ni cet identifiant ni le régime de TVA. La seule fixture franchise,
explicitement E/VATEX-FR-FRANCHISE et sans numéro de TVA vendeur, fournit son
SIREN fictif sans préfixe FR. BT-30 est conservé indépendamment :

```json
{
  "legal_registration_identifier": {"value": "000000000", "scheme": "0002"},
  "tax_registration_identifier": "000000000"
}
```

Le champ `vat_identifier` vendeur reste absent. Aucun numéro de TVA n'est créé.
Le BT-32 attendu en CII est
`SupplyChainTradeTransaction/ApplicableHeaderTradeAgreement/SellerTradeParty/SpecifiedTaxRegistration/ID[@schemeID='FC']`.
BT-30 reste dans `SellerTradeParty/SpecifiedLegalOrganization/ID[@schemeID='0002']`.
Le format JSON de BT-32 est une chaîne, sans objet scheme/value ni préfixe imposé
par l'OpenAPI. Le qualifiant FC appartient au binding CII, pas à la valeur JSON.

Cette correction ne modifie pas la lecture des entreprises réelles, leur stockage
ou leurs factures. Pour un parcours réel, l'appelant devra établir explicitement
la franchise et l'absence de numéro de TVA avant de fournir le SIREN en BT-32.
Ni un taux nul ni une exonération E quelconque ne déclenchent cet ajout.
Il ne s'agit pas d'une implémentation complète des validateurs EN16931 ou français.

## Mapping vérifié localement

Source : copie locale SUPER PDP OpenAPI 1.39.0.beta et schéma dérivé versionné.

| Sens | Ligne JSON `vat_information` | Ventilation JSON `vat_break_down[]` | CII attendu sous `ram:ApplicableTradeTax` |
|---|---|---|---|
| Catégorie | `invoiced_item_vat_category_code = E` | `vat_category_code = E` (BT-118) | `ram:CategoryCode = E` |
| Taux | `invoiced_item_vat_rate = 0.00` | `vat_category_rate = 0.00` (BT-119) | `ram:RateApplicablePercent = 0` |
| Code VATEX | `exemption_reason_code` (EXT-FR-FE-179) | `vat_exemption_reason_code` (BT-121) | `ram:ExemptionReasonCode` |
| Texte | `exemption_reason` (EXT-FR-FE-178) | `vat_exemption_reason` (BT-120) | `ram:ExemptionReason` |
| Base | — | `vat_category_taxable_amount = 100.00` (BT-116) | `ram:BasisAmount = 100.00` au niveau document |
| TVA | — | `vat_category_tax_amount = 0.00` (BT-117) | `ram:CalculatedAmount = 0.00` au niveau document |

Code retenu : `VATEX-FR-FRANCHISE`.
Texte distinct retenu : `TVA non applicable, art. 293 B du CGI`.
Les deux sont fournis ensemble, sans substitution ni concaténation.
Le bloc CII de ventilation attendu est situé dans
`SupplyChainTradeTransaction/ApplicableHeaderTradeSettlement/ApplicableTradeTax`.
Le bloc fiscal de ligne se situe dans
`IncludedSupplyChainTradeLineItem/SpecifiedLineTradeSettlement/ApplicableTradeTax`.
Le type fiscal attendu est `VAT`. Les noms CII et la distinction code/texte
sont cohérents avec `TradeTaxType` du XSD EN16931 installé avec atgp/factur-x.
La restitution effective des extensions françaises au niveau ligne par SUPER PDP
reste à observer ; ce tableau n'est pas une preuve de conversion.

Le total HT, la base fiscale, le total TTC et le dû attendus valent 100.00 ; la
TVA ventilée et totale vaut 0.00, en EUR. Les échelles lexicales XML peuvent
différer sans changer les valeurs décimales.

## Compatibilité et réserves

- L'OpenAPI accepte E et Z comme catégories distinctes. BT-121 est une chaîne,
  sans enum VATEX ; son acceptation structurelle ne valide pas le code.
- Le code proposé figure dans la liste locale
  `vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931_codedb.xml`.
  Cette présence confirme une compatibilité avec cette liste installée, pas sa
  mise à jour ni la liste effectivement appliquée par SUPER PDP.
- La formulation relative à l'article 293 B est conservée comme mention proposée.
  Aucune source juridique française officielle actualisée n'est disponible dans
  les ressources examinées. La validité actuelle de la référence légale et son
  adéquation restent à vérifier ultérieurement, sans affirmer leur certification.
- La catégorie E exige un code ou un texte explicite selon la politique existante.
  La fixture fournit les deux. Les catégories S et Z refusent ces motifs ; Z
  reste disponible pour ses propres usages, sans être un substitut à la franchise.
- Aucun choix de régime n'est déduit du taux nul, du SIRET, de la présence ou de
  l'absence de numéro de TVA. Aucun test n'affirme l'éligibilité d'une entreprise.
- XSD, Schematron, EN16931 et règles françaises complètes ne sont pas validés
  pour un CII de ce scénario puisqu'il n'existe pas encore. Les contrôles CII du
  premier scénario demeurent spécialisés sur leur référence à 20 %.

## Utilisation

Simulation locale, sans réseau même si la configuration de conversion est active :

```sh
php artisan superpdp:sandbox-convert --scenario=franchise
```

Sans option de scénario, la commande conserve la fiction standard à 20 %.
Un scénario inconnu est refusé. Aucun contenu utilisateur ni fichier ne peut être
injecté par cette option.

Après une **future autorisation explicite** et activation de la configuration
`SUPERPDP_SANDBOX_CONVERSION_ENABLED=true` en local/testing seulement :

```sh
php artisan superpdp:sandbox-convert --scenario=franchise --execute
```

Cette dernière commande n'a pas été exécutée. Elle conserve les restrictions du
probe existant : un seul POST de conversion, sans authentification, retry,
redirection, dépôt ou émission, sans persistance. Le résultat CII éventuel devra
être examiné séparément ; HTTP 200 ne constituera pas une certification fiscale.

## Tests locaux

`SandboxFranchiseScenarioTest` ajoute 26 cas : mapping exact, montants,
ventilation E, code et texte distincts, schéma OpenAPI, refus de E à taux positif,
de Z/S avec motif de franchise, de justification vide, de montants incohérents,
de types/champs JSON incorrects, d'une fixture persistée ou étrangère, scénario
inconnu et garde d'exécution. Il vérifie aussi la simulation sans HTTP, la
préservation du scénario standard et l'empreinte du CII réel.

Les tests de cette fiction interdisent toute requête SQL. Les suites sont
exécutées sous SQLite en mémoire, avec ouverture URL et transports réseau natifs
désactivés ; les appels HTTP des tests de transport existants sont simulés.

Résultats du 6 novembre 2026 :

- Tests ciblés (franchise, probe, CII de référence, fiscalité, OpenAPI et contexte
  français) : **473 réussis, 2 286 assertions**.
- Suite PHP complète : **1 069 réussis, 5 274 assertions** (48,82 s), soit les
  1 043 tests précédents plus 26 nouveaux cas.
- Pint : vérification réussie sur les quatre fichiers PHP concernés.
- Empreinte du CII réel après les tests :
  `3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`, inchangée.

Le bootstrap temporaire de test fixait APP_ENV=testing, SQLite en mémoire,
DB_URL vide et conversion désactivée. `allow_url_fopen=0` et les fonctions
`curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,socket_connect`
étaient désactivées ; les deux fonctions cURL étaient remplacées par des fonctions
levant immédiatement une exception, pour permettre l'initialisation de Guzzle
dans les tests HTTP simulés. Ce bootstrap a été retiré après les tests.

Le scénario est prêt techniquement pour un futur essai manuel autorisé, avec
les réserves documentées sur la vérification juridique actualisée et la
validation distante. Aucun succès de conversion de ce scénario n'est revendiqué.

La correction BT-32 ajoute des tests de non-régression du payload réellement
préparé par le probe : présence simultanée BT-30/BT-32, SIREN identique sans FR,
absence BT-31/BT-63, catégorie E et code de franchise conservés, compatibilité
OpenAPI et absence d'ajout automatique pour S, Z ou une autre exonération E.
Les types incorrects sont refusés ; les valeurs facultatives absentes ou blanches
ne sont pas remplacées automatiquement. Le scénario standard et l'empreinte du
CII réel sont toujours contrôlés.

Résultats après correction BT-32 : **602 tests unitaires SUPER PDP réussis,
2 641 assertions** ; **1 080 tests PHP réussis, 5 342 assertions** dans la suite
complète (45,14 s). Onze cas supplémentaires couvrent cette correction.
Pint réussit sur les trois fichiers PHP modifiés. La suite utilise le même
blocage réseau et SQLite en mémoire décrits ci-dessus ; le bootstrap temporaire
a été retiré. L'empreinte du CII de référence reste inchangée.

Le scénario corrigé est prêt pour un nouvel essai manuel de conversion
explicitement autorisé. Aucun appel distant n'a été effectué pendant cette
correction ; la disparition effective de BR-E-02 reste à constater sur cet essai.
