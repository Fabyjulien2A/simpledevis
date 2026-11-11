# Création des factures : rapprochement code / schéma

Contrôles du 11 novembre 2026. Aucun accès à la base de production.

## Diagnostic

`InvoiceController::store()` écrit toujours `emission_profile`, même pour une
facture ordinaire dont la valeur est NULL. La colonne provient de
`2026_11_09_000001_add_invoice_emission_preparation_fields.php`.
L'erreur 1054 communiquée prouve son absence dans la table ciblée par le POST.
Elle ne permet pas de connaître le reste du schéma de production ni son registre
de migrations : ceux-ci n'ont pas été consultés.

Le modèle et le contrôleur sont cohérents avec les migrations locales. Aucun
changement métier n'est nécessaire. Omettre la colonne à l'insertion masquerait
le décalage et laisserait les parcours de finalisation incomplets.

La migration ajoute cinq colonnes NULL, sans remplir l'historique :

| Table | Colonnes |
| --- | --- |
| companies | country_code CHAR(2), billing_electronic_address JSON |
| clients | billing_electronic_address JSON |
| invoices | emission_profile VARCHAR(32), emission_context JSON |

## Correspondance des écritures de création

Noms de fichiers dans `database/migrations/` (suffixe `.php`).

| Table / colonnes utilisées | Migration |
| --- | --- |
| invoices : user_id, client_id, quote_id, invoice_number, date, status, subtotal_ht, total_tva, total_ttc, notes, created_at, updated_at | 2026_03_28_151324_create_invoices_table |
| invoices.amount_paid | 2026_03_30_174709_add_amount_paid_to_invoices_table |
| invoices.due_date | 2026_04_01_164718_add_due_date_to_invoices_table |
| invoices.document_status | 2026_10_17_125234_add_document_status_to_invoices_table |
| invoices.invoice_number nullable (brouillon avant numérotation) | 2026_10_17_131219_make_invoice_number_nullable_on_invoices_table |
| invoice_counters : user_id, year, last_number, timestamps et unicité par utilisateur/année | 2026_10_17_155630_create_invoice_counters_table |
| invoices : unicité user_id + invoice_number | 2026_10_17_160928_add_unique_invoice_number_to_invoices_table |
| invoices : suppression de l'ancienne unicité globale invoice_number | 2026_11_05_000003_remove_global_invoice_number_unique_index |
| invoices.calculation_version | 2026_11_05_000002_add_calculation_version_to_invoices_table |
| invoices.french_business_process_type | 2026_11_07_000003_add_french_business_process_type_to_invoices_table |
| invoices.party_snapshot (capture à la finalisation) | 2026_11_08_000001_add_party_snapshot_to_invoices_table |
| invoices.emission_profile, emission_context ; adresses électroniques des parties | 2026_11_09_000001_add_invoice_emission_preparation_fields |
| invoice_items : invoice_id, description, quantity, unit_price_ht, tva_rate, line_total_ht, timestamps | 2026_03_28_151331_create_invoice_items_table |
| invoice_items : vat_category, exemption_reason_code, exemption_reason, unit_code | 2026_11_05_000001_add_tax_metadata_to_invoice_items_table |
| companies.vat_regime (lecture lors de la capture) | 2026_11_07_000001_add_vat_regime_to_companies_table |
| companies : trois mentions invoice_*_text (lecture lors de la capture) | 2026_11_07_000002_add_french_invoice_mentions_to_companies_table |

Les champs électroniques historiques `is_electronic`, `pdp_id`, `pdp_status`
proviennent du 21 avril 2026 ; `electronic_format`, `pdp_reference`,
`xml_generated_at`, `sent_to_pdp_at` sont complétés le 24 avril. Ils ne sont pas
nécessaires à l'INSERT ordinaire mais leur présence est aussi contrôlée.

## Vérifications effectivement réalisées sur MariaDB isolée

Le lanceur `tools/check-invoice-schema-test.php` lit exclusivement
`.env.mariadb-test`. Il exige le nom de base ET le compte SQL
`fabyjulien_simpledevis_test`, contrôle `DATABASE()`, `CURRENT_USER()`, les droits
et les bases visibles avant les essais. Il refuse tout droit sur une autre base
métier. Laravel utilise ensuite uniquement le PDO déjà contrôlé ; aucune lecture
du `.env` principal ni de son cache de configuration n'est nécessaire.

Résultats :

- MariaDB 10.11.18, compte dédié, deux déclarations de droits, production inaccessible.
- Toutes les colonnes nécessaires et l'index unique par utilisateur étaient déjà
  présents dans la base de test ; migration du 9 novembre déjà enregistrée.
- Reproduction de 42S22/1054 sur des tables TEMPORARY préfixées ; exécution du
  véritable `up()` du 9 novembre, insertion ensuite réussie, colonnes NULL et
  ligne témoin préservée. Même contrôle du `up()` OAuth du 10 novembre.
- La seule migration réellement en attente, appliquée sur la base de test :
  `2026_11_10_000001_add_oauth_context_to_superpdp_connections`.
  Elle ajoute `environment`, `oauth_client_id`, `api_url`, tous NULL, sans
  requalifier les connexions anciennes. Elle n'est pas le correctif de l'erreur invoices.
- Deux POST `/invoices` traités en mémoire par le véritable noyau HTTP Laravel :
  brouillon id 73 et facture définitive id 74, redirections 302, HT 100,00,
  TVA 20,00, TTC 120,00 ; ligne fiscale, version de calcul, numérotation et
  instantané de finalisation contrôlés. Aucun appel HTTP distant.
