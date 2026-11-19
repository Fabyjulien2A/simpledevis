# Référence CII officielle : test documentaire sandbox

La commande `superpdp:sandbox-cii-reference` est indépendante des factures SimpleDevis,
de `InvoiceCiiPreparation`, de `CiiPreparationRegistry`, des paquets expérimentaux de
dépôt et de `OutgoingEmission`. Aucun identifiant légal n'est construit ni enregistré
dans les entreprises. Les règles de qualification vendeur restent inchangées.

État livré : `config/superpdp_documentary.php` est fermé (`enabled=false`, autorisations
et stockage absents). Aucun appel réel n'a été exécuté lors de l'implémentation.

## Commandes

Sans opération, la commande ne lit ni SQL ni archive et n'appelle aucune API :

```powershell
php artisan superpdp:sandbox-cii-reference --env=sandbox
```

Après autorisation serveur de génération uniquement :

```powershell
php artisan superpdp:sandbox-cii-reference --env=sandbox --generate --run=first-reference
```

Après inspection du résultat et autorisation serveur distincte liée au SHA-256 reçu :

```powershell
php artisan superpdp:sandbox-cii-reference --env=sandbox --validate --run=first-reference
```

Les options ne constituent jamais une autorisation réseau. `--generate` et `--validate`
s'excluent. La validation ne génère pas un nouveau document. Une validation échouée
ou indisponible retourne un code de sortie 1 et conserve le CII.

## Autorisations à préparer ultérieurement côté serveur

Ne pas activer ces paramètres avant une décision explicite. Aucun paramètre n'a été
ajouté au profil `.env.sandbox` et aucun secret n'est demandé en argument de commande.

Dans la configuration serveur dédiée, il faudra définir :

```php
$scope = [
    'run' => 'first-reference',
    'user_id' => 3,
    'company_id' => 3,
    'connection_id' => 1,
    'provider_company_id' => 18172,
    'number_scheme' => 'sandbox',
    'number' => '000000002',
    'environment' => 'sandbox',
    'client_id' => '<client sandbox exact existant>',
    'api_url' => 'https://api.superpdp.tech/v1.beta',
    'private_root' => '<répertoire privé absolu dédié existant>',
];
```

Seul l'autre périmètre explicitement accepté est Tricatel : utilisateur 4, entreprise
4, connexion 2, fournisseur 18171, identité `sandbox/000000001`. Le nom de l'entreprise,
les propriétaires, les connexions et les identifiants sont vérifiés. Il ne s'agit pas
d'identifiants légaux. La base et le profil doivent satisfaire `SandboxDatabaseIsolation`.
Ce contrôle porte sur le rattachement OAuth enregistré localement ; il ne produit pas
une nouvelle preuve distante de qualification du vendeur.

Définir `enabled=true`, `scope=$scope` et `private_root=$scope['private_root']` dans cette
seule configuration dédiée. L'autorisation `generation_authorization` doit être :

```php
[
    'scope' => $scope,
    'purpose' => 'generation',
    'approved_by' => '<opérateur identifié>',
    'approved_at' => /* timestamp entier fixé lors de l'approbation */,
    'expires_at' => /* timestamp entier fixe, au plus 900 secondes plus tard */,
]
```

Ne jamais calculer une nouvelle date d'approbation à chaque démarrage. L'autorisation
`validation_authorization` est un objet séparé, avec `purpose='validation'`, ses propres
dates fixes et le champ `cii_sha256` égal à l'empreinte exacte obtenue après génération.
Une autorisation de génération ne peut pas autoriser une validation. Les deux sont
vérifiées avant SQL et à nouveau avant l'appel SDK ; la validation vérifie aussi le hash
avant son marqueur d'intention.

Le jeton d'accès doit exister, être déchiffrable et expirer dans plus de cinq minutes.
La connexion doit être connectée, avec son jeton de renouvellement existant. Aucun
renouvellement n'est effectué. L'expiration ferme le parcours ; une reconnexion serait
une opération distincte, à autoriser séparément. Les modèles sont chargés par SELECT
dans une transaction explicitement READ ONLY, annulée avant tout réseau. Aucun modèle
Invoice n'est lu ou sauvegardé.

## Transport et résultats

