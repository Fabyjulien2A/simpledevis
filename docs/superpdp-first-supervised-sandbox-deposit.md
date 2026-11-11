# Premier dépôt sandbox supervisé — procédure préparée, non exécutée

Vérifications du 11 novembre 2026. Cette procédure n'autorise aucune opération.
Aucune facture, tentative ou archive applicative n'a été créée pour cette étape.
Aucune migration, modification d'ACL ou requête SUPER PDP n'a été exécutée.
Seul ce document est ajouté ; les services et configurations sont inchangés.

## Résultats des vérifications réelles

Le contrôle hors ligne `tools/check-superpdp-sandbox.php` réussit : profil explicite,
unique connexion `superpdp_sandbox` vers `fabyjulien_simpledevis_test`, identifiants
SQL dédiés, aucun repli vers la base principale ni son cache, environnement OAuth
sandbox et origine API attendue. Il ne prouve pas la validité du compte SUPER PDP.

Le contrôle `tools/check-invoice-schema-test.php --audit` a été exécuté, sans
`--exercise`. Une inspection complémentaire des métadonnées sortantes a été faite
en transaction READ ONLY, terminée par ROLLBACK. Aucun modèle Laravel n'a été
démarré pour ces lectures ; aucune ligne métier ni aucun jeton n'a été lu.

| Contrôle | Résultat |
| --- | --- |
| Serveur | MariaDB 10.11.18 |
| Base et compte authentifiés | `fabyjulien_simpledevis_test` |
| Droits | Deux déclarations : USAGE global et droits sur la seule base de test ; aucune autre base métier visible |
| Schéma de création/finalisation | Aucune colonne requise manquante |
| Orchestration | Colonnes requises présentes dans `electronic_invoices`, `electronic_invoice_attempts`, `electronic_invoice_statuses` |
| OAuth | Contexte et `access_token_expires_at` présents, conformément au modèle |
| Index uniques | Les huit combinaisons ci-dessous sont présentes |
| Transactions | Les douze tables inspectées du parcours sont InnoDB |
| Conservation | FK facture → registre et registre → tentatives en RESTRICT ; statuts → registre en CASCADE |
| Migrations | Préparation du 9 novembre, orchestration du 9 novembre et contexte OAuth du 10 novembre enregistrés ; aucune migration locale en attente selon l'audit |

Combinaisons uniques effectivement vérifiées :

- `invoices(user_id, invoice_number)` ; numéro nullable pour les brouillons ;
- `invoice_counters(user_id, year)` ;
- `electronic_invoices(dispatch_key)`, `(local_reference)`, `(provider_binding_key)` ;
- `electronic_invoice_attempts(reference)`, `(electronic_invoice_id, number)` ;
- `electronic_invoice_statuses(event_key)`.

Les moteurs vérifiés concernent `users`, `companies`, `clients`, `invoices`,
`invoice_items`, `invoice_counters`, `payments`, `credit_notes`,
`superpdp_connections` et les trois tables électroniques sortantes.

## Stockage : blocage constaté, aucune correction appliquée

La configuration du disque `invoice_cii` est locale, privée et non servie ; la
racine prévue est `storage/app/invoice-cii`, hors des disques publics configurés.
Cette racine **n'existe pas** dans le workspace inspecté.

La DACL de son parent `storage/app` n'est pas protégée et comporte des droits de
modification pour des identités supplémentaires, dont le groupe local de sandbox.
Une création héritant simplement de ces droits ne démontrerait donc pas un accès
limité à l'utilisateur applicatif, SYSTEM et aux administrateurs.
`PreparedCiiArchive` utilise `mkdir/chmod`, sans contrôler la DACL Windows.

Avant toute archive applicative : faire approuver la création du répertoire et la
restriction de sa DACL, vérifier les règles effectives et l'absence de point de
réanalyse, puis vérifier lecture/écriture par l'identité exécutant réellement PHP.
Ne modifier ni les ACL du projet entier, ni les anciens fichiers de diagnostic.
Ces actions n'ont pas été réalisées. Si l'exécution vise un autre hôte, refaire
le contrôle sur cet hôte et son identité de service.

## Appels concurrents et doubles dépôts

