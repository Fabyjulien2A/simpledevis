# Cycle HTTP d’émission

Le profil distinct [Sandbox SUPER PDP](sandbox-superpdp-invoice-profile.md) raccorde désormais les nouvelles factures de test Burger Queen → Tricatel au même cycle. Sa qualification sandbox ne remplace pas les exigences du profil français décrites ici. Aucun transport ni aucune autorisation n’est activé par ce profil.

Le POST existant `invoices.request-electronic-emission` raccorde maintenant deux étapes distinctes au moteur `OutgoingEmission` :

1. `step=reserve` consulte le CII courant, revalide les prérequis sandbox existants et réserve atomiquement une tentative. L’UUID est celui du registre, jamais celui fourni par le navigateur.
2. Le serveur ajoute une autorisation à l’historique durable de cette tentative : UUID, propriétaire, facture, empreinte CII, empreinte des preuves/manifest et de l’accord serveur, expiration, empreinte du secret de confirmation. Le secret reste dans la session et le formulaire. Durée maximale : cinq minutes, bornée par l’expiration de l’accord opérateur. Une nouvelle demande ne renouvelle pas l’autorisation.
3. Un second POST exige la case de confirmation, l’UUID exact et le secret. L’appartenance est vérifiée avant de lire la tentative. CSRF, authentification et limitation de débit existants restent applicables.
4. `OutgoingEmission::dispatch` revalide la confirmation et les preuves sous les mêmes verrous que la prise en charge. Il réexécute ensuite ses contrôles documentaires existants. La prise en charge est commitée avant le transport.
5. Le moteur existant conserve les résultats acceptés, refusés et incertains. Un POST portant sur une prise en charge interrompue depuis au moins cinq minutes appelle sa récupération locale vers `unknown`, sans dépôt. L’action `step=review` permet aussi cet examen après perte de la session de confirmation ; elle ne peut jamais appeler le transport. Une prise en charge récente conserve la session pour un examen ultérieur. Un GET ne modifie jamais le registre.
6. La section « Envoyer électroniquement » présente la réservation, la confirmation puis le résultat local. Une simulation est explicitement identifiée. Les erreurs brutes du fournisseur ne sont pas affichées.

## Fermeture du transport réel

Deux chemins sont raccordés et fermés par défaut. `http_simulation_enabled` conserve le chemin de test, limité à `testing`, SQLite `:memory:` et un transport de test injecté. `http_sandbox_enabled` sélectionne désormais `SandboxEmissionFactory::forHttpInvoice`, qui construit le même `OutgoingEmission` avec les prérequis et le transport SDK sandbox existants.

Le chemin SDK HTTP exige simultanément `http_sandbox_enabled === true`, l’environnement applicatif **et** `app.env` strictement égaux à `sandbox`, l’environnement fournisseur sandbox et l’URL exacte `https://api.superpdp.tech/v1.beta`. Il exige aussi `SandboxDatabaseIsolation` réellement installé, `SandboxDatabaseManager` actif et son scope SQL inchangé : connexion unique `superpdp_sandbox`, base isolée `fabyjulien_simpledevis_test`. Une simple déclaration de nom de base ne suffit pas. `production`, les environnements mixtes, les connexions alternatives et les mutations de scope sont refusés.

Les valeurs livrées restent `http_simulation_enabled=false`, `http_sandbox_enabled=false`, `sandbox_enabled=false`, `sandbox_client_id=null` et `authorization=null`. Le binding général demeure `DisabledEmissionTransport` / `ClosedEmissionPrerequisites`. Aucun paramètre navigateur ne peut les ouvrir. Aucune migration supplémentaire n’est nécessaire.

Le dépôt sandbox est techniquement raccordé et reste désactivé. `HttpEmissionConfirmation` contrôle l’autorisation durable sous verrou, puis à la frontière SDK et dans le middleware avant l’appel. Il exige le même UUID, le secret, le propriétaire, la facture, le hash CII, les preuves inchangées, l’accord opérateur inchangé et une confirmation explicite persistée. Un callback vide ou un objet de confirmation seul ne suffit pas. Le chemin historique `forInvoice` conserve son contrôle d’UUID explicite.

L’accès local au registre HTTP reste possible après expiration de l’accord ou du jeton : le moteur peut ainsi enregistrer un refus local ou récupérer une interruption sans contacter le transport. Les contrôles de dépôt restent exécutés avant le claim et avant l’appel. Une réponse déjà reçue peut être enregistrée après expiration, sans déclencher de nouvel appel. Les lectures distantes, la réconciliation et la synchronisation ne font pas partie de l’autorisation HTTP et sont refusées par ce chemin SDK.

Cette livraison ne modifie aucune configuration locale de connexion, ne renouvelle aucun jeton, ne contacte aucune API et n’applique aucune migration sur une base réelle. Aucune facture définitive ni autorisation active n’a été modifiée.

## Validation

