# Profil de facture « Sandbox SUPER PDP »

Le profil `sandbox_superpdp_bq_tricatel_v1` est implémenté dans les routes existantes de création, modification de brouillon, finalisation, préparation CII et émission HTTP. Il réutilise `SandboxEmissionFactory` et `OutgoingEmission` ; aucun autre moteur d’émission, endpoint de dépôt ou journal de dépôt n’est créé.

## Périmètre et interface

- Disponible uniquement avec l’environnement applicatif et `app.env` égaux à `sandbox`, l’API sandbox attendue, `SandboxDatabaseIsolation` installé et `SandboxDatabaseManager` actif. Il n’existe aucune exception « testing » dans ce contrôle applicatif.
- Utilisateur 3 et entreprise Burger Queen 3, connexion 1, fournisseur 18172, `sandbox/000000002`. Destinataire fixé par le profil : entreprise Tricatel 4, connexion 2, fournisseur 18171, `sandbox/000000001`.
- Le client sélectionné reste un client **appartenant à l’utilisateur 3** : Tricatel, professionnel FR, sans SIRET. Le profil le lie explicitement à l’entreprise destinataire 4 ; il ne déduit pas une entreprise d’un nom et n’emprunte pas un client de l’utilisateur 4. L’adresse électronique client peut rester absente ; si présente, elle doit être exactement celle du destinataire approuvé.
- Quantités, prix, descriptions, TVA, unités et motifs d’exonération passent par les services fiscaux et de calcul existants. Le profil CII de test utilise le régime déclaré `subject_to_vat` ; les données postales doivent être complètes.
- La notice **« Sandbox SUPER PDP — Facture de test — sans valeur fiscale »** apparaît sur la facture, sa préparation, la liste et le PDF. Le formulaire explique ce périmètre ; son aperçu JavaScript ne soumet pas ce profil au récapitulatif français.

La référence `SBX-<UUID>` est créée côté serveur et figée dans le contexte. Elle ne consomme pas le compteur français. Une nouvelle facture reçoit une nouvelle référence ; son UUID de tentative d’émission est encore un autre identifiant, réservé par `OutgoingEmission`.

Le modèle refuse la création et la lecture d’un document de ce profil hors du scope sandbox, y compris lors du binding HTTP. La validation serveur refuse la valeur du profil en production ou pour un autre utilisateur. Le builder impose aussi ce scope, le propriétaire et un document sandbox persisté concordant. Un appel HTTP direct, un contexte JSON envoyé par le navigateur ou un changement d’environnement ne permet pas de l’ouvrir.

## Données figées et identités

La finalisation conserve le contrat d’instantané existant pour les parties. Le contexte de test versionné enregistre les entreprises, connexions, client, déclaration sandbox, référence de test, hash de l’instantané et hash des dates, cadre, version de calcul, lignes et totaux enregistrés. Le builder contrôle ce contexte et relit les sources persistées. Un changement de fiche ne remplace pas les parties figées ; une altération du document enregistré invalide son empreinte.

`buildSandboxFromData` reçoit une projection explicite de ces données. Les conventions CII de `SandboxCiiProfile` restent des **identifiants de test**. Aucun numéro sandbox n’est transformé en SIREN/SIRET, enregistré dans les champs légaux des entreprises ou injecté dans leur instantané comme une identité légale réelle. Le contexte de déclaration conserve `legal_registration_identity=null`. Les notes CII propres à ce profil portent la mention sans valeur fiscale.

Le profil français `fr_b2b_380_eur`, `InvoiceEmissionContext`, les qualificateurs français SIREN, le matcher CII français et leurs exigences restent inchangés. Le dispatcher de document appelle le matcher sandbox existant seulement pour une facture de ce nouveau profil. Les archives sandbox portent explicitement `emission_profile` ; elles ne sont pas acceptées comme archives françaises, et inversement. Les empreintes de source et de contexte restent obligatoires.