Le code actuel fournit une clé `superpdp:{invoice_id}`, les index uniques réels,
des verrous utilisateur/facture/registre/tentative, puis un claim UUID et
`transport_started_at` commités avant le POST. Aucun appel réseau n'est fait
dans la transaction de réservation/claim. Une transaction externe est refusée.
Une tentative prise en charge n'est pas réémise ; les doubles appels à
`OutgoingEmission::dispatch()` reviennent sur la même tentative.

Les exceptions, réponses ambiguës ou échecs de persistance deviennent `unknown`.
Après un crash, la récupération peut marquer une prise en charge vieille d'au
moins cinq minutes `unknown`, sans la remettre en file. La réconciliation exige
une preuve positive distante et le SHA-256 de l'original ; elle ne renvoie pas.

Les tests existants couvrent commits, appels entrelacés, doublons et incertitudes
sur SQLite avec transport simulé. Ils n'ont pas été rejoués ici, car ils exécutent
des migrations. Aucun test de contention multiprocessus MariaDB n'a été exécuté.
Un tel exercice nécessiterait une autorisation distincte de fixtures/écritures
dans la seule base de test, sans réseau SUPER PDP ni migration automatique.

Le futur point d'entrée doit utiliser exclusivement l'orchestrateur. Un appel
direct à `SandboxSdkEmissionTransport::send()` ne fournit pas à lui seul une
consommation unique du claim. Ne pas appeler ce transport directement.

## Données sandbox nécessaires avant la création

Obtenir un jeu d'essai explicitement reconnu par SUPER PDP ; aucun identifiant
réel ou inventé ne doit remplacer une information sandbox manquante.

Vendeur : identité fictive FR, SIRET/SIREN et numéro de TVA d'essai documentés,
adresse postale, régime TVA, mentions PMT/PMD/AAB et adresse de facturation déclarée.
La société authentifiée doit avoir le même SIREN, un id fournisseur positif,
`country=FR`, `env=sandbox` et, avec le code actuel, `number_scheme=fr_siren`.
La session doit désigner le client OAuth attendu et attester
`company_verification_status=verified` et `user_identity_verification_status=verified`.
Un compte répondant uniquement `number_scheme=sandbox` est refusé par le
qualificateur vendeur actuel : arrêter si ce cas survient, sans changer la règle.

Destinataire : client professionnel FR fictif, identité/adresse/identifiants d'essai,
et une destination d'annuaire active unique, issue d'une réponse complète.
Le qualificateur actuel accepte uniquement `0225:SIREN` sans suffixe, avec un
BT-49 figé `{scheme: '0225', value: '<SIREN>'}` exactement concordant.
Les adresses `EM` et identifiants de zéros utilisés pour la conversion précédente
ne constituent pas cette qualification. Leur validation CII ne prouve aucun routage.

Connexion : Authorization Code sandbox qualifiée dans la seule base de test,
client OAuth et URL API concordants, société attendue, access/refresh tokens
chiffrés avec la clé sandbox et lisibles par l'application. Aucun jeton ne doit
être copié depuis le profil principal ni exposé. Le modèle exige `connected`,
un refresh token et un access token valable au-delà de sa marge de cinq minutes.
Aucun refresh automatique n'est prévu pendant le dépôt.

## Parcours moderne de création — après accord distinct seulement

1. Choisir explicitement le profil sandbox via le bootstrap existant
   (`APP_ENV=sandbox` ou `--env=sandbox` pour une commande Laravel). Recontrôler
   base, compte et droits SQL dans la session utilisée ; ne pas supposer que le
   contrôle réalisé aujourd'hui qualifie toute autre session.
2. Préparer, après accord, un utilisateur, une entreprise et un client entièrement
   fictifs dans la base de test, avec le jeu d'essai qualifiable ci-dessus.
   Ne pas lancer `--exercise`, un seeder générique, `migrate` ou `migrate:fresh`.
3. Utiliser le POST local `/invoices` du parcours moderne, authentifié comme cet
   utilisateur, avec CSRF, `action=draft`, `emission_profile=fr_b2b_380_eur`,
   `french_business_process_type=S1`, l'id du client fictif, une date et une
   échéance cohérentes. Préférer un navigateur local au profil sandbox isolé.
4. Fournir les deux lignes du scénario métier : quantité `2.50`, prix `40.00`,
   TVA `20.00`, puis quantité `4.00`, prix `25.00`, TVA `10.00` ; descriptions
   fictives, `tax_treatment=taxable`, `unit_code=C62`. Ne fournir aucun total
   autoritaire : le serveur calcule HT 200,00, TVA 30,00 et TTC 230,00.
