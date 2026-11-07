# Essai isolé de conversion EN16931 vers CII

## Contrat consulté

Source officielle : https://api.superpdp.tech/openapi/superpdp.json ; copie locale
`resources/schemas/superpdp/1.39.0.beta/openapi.json` (version **1.39.0.beta**, OpenAPI **3.0.4**).
La provenance et l'empreinte figurent dans `resources/schemas/superpdp/README.md`.

- `POST /v1.beta/invoices/convert?from=en16931&to=cii`.
- `from` et `to` obligatoires, enum `cii`, `ubl`, `en16931`, `factur-x`.
- Corps JSON direct `en_invoice`, obligatoire, sans enveloppe `en_invoice`.
- `Content-Type: application/json`, `Accept: application/xml`.
- Succès documenté : `200`, `application/xml` pour CII. Aucun schéma XML ou
  rapport de conformité n'est défini dans cette réponse.
- Erreurs documentées : `400` et `500`, JSON `http_ko` : `http_status_code`
  (integer) et `message` (string) obligatoires, `code` (integer) facultatif.
  Le texte `message` n'est pas un code stable à interpréter automatiquement.
- `security: []` sur cette opération : **pas d'authentification requise**.
  Le schéma global OAuth ne s'applique donc pas ici. Aucun en-tête sandbox
  ni serveur distinct de conversion sandbox n'est déclaré par cette OpenAPI.

Le client existant Laravel HTTP est réutilisé. Le service OAuth de réception
renouvelle et persiste des jetons ; il n'est pas utilisé. Le SDK Saloon installé
supporte aussi la conversion, mais n'est pas nécessaire pour cet appel isolé.
Aucun secret ni jeton de réception n'est lu depuis la base ou envoyé.

## Exemple et contrôles

`SandboxInvoiceFixture` remplace uniquement la lecture des données par une source
fixe en mémoire. La facture n'a aucun ID et `exists` reste faux : aucune fausse
persistance n'est déclarée. Les identités, SIRET/TVA à zéro, adresses et adresses
électroniques `example.invalid` sont des valeurs fictives, sans inscription annuaire.

Le **vrai** `SuperPdpInvoicePayloadBuilder` est exécuté, ainsi que
`InvoiceFiscalDataPreparer` et `SuperPdpInvoicePayloadValidator` : statut issued,
version decimal-v1, aucun règlement/avoir, une ligne C62 de quantité 1.00 à
100.00 HT, catégorie S à 20.00 %, TVA 20.00 et TTC/dû 120.00.
La lecture persistée du parcours métier normal n'est ni modifiée ni activée.

Le contexte français est maintenant obligatoire et indépendant des calculs :
le quatrième argument `frenchContext` du builder contient `business_process_type`
(sélection explicite parmi `B1`, `S1`, `M1`) et `notes` (une occurrence de chaque
`subject_code` PMT, PMD, AAB avec son texte `note` non blanc). Les autres cadres,
les codes inconnus, doublons et textes manquants sont refusés. BT-24 est inchangé.
Les textes sont conservés tels que fournis, sans reformulation ni déduction métier.

Pour **cette fixture seulement**, `SandboxInvoiceFixture::frenchContext()` déclare
S1 (prestation ordinaire) et reprend exactement les textes du PDF existant :
PMT « 40 € dus en cas de retard de paiement. », PMD « 10% du montant TTC. »,
AAB « Néant. ». Les montants et identifiants ne changent pas.
Ces textes ne sont jamais des valeurs par défaut pour les factures réelles.
Leur contexte doit être fourni explicitement par l'appelant : le profil, les notes
libres, les descriptions, unités et taux ne sont pas interprétés pour le compléter.
La saisie, la conservation et l'historisation de ce contexte ne sont pas encore implémentées.

## Commandes

Simulation par défaut, sans réseau ni base de données :

```sh
php artisan superpdp:sandbox-convert
```

Après accord explicite seulement, en environnement `APP_ENV=local` (ou testing),
activer `SUPERPDP_SANDBOX_CONVERSION_ENABLED=true` dans la configuration locale,
puis exécuter :

```sh
php artisan superpdp:sandbox-convert --execute
```

Le drapeau de configuration est faux par défaut. Le mode simulation ne fait aucun
appel même si ce drapeau est vrai. L'outil refuse tout autre environnement, toute
autre URL de base que `https://api.superpdp.tech/v1.beta`, tout document utilisateur
et tout autre format ou endpoint. L'URL existante `services.superpdp.api_url` est
contrôlée. Une configuration mise en cache doit être actualisée avant l'essai.

La réservation « sandbox » est une restriction locale de cet outil. Puisque la
conversion est publique et le serveur commun, elle ne prouve pas l'identité d'un
compte sandbox distant. Ne pas inventer un marqueur d'authentification pour cela.

## Réponses et limites

Un appel explicite effectue au maximum **un POST**, sans redirection, retry,
authentification, renouvellement OAuth, dépôt ou émission. Aucun fichier ou facture
n'est enregistré ; le XML est affiché au terminal. Le code retour est 0 pour la
simulation ou la réception d'un CII XML bien formé, 1 pour un refus ou une erreur.

Le succès exige HTTP 200, `application/xml` et une racine `CrossIndustryInvoice`.
La lecture XML interdit les DTD et utilise `LIBXML_NONET`. Il ne s'agit pas d'une
validation XSD, Schematron, EN16931, fiscale, de profil ou de routage.

Les autres statuts (y compris 401/403/429/3xx non détaillés dans l'opération),
erreurs de connexion et réponses inattendues sont affichés sans exception HTTP
brute. Les diagnostics sont bornés et les secrets/jetons masqués. Un éventuel
rapport présent dans une réponse JSON est affiché comme diagnostic non contractuel :
aucune propriété de rapport n'est supposée et `/validation_reports` n'est jamais appelé.

Les identifiants fictifs peuvent être refusés par des contrôles distants non décrits
dans le contrat de conversion. Leur acceptation ne sera connue qu'après l'essai
autorisé. Aucun appel réel de conversion ne fait partie de l'implémentation ou des tests.
