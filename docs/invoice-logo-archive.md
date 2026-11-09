# Logos historiques privés — instantanés v2

Les nouvelles finalisations (création directe et validation d'un brouillon)
produisent `schema_version: 2`. Les champs métier et la qualification électronique
`not_assessed` restent identiques au contrat v1. Seul `seller.logo` change de sens :
il contient `null` ou une référence privée validée, par exemple
`invoice-logos/v1/42/<sha256>.png`. La version de cette référence est distincte de
la version de l'enveloppe JSON. JPEG utilise l'extension canonique `.jpg`.

`InvoicePartySnapshot::capture()` conserve son défaut v1 pour les captures
explicites existantes. La capture transactionnelle demande explicitement v2.
Les lecteurs acceptent v1 et v2 et conservent leur version à la sérialisation.
Aucune migration, réécriture des anciennes captures ou reprise des factures à
SQL NULL n'est effectuée. Les références publiques v1 restent publiques et leur
absence continue d'omettre le logo. Les factures sans instantané gardent leur
parcours historique utilisant la fiche entreprise actuelle.

## Stockage et accès

Le disque local `invoice_logos` pointe sur `storage/app/invoice-logos`, hors du
disque public et du disque local servi automatiquement. Il n'a ni URL, ni lien
public, ni route de consultation, et `serve` vaut `false`.
L'organisation est `<user_id>/<sha256>.<extension>` : déduplication des octets
pour un même propriétaire uniquement. Les octets originaux PNG/JPEG/WebP sont
conservés, sans conversion. La taille maximale est de 2 Mio, comme l'upload.

L'accès PDF est autorisé par les contrôleurs existants. Le lecteur prend le
propriétaire de la facture persistée, pas la session, et contrôle la référence,
le propriétaire, le confinement du chemin, l'absence de liens symboliques dans
le chemin de l'archive, le SHA-256 et le format réel/décodage de l'image. Les
références malformées, archives manquantes, illisibles ou altérées provoquent
une erreur de validation explicite ; aucun logo courant n'est substitué.
Le téléchargement, les pièces jointes et la vue PDF appelée par FacturXService
utilisent cette même projection. Le XML Factur-X n'est pas modifié.

La préparation électronique valide l'enveloppe v2 sans lire les fichiers des
logos. Le logo ne fait pas partie des données projetées vers SUPER PDP ; les
autres champs, diagnostics et règles de routage restent inchangés.

## Transactions et publication

Le remplacement de la fiche entreprise et la finalisation prennent d'abord le
verrou utilisateur. La finalisation conserve ensuite l'ordre facture,
entreprise, client. Le remplacement prend ensuite le verrou entreprise et ne
supprime l'ancien upload public qu'après le commit SQL. Une erreur d'upload
ou de mise à jour conserve donc l'ancien fichier. Aucun de ces chemins ne
supprime d'archive privée.

La capture lit le logo source une seule fois sous verrou. Elle calcule
l'empreinte et le format depuis ces mêmes octets, puis écrit un temporaire
privé dans le répertoire du propriétaire. Après écriture complète,
`fflush`/`fsync` et vérification de la relecture, un lien physique publie le nom
final sans possibilité d'écrasement. Un fichier préexistant est vérifié, jamais
réparé ou remplacé. Le temporaire propre à cet appel est ensuite supprimé.
La destination est relue et vérifiée avant l'enregistrement de l'instantané.
Une référence v2 déjà présente sur un brouillon est vérifiée sans la réécrire.

Tout échec avant l'émission annule la transaction SQL et ne consomme pas le
numéro de facture. Le système de fichiers ne participe pas au rollback : une
archive complète peut rester orpheline et sera réutilisable. Les archives
potentiellement partagées ne sont jamais supprimées automatiquement. Un upload
courant devenu orphelin après un rollback peut également rester sur disque.

## Limites opérationnelles

Le stockage doit supporter les liens physiques locaux et `fsync`. Si ce n'est
pas le cas, la finalisation avec logo échoue explicitement. Le répertoire doit
être sauvegardé avec la base et rester inaccessible au serveur web ; les droits
POSIX demandés sont 0700 pour les répertoires créés et 0600 pour les fichiers.
Leur application effective dépend du système et des ACL de l'hébergement.

Il s'agit d'une immutabilité applicative, sans stockage WORM : un administrateur
peut modifier ou supprimer les fichiers. Une modification est détectée à la
lecture, mais les suppressions/modifications externes après validation ne sont
pas empêchées. SQLite ne valide pas les attentes de verrous ou interblocages
MariaDB ; ces essais restent à réaliser sur deux connexions InnoDB, séparément.
Aucun accès à Alwaysdata n'a été nécessaire pour développer cette fonctionnalité.