5. Vérifier facture/lignes en base de test : `draft`, numéro NULL,
   `calculation_version=decimal-v1`, aucun règlement/avoir et montant payé nul.
6. Appeler une seule fois le POST local `/invoices/{id}/finalize`. Le contrôleur
   capture les parties, numérote et contrôle le profil dans la transaction.
   Vérifier le numéro réellement attribué, `issued`, instantané v2, contexte
   électronique figé, lignes et montants inchangés. Ne pas imposer le numéro
   `SANDBOX-METIER-0001` du diagnostic ; ne pas finaliser par SQL direct.

Ne pas utiliser duplication ou conversion de devis pour ce premier cas.
Ne pas utiliser le bouton d'export XML historique comme générateur de CII.

## Qualification puis archive applicative — autorisations réseau distinctes

Les appels ci-dessous sont les points d'entrée PHP existants, pas des commandes
CLI nouvelles. Aucun n'a été exécuté ici. Les objets et références seront connus
seulement après la création autorisée.

1. Après autorisation des lectures SUPER PDP, activer uniquement dans le processus
   de confiance `superpdp_verification.live_reads_enabled`, puis appeler :

   ```php
   $report = app(\App\Services\SuperPdp\Verification\InvoicePartyVerification::class)
       ->verify($actor, $invoice, 'sandbox');
   $verificationReference = $report['audit_reference'];
   ```

   Le service consulte l'annuaire acheteur, la session, puis la société si la
   session est qualifiée, et éventuellement les publications du vendeur : au
   maximum quatre GET. La publication de réception vendeur reste informative,
   sans modification de réception. Exiger `status=passed` et les trois contrôles
   obligatoires live/verified. Ne pas lancer ces lectures sans autorisation.
2. Après autorisation séparée d'un POST de conversion puis d'un POST de validation,
   paramétrer `superpdp_preparation.live_enabled=true`, son client sandbox et une
   approbation de confiance contenant `approved_by`, `approved_at`, `expires_at`
   (entiers, durée maximale 900 s), `user_id`, `invoice_id`, `source_sha256`,
   `verification_reference`, `environment=sandbox`, `oauth_client_id`, `api_url`
   et `provider_company_id`, tous issus du contexte vérifié. Appeler :

   ```php
   $prepared = app(\App\Services\SuperPdp\InvoiceCiiPreparation::class)
       ->produce($actor, $invoice, $verificationReference);
   ```

3. Exiger `status=prepared_validated`, `ready_for_emission=true` et une référence
   `invoice-cii/v1/validated/{user}/{invoice}/{sha256-manifeste}`. Relire avec
   `PreparedCiiArchive::readValidated()`. Le producteur déduit lui-même la
   provenance SDK et vérifie les octets, le rapport et les sources actuelles.
   Une simulation ou archive pending bloque la suite. Une validation indisponible
   ne justifie aucune relance automatique. Ne copier ni requalifier les fichiers
   de `%LOCALAPPDATA%/SimpleDevis/superpdp-cii-tests`.
4. Vérifier les références et SHA-256 source/payload/parties/contexte/CII/manifeste,
   les montants et ventilations 20 %/10 %. Refermer le verrou de préparation
   après cette phase ; il est indépendant de celui du dépôt.

## Fenêtre de fraîcheur avant réservation et avant POST

`SandboxEmissionPrerequisites` contrôle :

| Élément | Condition |
| --- | --- |
| Archive | Namespace validated, provenance live, source et contexte exacts, environnement/client/API/société concordants |
| Préparation | `prepared_at` non futur et datant d'au plus 15 minutes |
| Validation | `status=passed`, `source=live`, rapport positif lié aux octets CII et `observed_at` d'au plus 15 minutes |
| Audit des parties | `status=passed`, mêmes sources/parties/contexte, `checked_at` d'au plus 15 minutes |
| Trois contrôles | `buyer_destination`, `seller_identity`, `seller_session` : passed, live, verified=true, SHA-256 de réponse, chacun observé il y a au plus 15 minutes |
| Approbation de dépôt | Non expirée, durée maximale 900 s, utilisateur/facture/archive/client/API/société/empreinte exacts |
| Jeton OAuth | Connexion active, contexte exact, token déchiffrable et expiration au-delà de la marge de 5 minutes |

