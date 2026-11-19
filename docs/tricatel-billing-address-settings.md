# Adresse BT-34 de Tricatel dans Mon entreprise

Le composant `resources/views/components/billing-electronic-address.blade.php`
limite la longueur de la valeur à 254 caractères et propose les schémas 0225 et
EM. Il ne vérifie pas le préfixe SIREN en JavaScript. Le formulaire entreprise
utilise ce composant pour BT-34 ; celui des clients l'utilise pour BT-49.

`CompanySettingController::update` valide les clés, types et schémas avec
`DeclaredBillingAddress::rules()`, puis verrouille le propriétaire et son
entreprise. `DeclaredBillingAddress::validate()` impose normalement la forme
0225 et le SIREN issu du SIRET, ou une adresse email RFC pour EM. Les clients
conservent directement ce contrôle strict.

L'exception de stockage se trouve uniquement dans `CompanyDeclaredBillingAddress`.
Elle exige simultanément : environnement applicatif sandbox et périmètre SQL
isolé validé par `SandboxDatabaseIsolation`, environnement SUPER PDP sandbox et
URL attendue, utilisateur authentifié et propriétaire 4, entreprise existante 4
nommée Tricatel, aucun SIRET existant ou soumis, et valeur exacte
`0225:315143296_18171`. La connexion locale de cette entreprise est relue et
verrouillée ; elle doit être connectée, porter `directory_identifier=000000001`,
un identifiant fournisseur positif, et correspondre au contexte OAuth sandbox,
client et URL configurés. Aucune identité soumise dans le formulaire n'est utilisée.

Le lien vers le schéma `sandbox` provient du mécanisme existant
`SuperPdpOAuthService::identity()` : entreprise 4 / propriétaire 4 correspond à
`sandbox / 000000001`. `remoteCompany()` vérifie cette identité et l'environnement
avant sa persistance OAuth. La connexion ne stocke pas séparément le schéma.
Le contrôle de publication CLI `TricatelPublicationScope` est distinct et n'est
pas invoqué par le formulaire ; sa restriction CLI reste inchangée.

Cette exception ne fait que stocker une déclaration. Elle n'écrit aucun audit,
aucune qualification, aucun état de routage, aucune facture et aucun SIRET.
`InvoiceEmissionContext`, `InvoiceFinalizationSummary` et les clients continuent
d'utiliser `DeclaredBillingAddress` sans exception. Les contrôles de SIRET des
services de construction de payload et de qualification vendeur, les contrôles
de destination et les preuves de publication restent inchangés. L'adresse seule
ne rend donc aucune facture éligible à l'émission.

Les tests `tests/Unit/Http/TricatelBillingAddressSettingsTest.php` passent par les
routes et le contrôleur avec des tables simulées exclusivement en tableaux PHP
(`InMemorySuperPdpOAuthDatabase`), sans PDO, migration ou SQL réel. Les transports
HTTP sont bloqués. Ils vérifient l'enregistrement, l'effacement, les refus de
périmètre et d'identité, les accès interentreprises, la validation de production,
le refus du contexte d'émission vendeur/acheteur et l'intégrité des factures.