- Empreintes SHA-256 en mémoire : les 327 lignes préexistantes de 31 tables
  métier restent inchangées (projection des anciennes colonnes pour les ajouts
  de schéma). Le registre des migrations est exclu de cette comparaison.
- Fixtures clairement fictives conservées dans la seule base de test : utilisateur
  `TEST SCHEMA …`, adresse `@example.invalid`, client `TEST FICTIF …`, deux
  factures et leurs lignes, compteur propre à ce nouvel utilisateur. Aucun SIRET inventé.
- Aucune migration en attente après les essais. Aucun appel `down()`, aucune
  remise à zéro, aucune suppression ; les tables temporaires disparaissent à la
  fermeture de la session SQL.

Commande de lecture seule (elle contacte uniquement la base de test) :

```powershell
php tools/check-invoice-schema-test.php --audit
```

Rejouer les essais ci-dessous crée de nouvelles fixtures dans la base de test.
Ce n'est pas nécessaire après les résultats ci-dessus :

```powershell
php -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail tools/check-invoice-schema-test.php --exercise
```

Le réseau SQL reste disponible pour le seul PDO contrôlé ; les transports HTTP
natifs sont désactivés et Laravel/Saloon refusent les requêtes non simulées.
Le transport électronique reste désactivé. Aucun changement de Stripe, des PDF
ou de la réception n'est inclus.

## Production : procédure future, non exécutée

1. Obtenir l'autorisation distincte de maintenance de production, sauvegarder la
   base avec une restauration vérifiée et suspendre les écritures pendant le
   rapprochement. Vérifier le code et les migrations effectivement déployés.
2. Avec la connexion de production explicitement sélectionnée, vérifier son nom
   réel et les droits. Comparer `information_schema.COLUMNS` pour toutes les
   colonnes du tableau ci-dessus, les index et le registre `migrations`.
   Ne pas déduire l'état de production de celui de la base de test.
3. Si les cinq colonnes du 9 novembre sont absentes ET que sa migration n'est
   pas enregistrée, appliquer uniquement ce fichier, avec le code revu :

   ```powershell
   php artisan migrate --database=mysql --path=database/migrations/2026_11_09_000001_add_invoice_emission_preparation_fields.php --force
   ```

   Cette commande est valable seulement après confirmation que la connexion
   Laravel nommée `mysql` cible bien la production voulue. Elle n'a pas été lancée.
   Si d'autres dépendances du tableau manquent, préparer leur liste exacte et
   appliquer uniquement les migrations approuvées dans leur ordre chronologique.
   Ne pas lancer un `migrate` global incluant les évolutions OAuth/émission.
4. Si la migration est enregistrée malgré une colonne absente, ou si certaines
   de ses cinq colonnes existent déjà, arrêter : le `up()` n'est pas idempotent.
   Préparer une migration de réparation ciblée et la tester sur la base isolée
   avant une nouvelle autorisation. Ne pas supprimer de lignes du registre.
   Les DDL MariaDB peuvent être validés séparément : ne pas supposer un retour
   automatique à l'état initial en cas d'échec entre deux tables.
5. Vérifier les cinq colonnes NULL, les contraintes, le registre et la cohérence
   de toutes les dépendances. Aucun remplissage ni réécriture des factures
   historiques. Vérifier ensuite l'application et ses journaux expurgés. Une
   création de contrôle en production constituerait une écriture supplémentaire
   nécessitant une autorisation explicite ; elle n'a pas été effectuée ici.

## Diagnostic SUPER PDP

`tools/superpdp-client-credentials.php --check` : mode offline,
authentification non tentée, environnement du compte non vérifié, aucune erreur,
**0 requête**. Les secrets restent en mémoire et ne sont pas affichés.

Le transport réel autorise uniquement, dans cet ordre :

1. POST `https://api.superpdp.tech/oauth2/token`, Client Credentials.
2. GET `https://api.superpdp.tech/v1.beta/companies/me`, avec le jeton reçu.

Toute autre URL, redirection, requête supplémentaire ou profil non sandbox est
refusé. TLS vérifié, réponses en mémoire, aucun jeton persisté, aucun Laravel/SQL.
L'origine API est commune : seul le retour `env=sandbox` confirme effectivement
l'environnement du compte ; un retour production est refusé après sa lecture.
Le mode offline ne prouve donc pas la validité des identifiants ni le type réel
du compte. Les 55 tests simulés passent ; leur isolation des facades Laravel a
été corrigée pour permettre leur exécution après d'autres tests Laravel.

Premier essai réseau prêt techniquement, mais soumis à une autorisation distincte :

```powershell
php tools/superpdp-client-credentials.php --execute --confirm-sandbox-account
```

Cette commande n'a pas été exécutée. Elle ne convertit, ne valide et ne dépose
aucune facture. L'émission demeure verrouillée.

## Tests locaux

256 tests réussis, 574 assertions : calcul des montants, préparation des lignes,
instantanés de parties et diagnostic Client Credentials. Les appels natifs HTTP
étaient désactivés. S'y ajoutent les deux POST réels sur MariaDB et les contrôles
de migration sur tables temporaires décrits ci-dessus.
La suite Feature utilisant `RefreshDatabase` n'a pas été lancée : elle exécute
une remise à zéro du schéma, interdite pour cette intervention.