La requalification d’une facture définitive existante est interdite par les protections du modèle et des contrôleurs. Le scénario fixe, le slot permanent et les artefacts du précédent dépôt ne sont ni lus, ni effacés, ni réutilisés par ce parcours.

## Qualifications sandbox et préparation CII

`SandboxInvoiceEvidence::record()` assemble un rapport privé à partir de **quatre objets `SandboxSellerEvidence` issus de réponses SDK déjà obtenues sous autorisations distinctes** :

1. Burger Queen : réponse de `GET /companies/me`.
2. Burger Queen : réponse de `GET /oauth2_sessions/me`.
3. Burger Queen : réponse de `GET /directory_entries`.
4. Tricatel : réponse de `GET /directory_entries`, obtenue dans le contexte autorisé de Tricatel.

Chaque objet est construit par `SandboxSellerEvidence::fromResponse($response)` : méthode, origine, endpoint, statut, authentification, taille, JSON et provenance sont contrôlés. Le service ne réalise aucune requête, aucun refresh et aucune impersonation de l’utilisateur 4. Les trois preuves vendeur doivent utiliser le jeton courant et sont évaluées par `SellerEmissionQualification::assessSandbox`. La destination exige le reçu du GET de liste avec le jeton de la connexion Tricatel 2, entreprise fournisseur 18171 : identité exacte, adresse Peppol exacte et unique, `directory=peppol` et `status=created`. Une déclaration manuelle ne devient jamais une preuve API. L’annuaire français SIREN n’est pas appelé pour ce profil.

Pour ce seul profil sandbox, `is_replyto=false` est accepté et un champ absent permet la qualification documentaire fondée sur la description contractuelle du GET de liste : `qualification_documentaire_sandbox=passed`, `basis=directory_list_contract`, `replyto_classification=undetermined`, `delivery_verified=false`, `ready_for_emission=false`. L’absence n’est jamais convertie en `false`. Une valeur `true`, `null`, un type incorrect ou une adresse technique `_replyto` ne qualifient jamais la destination. Le contrôle strict de publication Tricatel et les règles de production restent inchangés. Cette qualification documentaire ne prouve pas la livraison et n’accorde aucune autorisation de préparation CII ou d’émission. Les rapports privés antérieurs ne sont pas réécrits.

Le rapport `kind=sandbox_invoice_parties` est lié au profil, à la facture, à ses empreintes, à la déclaration et au jeton vendeur courant. Il conserve la provenance de chaque preuve. Les contrôles communs exigent des preuves live récentes, au maximum quinze minutes, avant une préparation distante ou un dépôt. Une preuve simulée peut servir à tester les contrôles, mais ne permet pas de les ouvrir. Une preuve française ne qualifie pas ce profil ; une preuve sandbox ne qualifie pas une facture française.

Le rapport retourne `audit_reference`. Cette référence et le hash de source sont sélectionnés par l’autorisation **serveur** de préparation, jamais fournis comme preuve par le formulaire. Le bouton HTTP CII réutilise alors `InvoiceCiiPreparation::produce`, `SandboxCiiPreparationGateway`, `CiiPreparationRegistry` et `PreparedCiiArchive`. Il effectue la conversion et la validation existantes du document figé, avec recontrôles de scope et d’empreintes avant chaque opération.

Une conversion/validation simulée reste dans l’espace de simulation, sans permission d’émettre. Un échec, une interruption ou une validation indisponible conserve le blocage et ne provoque pas de reconversion automatique. Pour le dépôt, seuls les octets privés réellement validés, les preuves sandbox correspondantes et les autorisations temporaires passent les prérequis de la factory existante.

La réservation/confirmation HTTP d’émission conserve ses durées, son secret et son UUID exact. Les résultats acceptés, refusés et incertains sont durables ; aucun résultat incertain ne crée de relance. Une modification après réservation est refusée localement avant le SDK.

## Préparer le premier essai réel — actions restantes, non exécutées

