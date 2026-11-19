# Nouvel essai Burger Queen → Tricatel depuis l’interface

Mise à jour : le profil proposé ci-dessous est désormais implémenté. Voir [le parcours Sandbox SUPER PDP et les actions restantes](sandbox-superpdp-invoice-profile.md). Les constats ci-dessous décrivent la préparation antérieure ; le profil manquant n’est plus un blocage logiciel.

Préparation du 15 novembre 2026. Le précédent envoi est considéré comme réalisé, conformément à la confirmation de l’opérateur. Il n’a été ni répété ni consulté par API. Son UUID, ses archives et son journal restent intacts.

## État vérifié

| Élément | Résultat |
| --- | --- |
| Profil `.env.sandbox` | Contrôle hors ligne réussi ; aucune modification du profil ou du `.env` principal. |
| Bootstrap Laravel | `SimpleDevisSandbox`, environnement `sandbox`, debug OFF, URL `127.0.0.1:8000`. Vérifié avec les classes PDO et les fonctions réseau désactivées. Aucun serveur n’a été lancé. |
| SQL | Compte dédié et seule base métier visible : `fabyjulien_simpledevis_test`, MariaDB 10.11.18. Aucun accès production. |
| Schéma création/finalisation/émission/OAuth | Aucune colonne requise manquante, aucune migration en attente. |
| Atomicité et conservation | Huit index uniques du contrat d’émission présents, douze tables InnoDB, clés étrangères RESTRICT/NO ACTION et CASCADE attendues présentes. |
| Burger Queen | Entreprise locale 3, propriétaire 3, connexion existante 1, fournisseur 18172, identité attendue `sandbox/000000002`. Liaison et contexte OAuth locaux concordants. |
| Tricatel | Entreprise locale 4, propriétaire 4, connexion existante 2, fournisseur 18171, identité attendue `sandbox/000000001`. Liaison et contexte OAuth locaux concordants. |
| Jeton Burger Queen | Expiration enregistrée : **14/11/2026 20:00:36, heure de Paris** (`19:00:36 UTC`). Expiré. |
| Jeton Tricatel | Expiration enregistrée : **14/11/2026 13:02:13, heure de Paris** (`12:02:13 UTC`). Expiré. |
| Réseau et autorisations | Préparation, lectures live, émission et transport HTTP restent désactivés ; autorisations nulles. |

Les lectures réelles ont concerné exclusivement le schéma, les droits du compte isolé et les métadonnées des deux liaisons existantes. L’inspection complémentaire utilise `START TRANSACTION READ ONLY`, puis `ROLLBACK`, y compris en cas d’erreur. Aucun contenu de jeton n’est sélectionné : seuls sa présence et son horodatage d’expiration sont inspectés. Déchiffrement, identité distante et session distante restent **non vérifiés aujourd’hui**. Aucun renouvellement n’est tenté.

L’index unique OAuth `superpdp_connections(company_id)` n’est pas présent. C’est une observation distincte des huit index nécessaires au registre sortant ; une seule liaison effective a été trouvée pour chacune des entreprises 3 et 4. Le contrôle refuse une liaison ambiguë. Aucune modification de cet index ni migration n’est proposée pour cet essai.

Pour Burger Queen → Tricatel, le jeton de l’**émetteur Burger Queen** est nécessaire. L’expiration du jeton Tricatel n’impose pas de renouveler celui du destinataire pour déposer depuis Burger Queen. Réutiliser les entreprises et connexions existantes ; ne pas les recréer ni recopier leurs jetons.

## Pourquoi le parcours actuel bloque

Le formulaire des factures propose le profil français `fr_b2b_380_eur`. Il ne propose pas encore un profil de document de test sandbox pour ces identités. Les barrières sont distinctes :

1. La capture/finalisation et le diagnostic français exigent les données légales SIRET/SIREN et le contexte figé correspondant. `InvoiceEmissionContext` et `DeclaredBillingAddress` ne remplacent pas ces identifiants par les numéros sandbox.
2. `SellerEmissionQualification::assess` exige explicitement `number_scheme=fr_siren` et le SIREN vendeur concordant. Une société authentifiée comme `sandbox/000000002` échoue sur ce parcours.
3. Le qualificateur acheteur français attend `0225:SIREN`, sans suffixe ; il ne qualifie pas les adresses spécifiques `0225:315143296_18171` et `0225:315143296_18172` avec cette règle.
4. `SandboxEmissionPrerequisites`, malgré son nom, appelle encore le diagnostic et le builder français des factures SQL. Le raccordement `HttpEmission` → factory → `OutgoingEmission` ne remplace pas leur contrat documentaire.