La génération utilise exactement `invoice()->generateTest(format: 'cii')` du SDK, avec
Bearer sur `GET /v1.beta/invoices/generate_test_invoice?format=cii`. Le vendeur est par
défaut celui du jeton ; le client sandbox est choisi aléatoirement par SUPER PDP. Aucun
destinataire Burger Queen/Tricatel particulier n'est promis.

La validation utilise `invoice()->validate(['invoice.xml' => $xml])`, sur un connecteur
distinct sans Bearer (`POST /v1.beta/validation_reports`, `security: []`). Aucun endpoint
de conversion n'est nécessaire pour obtenir directement le CII. Les requêtes sont
limitées par classe, méthode, URL, paramètres et authentification : une tentative, TLS
vérifié, délais bornés, aucune redirection, aucun renouvellement ni cache installé.

Le XML exact est conservé après contrôles structurels et de sécurité XML. Cela ne
constitue pas une validation fiscale. Les statuts sont `not_performed`, `passed`,
`failed`, `unavailable`. `passed` requiert `CiiDocumentChecks::assertReport()` : rapport
unique lié au nom et à la taille du document, CII, niveau de conformité déclaré,
sous-rapports non vides avec contrôles et sans échecs. Un rapport incomplet ou malformé
ne devient jamais un succès. Le rapport brut est conservé même en cas d'échec.

Les erreurs réseau/HTTP ou interruptions laissent une intention consommée, à examiner,
sans relance. Une répétition après succès réutilise l'archive vérifiée sans nouvel appel.
Les autorisations doivent toujours être valides pour lancer une opération, même répétée.
Ne pas supprimer les marqueurs, changer le répertoire ou changer la référence pour
contourner un résultat incertain. Un nouvel essai exige une nouvelle décision serveur.

## Stockage privé et journal

Préprovisionner un répertoire dédié hors dépôt, hors racines publiques, hors OneDrive,
sur disque local. Sous Windows, les ACL protégées du répertoire et les descendants sont
contrôlés : accès limités à l'identité PHP, SYSTEM et administrateurs ; aucune jonction
ni lien dans les ancêtres ou descendants. Sous Unix : racine 0700 sans lien. Le programme
ne modifie pas les ACL d'un répertoire existant. Vérifier aussi que le serveur web ne
publie pas cet emplacement par un alias externe à Laravel.

Chaque référence possède `scope.json`, un verrou `operation.lock`, les journaux durables
`generation.intent.json` / `validation.intent.json`, le XML `document.cii.xml`, le rapport
facultatif `validation-report.json`, et les manifestes `*.result.json` avec leurs empreintes
`*.result.sha256`. Création exclusive, écritures complètes, flush/fsync, aucune réparation
ni écrasement automatique. Le verrou couvre toute l'opération, y compris le réseau.

Le manifeste contient le périmètre, la date, la version du contrat, l'opération distante,
les paramètres, le statut HTTP, la provenance réelle/simulée/cache et les SHA-256. Les
journaux conservent l'opérateur et la fenêtre d'approbation, sans jeton. Un hash détecte
une altération par rapport au manifeste privé ; ce n'est pas une signature contre un
administrateur capable de remplacer tous les fichiers.

Aucun téléchargement web ou stockage public n'est ajouté. Les sorties console sont
limitées aux statuts, dates et empreintes ; aucun XML, jeton ou chemin privé. Toujours :
« Test documentaire sandbox — document non émis — routage non vérifié ».

## Sources et vérification hors ligne

Contrat officiel local : `resources/schemas/superpdp/1.39.0.beta/openapi.json`, opérations
`GenerateTestInvoice` et `POST /v1.beta/validation_reports`. Provenance dans le README
du même répertoire de schémas. SDK : `InvoiceResource::generateTest()` et `validate()`.

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
php vendor/bin/pest tests/Unit/Services/SuperPdp/DocumentaryReferenceTest.php tests/Unit/Services/SuperPdp/CiiDocumentChecksTest.php --compact
```

Les tests interdisent SQL et les envois HTTP non simulés. Ils utilisent des modèles en
mémoire, des réponses SDK simulées et des fichiers temporaires. Les opérations de
verrouillage et d'intégrité sont réelles sur ces seuls fichiers. Le sondage des ACL
Windows est simulé dans les tests fonctionnels ; leurs droits effectifs et l'accès au
stockage cible devront être vérifiés avant le premier essai réel.