1. Démarrer le bootstrap sandbox existant, se connecter comme utilisateur Burger Queen 3 et vérifier le stockage privé d’archives et ses droits effectifs. Réutiliser les entreprises et connexions existantes.
2. Sous autorisation distincte, rétablir/qualifier la session OAuth émetteur si nécessaire. La collecte d’une nouvelle preuve de publication Tricatel exige aussi un accès Tricatel autorisé et valide ; cela ne signifie pas que son jeton soit utilisé pour le dépôt Burger Queen. Aucun renouvellement automatique n’est ajouté.
3. Créer ou sélectionner le client Tricatel de test appartenant à Burger Queen, sans inventer de SIRET ni numéro de TVA légal. Choisir **Sandbox SUPER PDP**, saisir de nouvelles lignes et dates, vérifier les montants et finaliser le nouveau document. Les champs documentaires manquants doivent être complétés avant cette finalisation ; ne pas modifier une ancienne facture définitive.
4. Après autorisation des GET nécessaires, collecter les quatre réponses SDK ci-dessus dans leurs contextes autorisés. Construire les objets de preuve et appeler `app(SandboxInvoiceEvidence::class)->record($actor3, $invoice, $companyProof, $sessionProof, $sellerDirectoryProof, $tricatelDirectoryProof)`. Examiner le rapport. Aucun champ HTTP n’est une preuve.
5. Autoriser côté serveur, pour au plus quinze minutes, la préparation de **cette** facture et de **cette** référence de preuves : client sandbox, propriétaire 3, id facture, `source_sha256`, `verification_reference`, environnement, URL et fournisseur 18172. Les contrôles existants `superpdp_preparation.live_enabled`, `web_enabled`, `sandbox_client_id` et `authorization` restent à ouvrir explicitement, après revue. Puis cliquer sur **Préparer le document CII**, consulter le résultat et le CII privé, et exiger une validation réelle réussie.
6. Seulement après revue du CII et autorisation distincte du dépôt : configurer l’accord d’émission temporaire exact, puis ouvrir les interrupteurs HTTP sandbox existants. Cliquer sur **Réserver une tentative d’envoi**, vérifier le nouvel UUID et confirmer dans sa durée de validité. Conserver le résultat ; toute incertitude impose un examen, sans répétition automatique.

Tous les interrupteurs et autorisations livrés restent fermés. Aucun appel API réel, renouvellement OAuth, dépôt, écriture sur une base réelle ou modification de facture définitive existante n’a été effectué pendant cette implémentation. Aucune migration n’est nécessaire au profil : il utilise les colonnes JSON et les registres existants.

## Fichiers de cette implémentation

| Rôle | Fichiers créés ou modifiés |
| --- | --- |
| Nouveau profil et preuves typées | `app/Services/SuperPdp/SandboxInvoiceProfile.php`, `app/Services/SuperPdp/SandboxInvoiceEvidence.php` |
| Création et gel | `app/Http/Controllers/InvoiceController.php`, `app/Models/Invoice.php`, `app/Services/InvoicePartySnapshotCapture.php`, `app/Services/InvoiceExportDataBuilder.php`, `app/Services/SuperPdp/InvoiceFinalizationSummary.php` |
| Builder et diagnostic | `app/Services/SuperPdp/SuperPdpInvoicePayloadBuilder.php`, `app/Services/SuperPdp/SuperPdpEmissionEligibilityDiagnostic.php` |
| CII et archives | `app/Services/SuperPdp/CiiDocumentChecks.php`, `app/Services/SuperPdp/CiiPreparationRegistry.php`, `app/Services/SuperPdp/InvoiceCiiPreparation.php`, `app/Services/SuperPdp/SandboxCiiPreparationGateway.php`, `app/Services/SuperPdp/PreparedCiiArchive.php`, `app/Services/SuperPdp/CiiPreparationEvidence.php` |
| Qualifications et prérequis d’émission | `app/Services/SuperPdp/Emission/SandboxEmissionPrerequisites.php`, `app/Services/SuperPdp/Emission/SandboxSellerEvidence.php`, `app/Services/SuperPdp/Verification/TricatelPublicationVerification.php` |
| Présentation et séparation des contrôles | `app/Http/Controllers/InvoiceElectronicPreparationController.php`, `app/Http/Controllers/InvoiceDestinationVerificationController.php`, `resources/views/invoices/_form.blade.php`, `show.blade.php`, `index.blade.php`, `pdf.blade.php`, `electronic-preparation.blade.php`, `resources/views/invoices/partials/sandbox-notice.blade.php`, `public/js/invoice-finalization-summary.js` |
| Tests et fixture isolée | `tests/Unit/Http/SandboxInvoiceProfileTest.php`, `tests/Support/SandboxOwnInvoiceCii.php`, `tests/JavaScript/invoice-finalization-summary.test.cjs` |
| Procédure et renvois depuis les préparations précédentes | `docs/sandbox-superpdp-invoice-profile.md`, `docs/superpdp-interface-new-sandbox-trial.md`, `docs/invoice-electronic-emission-http.md` |