Changer seulement les interrupteurs d’émission, relâcher `fr_siren` ou compléter artificiellement les SIRET ne résout donc pas le parcours. Aucune facture définitive existante ne doit être enrichie rétroactivement ou requalifiée.

## Règle SimpleDevis et contrat SUPER PDP

Source locale : `resources/schemas/superpdp/1.39.0.beta/openapi.json`, schémas `company_number_scheme` et `company`, opérations `POST /v1.beta/invoices`, `GET /v1.beta/companies/me` et session OAuth. Le contrat archivé énumère **`sandbox`, `fr_siren` et `be_numero_entreprise`** comme schemes d’identité d’entreprise. Il ne pose pas `fr_siren` comme condition générale de tous les dépôts sandbox.

Le précédent envoi réussi confirme la faisabilité d’un cas sandbox de ce couple d’entreprises ; il ne prouve pas la validité d’une nouvelle facture, de ses preuves ou des jetons actuels. Aucun appel documentaire ou API n’a été fait pour revérifier le contrat distant aujourd’hui.

Le dépôt attend toujours un document supporté, un accès OAuth autorisé à l’entreprise émettrice, une session qualifiée et une destination/document compatibles avec les précontrôles. L’acceptation structurelle ne garantit ni validation ultérieure ni livraison. `disable_pre_check` doit rester désactivé. Ces exigences restent à constater sur le **nouvel** essai après autorisations distinctes.

`SandboxCiiProfile`, `SandboxEmissionContext`, `SellerEmissionQualification::assessSandbox`, `InvoiceRoutingQualification::assessSandbox` et `buildSandboxFromData` existent déjà. Le profil CII encode des conventions **fictives de test**, y compris dans des champs CII nommés `legal_registration_identifier` et `vat_identifier` ; ces valeurs ne doivent jamais devenir des identifiants légaux réels dans les entreprises ou les instantanés de production. Le contexte conserve `legal_registration_identity=null`.

## Parcours proposé pour nos propres factures — à implémenter, non activé

Une vérification isolée confirme que le builder sandbox existant accepte une **nouvelle référence et des lignes personnalisées**, calcule les montants selon les contrôles existants et reste `ready_for_emission=false`. Ce test en mémoire ne produit ni CII validé ni facture persistée. Il ne réutilise pas le scénario fixe de 100 EUR du premier dépôt.

Le raccordement restant doit être un profil explicitement sandbox, pas un second moteur d’émission :

1. Permettre à l’utilisateur 3, dans le seul scope SQL sandbox installé, de préparer un **nouveau document de test Burger Queen → Tricatel**, avec ses propres lignes, une référence `SBX-...` unique et un affichage sans valeur fiscale. Lier Tricatel explicitement au destinataire existant de l’utilisateur émetteur ; ne pas utiliser directement un client appartenant à l’utilisateur 4, et ne pas deviner la liaison par le nom.
2. Figer un contexte de test distinct et versionné contenant les identités sandbox, les adresses approuvées et le hash des données du document. Conserver les identifiants légaux absents comme tels. Réserver le profil aux nouvelles factures de test de la base isolée, sans modifier les factures définitives existantes ni le parcours français.
3. Réutiliser `buildSandboxFromData`, les calculs fiscaux et les contrôles CII. Prévoir l’archivage, la réservation CII et la qualification sandbox adaptés à ce profil. Les archives françaises et les archives de test ne doivent pas être substituables. Une confirmation manuelle de destination reste `operator_confirmed`, jamais `api_verified=true` ou une fausse preuve live.
4. Après autorisation, obtenir les contrôles vendeur/session et la validation CII réels nécessaires au nouveau document. Conserver leur provenance et leur fraîcheur. Vérifier exactement l’adresse Tricatel `0225:315143296_18171`, sans `_replyto` ; celle du vendeur est `0225:315143296_18172`.
5. Brancher les prérequis typés de ce profil dans **la factory existante et `OutgoingEmission`**, derrière les mêmes interdictions de production. Conserver réservation atomique, nouvel UUID, confirmation explicite, autorisation temporaire liée au document et aux preuves, recontrôle avant transport, persistance des refus/incertitudes et absence de relance.

