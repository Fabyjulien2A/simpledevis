# Troisième scénario fictif : deux prestations S1 à 20 % et 10 %

`SandboxMultiRateInvoiceFixture` prépare `SANDBOX-FICTIF-MULTITAUX-0003`.
Le scénario réutilise la source fictive de base, ses contrôles d'identité et
d'absence de persistance, le contexte français S1 explicite et les trois notes
PMT/PMD/AAB. Le vrai moteur fiscal, le préparateur, le builder EN16931 et le
validateur OpenAPI local 1.39.0.beta sont exécutés sans modification de leurs règles.

Les parties sont entièrement fictives : noms signalant la fiction, SIREN/SIRET
à zéro, adresses fictives et communications `example.invalid`. Le numéro de TVA
à zéro provient de la fixture standard existante ; il ne représente pas une
immatriculation réelle. Aucun identifiant ni régime n'est attribué à une entreprise.

| Ligne | Quantité | Prix et montant HT | Catégorie | Taux | TVA de la ventilation |
|---|---|---|---|---|---|
| Prestation fictive 1 | 1.00 | 100.00 EUR | S | 20.00 % | 20.00 EUR |
| Prestation fictive 2 | 1.00 | 100.00 EUR | S | 10.00 % | 10.00 EUR |

Deux ventilations sont attendues, chacune avec base 100.00 EUR, respectivement
S/20.00 et S/10.00. HT total 200.00, TVA totale 30.00, TTC et dû 230.00 EUR.
Aucun règlement, avoir, acompte, frais, remise ou motif d'exonération.
BT-32 n'est pas ajouté à cette fixture. Montants et taux restent des chaînes
décimales ; les calculs utilisent les décimaux exacts du moteur existant.

## Simulation et futur essai

La commande suivante valide et affiche le payload local, sans réseau :

```sh
php artisan superpdp:sandbox-convert --scenario=multi-rate
```

Le scénario par défaut `standard` et le scénario `franchise` sont préservés.
La sélection est limitée à ces trois noms, sans injection de facture utilisateur.

Après une future autorisation explicite et activation de
`SUPERPDP_SANDBOX_CONVERSION_ENABLED=true` en environnement local/testing :

```sh
php artisan superpdp:sandbox-convert --scenario=multi-rate --execute
```

Cette commande d'exécution n'a pas été lancée. Elle conserve le probe existant :
un seul POST de conversion, sans OAuth, redirection, retry, dépôt, émission ou
persistance. Le drapeau de configuration reste une condition nécessaire pour
une exécution explicite ; son activation seule ne déclenche aucun appel.

## Validation locale et limites

Les tests vérifient les deux lignes, leur ordre et identifiants, les prix,
quantités, catégories et taux, les deux ventilations exactes, les totaux, le
contexte S1, l'absence d'exonération et la compatibilité OpenAPI. Les cas négatifs
portent sur une ligne manquante, un changement de taux incompatible avec les
totaux stockés, un taux nul ou non supporté, E/Z à taux positif, un motif
d'exonération incompatible, un montant de ligne ou total incohérent et des
propriétés JSON invalides sur la deuxième ligne ou ventilation.

Le moteur reste générique : ces tests ne rendent pas obligatoire la présence de
deux taux sur toutes les factures et ne déterminent pas l'éligibilité réelle
d'une prestation au taux de 10 %. Une compatibilité OpenAPI est structurelle,
sans certification EN16931, française ou réglementaire.

Aucun CII de ce troisième scénario n'a encore été reçu. La conversion effective,
la conservation des deux ventilations dans le CII et sa validation complète
restent à vérifier ultérieurement. Aucun XML synthétique n'est ajouté comme preuve.

Les deux CII réels et leurs chargeurs/contrôles existants restent inchangés :

- Standard : `3e68e161036a1d2cd8b92b1eaabcef49206fa7e74a29ea7e0b1b2b9c653a67fe`.
- Franchise : `c6a1f6c5e205662e1416c6334dbbf0bbeba5cc7bafe617baaf0dbdc7abf15735`.

Les tests de la nouvelle fixture interdisent toute requête SQL et vérifient
l'absence d'HTTP. Les suites sont exécutées avec APP_ENV=testing, SQLite en
mémoire, DB_URL vide et conversion désactivée. `allow_url_fopen=0` et les fonctions
`curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,socket_connect`
sont désactivés. Un bootstrap temporaire remplace les deux fonctions cURL par des
fonctions levant immédiatement une exception pour permettre l'initialisation de
Guzzle avec les réponses simulées des tests de transport existants.
Aucun accès MariaDB Alwaysdata, appel réseau ou émission n'est effectué.
Les PDF, la réception, les migrations et les factures existantes sont inchangés.

## Résultats

- 22 nouveaux cas ciblés sur le scénario multi-taux.
- Tests unitaires SUPER PDP : **726 réussis, 3 145 assertions** (21,00 s).
- Suite PHP complète : **1 204 réussis, 5 846 assertions** (46,06 s).
- Pint : vérification réussie sur les quatre fichiers PHP concernés.
- Empreintes des deux références CII inchangées après les tests.
- Bootstrap temporaire de blocage réseau supprimé après les suites.

Le troisième scénario est prêt techniquement pour un futur essai manuel
explicitement autorisé. Aucun succès distant n'est revendiqué à cette étape.