`OutgoingEmission`, `HttpEmission`, les routes et les fichiers de configuration ne sont pas remplacés ou ouverts par ce profil.

## Validation

Les nouveaux scénarios HTTP utilisent exclusivement un PDO SQLite en mémoire derrière le gestionnaire sandbox de test, `Storage::fake`, des reçus synthétiques et `MockClient`. Les requêtes HTTP non simulées sont interdites. Les reçus déclarés live dans les fixtures restent des objets synthétiques réservés au test, jamais de vraies preuves ni des fichiers d’émission réels. Une fixture privée de validation est construite uniquement pour exercer les protections du dispatch simulé ; le producteur applicatif ne promeut jamais une simulation.

Les tests vérifient : création multiligne et multi-TVA, brouillon/modification/gel, références distinctes, absence de consommation du compteur français, maintien des SIRET absents, stabilité après modification des fiches, refus de production et des mauvais propriétaires/liaisons, rejet des valeurs de contexte envoyées par le navigateur, preuves typées/expirées/différentes et rotation de jeton, CII exact et rejet de l’ancien numéro, réservation CII unique, dispatch unique et résultats acceptés/refusés/incertains, refus d’une altération après réservation.

Le test JavaScript dédié vérifie l’aperçu sandbox sans requête au récapitulatif français et conserve les attentes des scénarios français existants.

Résultat final : **676 tests PHP réussis, 5 014 assertions**, dont **21 scénarios du nouveau profil** ; **9 tests JavaScript réussis**. La régression ciblée inclut les anciens tests HTTP d’émission, du transport sandbox, d’OutgoingEmission, du builder français, du builder sandbox, des contrôles CII sandbox, des préparations CII, de l’interface d’émission, de la finalisation et de la capture des parties. Aucune suite globale ou nouvel audit général n’a été lancé.

Commande de la régression PHP exécutée :

```powershell
php vendor/bin/pest tests/Unit/Http/SandboxInvoiceProfileTest.php tests/Unit/Http/InvoiceEmissionHttpTest.php tests/Unit/Services/SuperPdp/SandboxEmissionTransportTest.php tests/Unit/Services/SuperPdp/OutgoingEmissionTest.php tests/Unit/Services/SuperPdp/SandboxInvoicePayloadBuilderTest.php tests/Unit/Services/SuperPdp/SandboxCiiDocumentChecksTest.php tests/Unit/Services/SuperPdp/SuperPdpInvoicePayloadBuilderTest.php tests/Feature/InvoiceEmissionPreparationTest.php tests/Feature/InvoiceCiiPreparationTest.php tests/Feature/RealCiiPreparationTest.php tests/Feature/InvoiceElectronicEmissionInterfaceTest.php tests/Feature/InvoicePartySnapshotFinalizationTest.php tests/Feature/InvoiceFinalizationTest.php --compact
node --preserve-symlinks --preserve-symlinks-main tests/JavaScript/invoice-finalization-summary.test.cjs
```

Les options Node évitent une erreur locale de résolution des parents du workspace dans l’environnement Windows restreint ; elles ne changent pas les assertions ni les contrôles applicatifs.