Ce profil n’est pas implémenté dans cette préparation : l’utilisateur a demandé de proposer ce parcours si nécessaire. L’implémentation exige des tests isolés de finalisation de test, de conservation du contexte et des preuves jusqu’au dispatch. Le simple succès du builder ne suffit pas à ouvrir l’émission HTTP actuelle.

Ne pas utiliser `superpdp:sandbox-trial prepare/deposit` pour ce nouvel essai : le module expérimental conserve le slot permanent du premier envoi et ne constitue pas le parcours des nouvelles factures de l’interface. Ne pas effacer, déplacer ou réinitialiser ses marqueurs, archives ou journaux. Aucun UUID ou document du précédent dépôt ne doit être réémis.

## Commandes strictement nécessaires

Depuis la racine, PowerShell. Ces contrôles ont été exécutés ; ils ne préparent ni facture ni dépôt :

```powershell
php tools/check-superpdp-sandbox.php
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail tools/check-invoice-schema-test.php --interface-audit
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail -d disable_classes=PDO,mysqli,SQLite3 artisan about --env=sandbox --only=environment
```

Le deuxième contrôle ouvre uniquement la connexion MariaDB dédiée, confirme base/compte/droits et refuse toute divergence entre `.env.sandbox` et `.env.mariadb-test`. Il ne lance ni Laravel ni `--exercise`. Il affiche `deposit_allowed=false` même si le schéma est prêt. Ne lancer ni `migrate`, ni `migrate:fresh`, ni `--exercise`, ni commande OAuth pendant cette préparation.

Régression ciblée, exclusivement en mémoire ou avec des réponses SDK synthétiques :

```powershell
php vendor/bin/pest tests/Unit/Tools/SandboxInterfaceTrialReadinessTest.php tests/Unit/Services/SuperPdp/SandboxInvoicePayloadBuilderTest.php tests/Unit/Services/SuperPdp/SandboxDatabaseIsolationTest.php tests/Unit/Services/SuperPdp/SellerSandboxQualificationTest.php tests/Unit/Services/SuperPdp/BuyerSandboxRoutingQualificationTest.php --compact
```

Résultat : **246 tests réussis, 1 014 assertions**. Pint et le contrôle syntaxique des scripts passent. Aucune suite générale n’a été lancée.

Après implémentation et validation du profil, le lancement local prévu sera :

```powershell
php artisan serve --env=sandbox --host=127.0.0.1 --port=8000 --no-reload
```

Cette commande n’a pas été exécutée. Elle n’autorise aucun appel SUPER PDP : garder tous les interrupteurs fermés. Utiliser `http://127.0.0.1:8000`, la session sandbox et l’utilisateur Burger Queen 3. Aucune commande actuelle ne peut achever le nouveau parcours demandé tant que le profil de test de l’interface manque ; ne pas lui substituer une ancienne commande de dépôt.

## Blocages et prochaine action

- **Logiciel :** profil de facture propre sandbox absent dans le parcours interface → capture → CII → prérequis HTTP. Le transport est raccordé, le contrat documentaire de ce cas ne l’est pas encore.
- **OAuth :** jeton émetteur Burger Queen expiré. Une reconnexion ou un renouvellement devra faire l’objet d’une autorisation distincte, une fois le parcours logiciel prêt. Réutiliser la connexion 1 ; aucune action OAuth maintenant.
- **Preuves :** aucune nouvelle qualification distante, conversion/validation CII ou preuve fraîche du nouveau document. Le stockage privé effectif et ses droits devront être confirmés avant l’archivage réel ; ils ne sont pas certifiés par ce contrôle SQL.
- **Ouverture volontairement absente :** aucune autorisation temporaire ni activation de transport. Elles ne viennent qu’après revue du nouveau document et de ses preuves, avec confirmation de son nouvel UUID.

**Prochaine action concrète : implémenter et tester hors ligne le profil « facture de test sandbox Burger Queen → Tricatel » dans l’interface en réutilisant les composants nommés ci-dessus.** Ensuite seulement : demander l’autorisation de rétablir la session OAuth émetteur et de collecter les nouvelles preuves/conversion/validation. L’autorisation du dépôt unique vient en dernier, après revue ; elle ne découle jamais de cette préparation.
