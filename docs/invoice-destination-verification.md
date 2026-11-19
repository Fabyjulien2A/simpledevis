# Vérification explicite de la destination électronique

Depuis une facture définitive : **Préparer l’envoi électronique → Vérifier la
destination électronique**. Le POST exige authentification, appartenance et
limite les tentatives à cinq par minute et par utilisateur. Aucun paramètre du
navigateur ne fournit une adresse, un SIREN, une réponse, un mode de simulation
ou une qualification.

Le parcours utilise uniquement `party_snapshot` et `emission_context` déjà
figés. Les adresses acheteur couvertes sont `0225` avec un SIREN simple, sans
suffixe. Une absence d'instantané ou un format hors périmètre est refusé avant
toute consultation. Aucune fiche actuelle n'est utilisée comme remplacement.

## Tester dans l'interface sans réseau

Dans un environnement de développement **APP_ENV=local** uniquement, ajouter
temporairement à la configuration locale :

```dotenv
SUPERPDP_DESTINATION_DEMO=match
```

Si la configuration Laravel est en cache, l'actualiser (`php artisan config:clear`).
Conserver `destination_live_reads_enabled=false` et `live_reads_enabled=false`
dans `config/superpdp_verification.php`. Ouvrir la préparation d'une facture
définitive dont l'adresse acheteur figée est `0225` avec un SIREN simple, puis
cliquer sur le bouton. Les réponses sont produites par `MockResponse` du SDK,
avec le SIREN figé, sans requête réseau. Le résultat indique **Simulation**,
`verified=false` et conserve `routing.verification_unavailable`, même si la
destination simulée correspond exactement.

Scénarios : `match`, `absent`, `inactive`, `ambiguous` (deux lignes identiques),
`incompatible` (suffixe non pris en charge) et `api_error` (HTTP 503 simulé).
Changer la valeur et actualiser la configuration avant un nouveau clic.
La simulation est refusée en production, sans repli vers un appel réel.
Retirer la variable à la fin ; un GET n'effectue jamais de consultation distante.

Sans mode de démonstration et avec les lectures réelles désactivées, le bouton
enregistre simplement « Vérification indisponible — consultations réelles
désactivées », sans réseau.

## Rapport et validité

Les comptes rendus JSON restent sur le stockage local privé `invoice_cii`,
dans l'arborescence existante `party-checks/v1/{user}/{invoice}`. Ce stockage doit
être accessible en écriture à l'application et non exposé publiquement.
`VerificationAuditStore` conserve les rapports immuables, contrôle leurs
empreintes et maintient un index privé verrouillé `destination.latest` du
dernier contrôle terminé. Aucun schéma SQL n'est ajouté et aucune facture,
connexion OAuth ou donnée de réception n'est modifiée.

Chaque rapport contient le contrat et le périmètre d'annuaire, l'utilisateur,
la facture, les dates de contrôle et d'observation, le SIREN demandé, l'adresse
figée, les empreintes des sources et de la réponse HTTP, la provenance SDK,
l'endpoint réellement interrogé, la méthode, les paramètres et le statut HTTP.
Seuls les éléments de réponse nécessaires à la décision sont conservés ; aucun
corps HTTP intégral, secret, jeton ou en-tête d'authentification n'est enregistré.

L'affichage relit le dernier rapport local et vérifie à nouveau l'intégrité,
les sources, l'identité, le périmètre, la destination et la fraîcheur. Une
dernière tentative échouée ne fait pas réapparaître un ancien succès.
L'absence, l'inactivité, l'ambiguïté, l'incompatibilité, l'indisponibilité et
l'expiration sont distinguées. Les doublons ne sont jamais fusionnés pour
fabriquer une destination unique. Une réponse tronquée reste ambiguë.

Seule une preuve `live`, cohérente et récente peut lever le blocage de routage
**dans cette page de préparation**. Les résultats simulés ou issus du cache
ne le peuvent pas. La validité locale initiale est de quinze minutes, réglable
par `destination_validity_minutes` ; elle n'est pas une exigence officielle.
Le diagnostic purement local et les autres parcours restent inchangés.
« Destination qualifiée » ne signifie pas « Prête à être envoyée » : cette
fonction ne valide pas l'ensemble des conditions d'émission et ne déclenche
aucune conversion, génération distante ou émission.

Les lectures réelles ont leur propre interrupteur, fermé par défaut, et
n'activent ni le transport général ni le parcours sandbox Burger Queen → Tricatel.
