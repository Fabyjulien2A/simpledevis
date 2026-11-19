# Réservations durables de préparation CII

Le producteur existant `InvoiceCiiPreparation::produce()` est protégé par
`CiiPreparationRegistry`. Aucune route, action web, activation ou migration SQL
n'est ajoutée. Le bouton Document CII reste désactivé. `OutgoingEmission` et son
registre ne sont pas utilisés et ne sont pas modifiés.

## Clé et stockage

Le registre relit le propriétaire authentifié, la facture définitive, l'entreprise
et sa connexion SUPER PDP ; il réutilise le diagnostic local et le builder
historique. La clé SHA-256 comprend le propriétaire, l'entreprise, la facture,
le périmètre de connexion SQL, la connexion OAuth, l'environnement, le client,
l'URL, l'identifiant fournisseur, l'identité d'annuaire, la version de contrat et
les empreintes des sources figées, des deux instantanés et du payload.

Les jetons OAuth, la référence des preuves et les dates d'autorisation ne font
pas partie de la clé : leur renouvellement ne doit pas autoriser une conversion
identique supplémentaire. Simulation de test et parcours API utilisent deux
clés distinctes ; une simulation ne devient jamais un résultat réellement validé.

Stockage unique : sous le disque privé `invoice_cii`, dans
`preparation-reservations/<clé>.jsonl`. La validation de racine privée et des
chemins est réutilisée depuis `PreparedCiiArchive`. Aucun chemin utilisateur,
lien public, jeton ou contenu de facture n'est enregistré dans le journal.

Les mécanismes reprennent ceux déjà présents dans le journal expérimental :
création exclusive, verrou `flock` non bloquant, écritures complètes,
`fflush`/`fsync`, entrées en ajout seulement et chaîne d'empreintes. Le premier
appel ne reçoit sa capacité de finalisation qu'après synchronisation du journal.
Cette capacité reste en mémoire, n'est ni sérialisable ni restaurable et ne
s'affiche pas dans JSON ou les informations de débogage.

Tous les processus doivent utiliser le **même stockage privé local**, avec des
verrous de fichiers fiables. Des stockages indépendants par serveur ne partagent
pas la réservation ; un déploiement multi-serveur nécessiterait une coordination
commune avant toute activation. Aucun journal ne doit être supprimé ou purgé pour
débloquer une préparation.

## États et intégrité

- `running` : un seul détenteur ; toute autre demande est bloquée avant conversion.
- `completed` : résultat terminé, relu et vérifié avant réutilisation.
- `review_required` : interruption, expiration après quinze minutes, erreur,
  validation indisponible/en échec ou résultat incohérent. Aucune nouvelle
  capacité n'est délivrée et aucune relance automatique n'existe.

L'expiration ne libère jamais la réservation. Un fichier vide, tronqué, altéré
ou non synchronisé bloque également la reprise. Un incident après archivage mais
avant finalisation du journal nécessite un examen manuel : l'archive n'est jamais
redécouverte et promue automatiquement.

`produce()` consulte d'abord un éventuel résultat existant. Pour une nouvelle
conversion, la passerelle doit toujours valider les preuves, l'autorisation et
le contexte existants **avant** de réserver ; elle recontrôle aussi ces conditions
avant chaque requête. Une réservation seule ne donne aucune autorisation API.
En cas de course après cette vérification, une seule réservation délivre une
capacité et les autres appels ne convertissent pas.

Le résultat est lié à l'archive immuable existante. La lecture réutilise
`readValidated()`, `readPending()` ou `read()` et vérifie à nouveau les empreintes,
le rattachement aux sources, au propriétaire et au contexte, la concordance
XML/payload et le rapport lorsqu'il est déclaré réussi. Un résultat absent ou
altéré passe en quarantaine et ne déclenche jamais de reconversion.

`CiiPreparationRegistry::result()` fournit une consultation serveur autorisée,
liée à la facture et à son contexte courant. Elle retourne des métadonnées et une
référence opaque, aucun chemin système. Les lecteurs d'archives existants restent
nécessaires pour une future consultation des octets ; aucun téléchargement public
n'est ajouté. Les métadonnées du registre ne donnent aucun droit d'émission.
Consulter un résultat terminé ne renouvelle pas les preuves anciennes ; elles ne
sont pas présentées comme une nouvelle qualification de routage.

## Vérification et prochaine étape

Les tests dédiés utilisent des tables en tableaux PHP et des répertoires de test
privés. Aucun SQL métier n'est exécuté. Les huit processus PHP du test de course
ne chargent que le journal et une liaison synthétique, sans application Laravel,
base ou transport réseau. Les tests de non-régression du producteur utilisent
SQLite `:memory:` et des réponses SDK simulées : aucune conversion réelle.

Avant de connecter le bouton, il reste à ajouter la consultation HTTP privée du
résultat, la présentation des états et erreurs, puis un POST protégé explicitement
autorisé utilisant le producteur déjà protégé. Le stockage partagé, les preuves
réelles récentes et l'autorisation bornée doivent être disponibles et contrôlés.
Aucune action de réinitialisation/reprise des états incertains n'est fournie.