La fenêtre utile est celle de la preuve la plus ancienne, pas quinze minutes
supplémentaires accordées au dernier clic. Renouveler une approbation ne rajeunit
aucune preuve. En cas d'expiration, arrêter et demander l'autorisation des seules
lectures/conversions réellement nécessaires ; ne réétiqueter aucun horodatage.

## Unique dépôt futur via OutgoingEmission — après accord explicite

Un lanceur de dépôt prêt à exécuter n'existe pas actuellement. La séquence API
exacte ci-dessous devra être encadrée par un opérateur ou un futur lanceur revu,
qui impose base/compte de test, ids connus et empreintes attendues. Ne pas la
coller dans une console Laravel utilisant son environnement par défaut.

1. Revalider stockage, droits SQL exclusifs et absence d'historique sortant
   contradictoire pour cette facture. Vérifier toutes les preuves fraîches.
2. Obtenir l'accord portant sur une unique facture, sa référence d'archive,
   son SHA-256 et la société sandbox. Dans le seul processus opérateur, fournir
   `superpdp_emission.sandbox_enabled=true`, `sandbox_client_id` et `authorization` :
   `approved_by`, `approved_at`, `expires_at` (durée <= 900 s), `user_id`,
   `invoice_id`, `document_reference`, `cii_sha256`, `environment=sandbox`,
   `oauth_client_id`, `api_url=https://api.superpdp.tech/v1.beta`,
   `provider_company_id`. Ne pas modifier les valeurs par défaut du dépôt.
3. Hors de toute transaction externe, construire et réserver :

   ```php
   $outgoing = app(\App\Services\SuperPdp\Emission\SandboxEmissionFactory::class)
       ->forInvoice($actor, $invoice, $archiveReference);
   $attempt = $outgoing->reserve($actor, $invoice, $archiveReference, false);
   $record = \App\Models\ElectronicInvoice::on($invoice->getConnectionName())
       ->findOrFail($attempt->electronic_invoice_id);
   ```

   Cette phase écrit le registre dans la base de test, mais ne dépose rien.
   Présenter l'UUID de tentative et vérifier qu'il correspond au périmètre autorisé.
   S'il existe déjà une prise en charge, un rejet ou un état incertain, arrêter
   plutôt que d'utiliser `retryRejected=true` pour obtenir une nouvelle tentative.
4. Faire approuver la tentative précise, puis ajouter seulement cet UUID à
   `superpdp_emission.authorization.attempt_reference`. Recontrôler le temps restant.
   Appeler une seule fois, sans boucle ni retry :

   ```php
   $result = $outgoing->dispatch($actor, $record, $attempt->id);
   ```

   Le service revendique et commite avant le POST SDK, transmet les octets de
   l'archive avec l'UUID comme `external_id`, conserve les précontrôles fournisseur,
   interdit les redirections et utilise une seule tentative HTTP.
5. Refermer l'autorisation d'émission dans un `finally`, y compris en cas
   d'exception. Conserver résultat local, statut HTTP disponible, identifiants,
   empreintes et historique expurgés. Ne pas confondre acceptation du dépôt et
   réception métier par le destinataire.
6. `unknown`, exception ou coupure après claim : aucun second POST. Envisager
   `recoverInterrupted()` puis une réconciliation positive via GET/original,
   uniquement après autorisation des lectures nécessaires. Un rejet certain
   n'autorise pas une nouvelle tentative sans nouvel accord.

## État final de cette préparation

Satisfaits : profil isolé, compte SQL exclusivement test, schéma et index sortants,
moteurs InnoDB, contraintes de conservation, services d'orchestration fermés par
défaut et mécanismes anti-double appel identifiés.

À satisfaire : racine d'archives privée et ACL effectives ; jeu d'essai SUPER PDP
compatible avec les contrôles vendeur/annuaire ; création moderne autorisée et
connexion sandbox qualifiée ; preuves live fraîches ; archive applicative validée ;
autorisation de l'archive puis de l'UUID. La contention multiprocessus MariaDB
reste une vérification distincte, non réalisée.

Les blocages actuels sont le stockage non préparé et l'absence de données/preuves
qualifiées pour une facture applicative précise. Aucun manque de schéma n'a été
identifié et aucune migration n'est nécessaire d'après les lectures effectuées.
