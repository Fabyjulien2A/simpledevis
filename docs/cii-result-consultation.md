# Consultation privée des résultats CII

La section **Document CII** de la page existante
`invoices.electronic-preparation` affiche le résultat correspondant aux données
figées et au contexte SUPER PDP actuels de la facture. Le bouton de préparation
reste désactivé. Aucun POST de génération n'est ajouté.

`CiiPreparationResultView` présente en français l'absence de préparation,
l'opération en cours, la validation réussie, la validation échouée,
l'indisponibilité de la validation et les résultats interrompus/incertains.
Les résultats simulés sont explicitement séparés d'une validation réelle.
La date correspond au dernier état enregistré dans le journal, affiché en heure
de Paris ; elle n'est pas présentée comme une nouvelle vérification de routage.

## Lecture sans effets de bord

Le GET utilise exclusivement `CiiPreparationRegistry::consult()`, et non
`existing()`, `reserve()` ou `result()`. La nouvelle méthode réutilise les mêmes
contrôles de source, de propriétaire, de facture définitive, d'entreprise et de
contexte ainsi que la vérification existante des archives. Le rapport de
validation est contrôlé lorsqu'il est déclaré réussi.

`CiiPreparationJournal::read()` ouvre le fichier en `rb`, prend un verrou partagé
et ne modifie aucune permission. La consultation ne crée aucun répertoire,
n'expire pas la réservation sur disque et ne met aucun résultat en quarantaine.
Une opération de plus de quinze minutes est présentée comme « à examiner » sans
modifier son état persistant. Une archive absente ou altérée bloque la consultation
et le téléchargement sans réparation. Un résultat déjà mis en quarantaine reste
bloqué, même si ses fichiers redeviennent lisibles.

La recherche porte sur la clé du contexte actuel. Si les données figées ou la
connexion changent, une ancienne référence ne permet pas de consulter un document
d'un autre contexte. Les jetons et autorisations API n'ont pas à être renouvelés
pour lire un document déjà préparé ; la consultation ne donne aucune autorisation
de préparation ou d'émission. Les environnements refusés par les contrôles
existants, dont la production, restent refusés.

## Téléchargement

`GET /invoices/{invoice}/electronic-preparation/cii/{reservation}`
(`invoices.cii-download`) exige l'authentification et une facture définitive
appartenant au demandeur. La référence de réservation doit correspondre à la clé
recalculée pour cette facture, son entreprise et son contexte. Aucun chemin
d'archive n'est accepté depuis la requête.

Chaque téléchargement relit le journal puis réutilise `PreparedCiiArchive` et
les contrôles existants pour vérifier propriétaire, empreintes, manifeste,
concordance XML/payload et validation déclarée. Les octets vérifiés sont servis
directement en pièce jointe privée, avec `no-store`, `nosniff` et une politique
de contenu restrictive ; aucun lien de stockage public n'est fourni.

Un document présent et intègre peut être téléchargé avec une validation échouée
ou indisponible. Il est alors explicitement nommé `non-valide`. Les documents de
simulation portent le suffixe `simulation`. Seule une archive réellement validée
et son rapport réussi donnent le libellé de validation réussie. Aucun de ces
résultats n'autorise l'envoi électronique.

Les erreurs attendues de téléchargement utilisent une réponse neutre, sans
trace de débogage, chemin privé, rapport distant brut ou jeton. L'affichage de
la page masque aussi les détails internes des erreurs de lecture.

## Tests et suite

`tests/Unit/Http/CiiResultConsultationTest.php` utilise des tables simulées en PHP
et des archives synthétiques dans un disque de test privé. Aucun producteur,
convertisseur ou transport réseau n'est exécuté. Les états, accès, téléchargements,
archives manquantes/altérées, contextes substitués et simulations sont couverts.
Les contenus, dates de modification et permissions des fichiers/répertoires
sont comparés avant et après les GET ; les données simulées sont également
comparées après chaque test.

Avant l'activation de la préparation, il reste à ajouter un POST authentifié,
protégé contre les requêtes forgées et limité en fréquence, à recontrôler les
preuves et l'autorisation bornée, puis à appeler le producteur déjà protégé par
les réservations. Les processus doivent partager le même stockage privé fiable.
Aucune reprise automatique des opérations incertaines n'est fournie.