`tests/Unit/Http/InvoiceEmissionHttpTest.php` utilise les vraies routes et middleware, des commits sur SQLite en mémoire, une archive privée et des preuves synthétiques, les contrôles sandbox existants et des transports simulés. Les tests de factory installent le vrai scope et le vrai gestionnaire sandbox ; leur connecteur de test remplace exclusivement MySQL par le PDO SQLite en mémoire déjà migré. Le nom d’hôte est `forbidden.invalid`. Les modèles et le journal CII sont rattachés au scope de test ; aucun socket SQL réel n’est ouvert. Les clients Laravel HTTP et SDK interdisent les requêtes non simulées. `MockClient` intercepte chaque appel SDK. Le corps XML exact, l’UUID, le claim durable, l’absence de transaction ouverte, l’absence de retry et de redirection sont vérifiés.

Les tests couvrent acceptation, refus, timeout, double confirmation, expiration, altération du CII/des preuves/de l’accord, jeton OAuth expiré, UUID et secret étrangers, propriétaire, CSRF, confirmation absente, interruption et absence de renouvellement. Les tests existants du moteur couvrent aussi les travailleurs entrelacés, la persistance incertaine et la réconciliation.

Le lot de la livraison précédente (`InvoiceEmissionHttpTest`, `InvoiceElectronicEmissionInterfaceTest`, `OutgoingEmissionTest`, `ElectronicEmissionEndToEndTest`) passait entièrement : **116 tests, 1 462 assertions**, dont **23 tests HTTP**. Le raccordement de la factory ajoute 28 scénarios HTTP SDK et conserve ces tests.

La régression globale **précédente** rapportait 4 364 réussites, 116 échecs et 48 tests dépréciés. La reproduction limitée aux quatre fichiers concernés rapporte **116 échecs et 64 réussites**. Lors de cette reproduction, `HttpEmission`, `InvoiceEmissionReadiness` et `OutgoingEmission` ne sont pas chargés ; le contrôleur peut être chargé à l’enregistrement des routes. Aucune régression imputable au cycle HTTP n’est identifiée dans ce lot. Aucun nouvel audit général n’est lancé, et les attentes ou les contrôles de ces fichiers ne sont pas modifiés pour les rendre verts.

| Fichier | Échecs | Cause observée |
| --- | ---: | --- |
| `tests/Unit/Services/SuperPdp/ExperimentalSandboxTrialTest.php` | 44 | Mock `PendingRequest` sans méthode configurée : PHPUnit ne peut générer l’enum `Saloon\Enums\Method` demandé par `VerificationObservation`. |
| `tests/Unit/Services/SuperPdp/FrenchDirectoryConsultationFactoryTest.php` | 2 | Resolver de sender global laissé par les tests expérimentaux : `No HTTP sender outside synthetic runtime tests`. |
| `tests/Unit/Tools/SuperPdpBusinessCiiDiagnosticTest.php` | 38 | État global de facade/conteneur : service `validator` absent à l’initialisation des fixtures. |
| `tests/Feature/SuperPdpOAuthTest.php` | 32 | Fixtures sandbox utilisant le parcours SIREN historique sans le scope SQL et les identités explicitement exigés par le service actuel. Échec avant création de `state`, ainsi que dans des tests de refresh. |

Ces problèmes préexistants restent distincts du raccordement demandé. La suite globale n’a pas été relancée.

Validation finale du raccordement : **226 tests réussis, 2 425 assertions**, sur `InvoiceEmissionHttpTest`, `InvoiceElectronicEmissionInterfaceTest`, `OutgoingEmissionTest`, `ElectronicEmissionEndToEndTest`, `SandboxEmissionTransportTest` et `SandboxDatabaseIsolationTest`. Cela inclut les **28 nouveaux scénarios HTTP SDK**. Pint et le contrôle des espaces passent sur les fichiers concernés. Tous ces essais utilisent uniquement des transports simulés et des bases isolées.

## Avant un premier essai sandbox depuis l’interface

Le raccordement technique à la factory existe désormais ; aucune activation n’a été effectuée. Avant un essai séparément autorisé :

1. Démarrer via le bootstrap sandbox existant, avec le scope et le gestionnaire SQL isolés installés, ainsi que le schéma du registre dans la base sandbox.
2. Disposer d’une connexion OAuth sandbox qualifiée du bon propriétaire et de la bonne entreprise fournisseur, avec un client explicitement autorisé et un jeton valable plus de cinq minutes à l’inspection initiale. Aucun refresh automatique.
3. Sélectionner une facture définitive déjà éligible et inchangée, un CII privé réellement validé et une réservation CII terminée dans le **même scope SQL**. Les preuves vendeur/session/destination et la validation doivent avoir au plus quinze minutes et correspondre aux empreintes et au contexte.
4. Un opérateur active explicitement `SUPERPDP_HTTP_SANDBOX_ENABLED=true` dans le profil serveur `.env.sandbox`, puis fournit l’accord privé décrit ci-dessous. Le fournisseur de services le charge à chaque démarrage de requête. Aucun script CLI d’émission n’est nécessaire. **Aucun profil ni accord actif n’a été créé ou modifié ici.**
5. Réserver depuis l’interface puis confirmer cet UUID dans les cinq minutes. L’autorisation de tentative est dérivée côté serveur de l’accord précédent et stockée dans son historique. Il n’est pas nécessaire d’ajouter ensuite l’UUID à la configuration : changer l’accord invalide son empreinte. Si un `attempt_reference` est déjà présent dans l’accord, il doit correspondre exactement.

