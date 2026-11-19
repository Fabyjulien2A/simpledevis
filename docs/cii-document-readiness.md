# Section Document CII : consultation des prérequis

La page existante `invoices.electronic-preparation`, réservée au propriétaire
d'une facture définitive, affiche une section **Document CII**. Elle distingue
les données documentaires figées, la connexion OAuth, le bilan complet des
preuves vendeur/destinataire et l'autorisation de préparation.

`CiiPreparationReadiness` réutilise le résultat de `prepareLocal()` et
`CiiPreparationEvidence::assess()`. Il consulte uniquement la référence de bilan
sélectionnée dans l'autorisation serveur ; aucun paramètre HTTP ni rapport de
destination seul ne peut la remplacer. Sans référence, le bilan complet est
signalé comme non sélectionné : aucune recherche ou vérification distante n'est
déclenchée.

`CiiPreparationGuards` contient les contrôles locaux extraits de
`SandboxCiiPreparationGateway::context()` : environnement et client sandbox,
connexion OAuth, activation et autorisation bornée à la facture et ses empreintes.
La passerelle réutilise ces mêmes contrôles, avec les mêmes conditions de refus.
Les règles de preuve et de fraîcheur restent dans `CiiPreparationEvidence`.
Les erreurs d'inspection sont présentées en français sans jeton, chemin privé
ou corps distant.

Le bouton **Préparer le document CII** reste désactivé par défaut. Le verrou
`superpdp_preparation.web_enabled=false` est indépendant de `live_enabled`.
Un formulaire POST avec CSRF apparaît uniquement en sandbox explicite, lorsque
tous les groupes passent et qu’aucune réservation/résultat n’est affiché. Le POST
recontrôle les données, les preuves et l’autorisation avant de déléguer au producteur
existant ; voir `cii-http-preparation-request.md`.
Le GET ne construit aucune passerelle et ne déclenche ni conversion, validation,
archivage, émission ou renouvellement OAuth. Les sections existantes et le contrôle
de destination distinct sont conservés.

Les tests `tests/Unit/Http/CiiDocumentReadinessTest.php` utilisent les vrais
calculs, diagnostics et contrôles de preuve avec une base simulée en tableaux
PHP. Tout SQL, verrou ou transaction est interdit ; les données sont comparées
avant/après chaque test. Les transports HTTP, les écritures d'audit, l'accès au
stockage et l'instanciation du producteur ou de l'archive sont bloqués. Des tests
de la passerelle vérifient aussi, sans requête réseau, le maintien des refus des
autorisations invalides après extraction des contrôles.