Les identifiants sandbox ne remplacent jamais un SIREN/SIRET ou les exigences `fr_siren`. Si ces contrôles légaux bloquent la facture, l’essai reste bloqué. Un document de simulation ou de référence ne suffit pas. Une tentative existante, même refusée, ne crée aucun bouton de relance ; un résultat incertain exige un examen sans nouveau dépôt automatique.

## Accord privé du parcours web sandbox

L’activation utilise uniquement le moteur existant. L’indicateur serveur ne suffit pas à autoriser un envoi. Un opérateur ayant accès au serveur doit déposer un unique fichier JSON dans `storage/app/invoice-cii/sandbox-web-emission/authorization.json`, hors du répertoire public. Le disque `invoice_cii` doit rester local, privé et non servi. Restreindre l’écriture aux opérateurs autorisés et la lecture au processus applicatif ; ne jamais placer ce fichier dans Git ni créer de route de téléchargement pour celui-ci.

Le JSON contient les champs suivants :

| Champ | Valeur attendue |
| --- | --- |
| `approved_by` | Identité non vide de l’opérateur |
| `approved_at`, `expires_at` | Entiers Unix en secondes, accord déjà commencé et non expiré, durée de 900 secondes maximum |
| `user_id`, `invoice_id` | Identifiants entiers positifs du propriétaire et de la seule facture autorisée |
| `company_id`, `connection_id` | Identifiants locaux exacts de l’entreprise et de sa connexion OAuth |
| `provider_company_id` | Identifiant entier de l’entreprise SUPER PDP de cette connexion |
| `document_reference` | Référence exacte `invoice-cii/v1/validated/{user_id}/{invoice_id}/{manifest_sha256}` de l’archive privée |
| `cii_sha256` | Empreinte SHA-256 du CII validé, issue de son manifeste |
| `environment` | `sandbox` |
| `oauth_client_id` | Client OAuth sandbox explicitement autorisé, identique à celui du serveur |
| `api_url` | `https://api.superpdp.tech/v1.beta` |

Ne fournir aucun jeton OAuth dans ce fichier. Ne fournir aucun `attempt_reference` : la réservation web produit et lie l’UUID elle-même. La facture nº 81 est définitivement exclue des nouvelles émissions, même avec une autorisation injectée manuellement.

Le fichier est lu sans écriture et n’est pas renouvelé automatiquement. Il doit rester inchangé entre les deux POST. À la réservation, le mécanisme existant conserve l’empreinte de l’accord, des preuves et du manifeste dans l’historique de la tentative, ainsi que le hash du jeton de confirmation. Une modification ultérieure de l’accord invalide cette liaison. Un fichier absent, invalide, expiré ou supprimé ferme l’autorisation ; aucune permission issue d’un ancien accord n’est héritée au démarrage suivant.

Pour essayer le parcours dans le navigateur, démarrer le serveur avec le profil sandbox isolé existant, préparer une nouvelle facture éligible et son CII réellement validé, puis faire déposer cet accord par l’opérateur. Ouvrir le détail de cette facture sous le compte propriétaire, cliquer sur « Envoyer électroniquement », vérifier le formulaire de confirmation, cocher la confirmation explicite et confirmer dans les cinq minutes et avant expiration de l’accord. La réservation seule n’effectue aucun dépôt. Le second POST utilise le transport sandbox réel : ne le confirmer que lors d’un essai expressément autorisé. Le statut accepté, refusé ou incertain s’affiche ensuite sans relance automatique.

Après l’essai, supprimer l’accord et désactiver l’indicateur serveur. Ne jamais modifier un accord pour réémettre une facture ayant déjà une tentative. Aucun changement du profil de production n’est nécessaire ; l’activation y est refusée même si l’indicateur et le fichier sont présents.

Validation de cette activation : `InvoiceEmissionHttpTest` et `InvoiceElectronicEmissionInterfaceTest` passent avec **98 tests et 1 623 assertions**, dont 28 nouveaux scénarios de chargement de l’accord privé. `SandboxEmissionTransportTest` et `SandboxDatabaseIsolationTest` passent avec **90 tests et 508 assertions**. Total : **188 tests, 2 131 assertions**. Ces tests utilisent SQLite en mémoire, des archives et accords synthétiques et des clients HTTP/SDK simulés ; aucun appel fournisseur ni aucune émission réelle ou sandbox n’est effectué. Les contrôles de syntaxe et Pint passent sur les fichiers PHP concernés. Aucun accord actif ni profil serveur n’a été installé par cette modification.

