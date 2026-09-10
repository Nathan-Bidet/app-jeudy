# Compactage de l'historique des cotations

## Pourquoi

`cotations:refresh` est planifiée **toutes les minutes** (`routes/console.php`).
Chaque passage insère une actualisation et 24 à 27 lignes de prix, soit environ
**1 440 actualisations et 36 000 lignes par jour**. Rien ne purgeait cette
table.

Relevé du 10/09/2026 sur le serveur :

| Table | Lignes | Données | Index |
|---|---:|---:|---:|
| `cotation_market_prices` | 2 701 531 | 259 Mo | 228 Mo |
| `cotation_market_refreshes` | 106 002 | 10 Mo | 4 Mo |

L'application ne lit pourtant **que le dernier relevé de chaque cotation**
(`CotationMarketService::latestMarketRowsByIdentity`, via un `MAX(id)` groupé).
Aucun écran, graphique, export PDF ou API ne parcourt l'historique : les
2,7 millions de lignes ne servent à rien en lecture.

## Politique retenue

* Les **7 derniers jours** conservent leur granularité complète.
* Toute journée civile **entièrement antérieure** à cette fenêtre est réduite à
  **un relevé par cotation**, celui dont l'horodatage est le plus proche de
  **15 h 00, heure `Europe/Paris`**.
* Une journée à cheval sur la fenêtre n'est jamais traitée. Avec 7 jours de
  rétention, ce sont donc 8 journées civiles qui restent détaillées.

### Clé métier

```
product_code + harvest_year + maturity_year + maturity_month + maturity_label
```

Ce sont exactement les colonnes de `CotationManualPrice::identityHash()` et du
`GROUP BY` de `CotationMarketService`. Compacter sur cette clé garantit
qu'aucune ligne affichée aujourd'hui ne peut disparaître de l'historique.

`contract_code` en est volontairement absent : il change d'un mois de cotation à
l'autre pour une même échéance et n'entre pas dans l'identité côté application.

### Horodatage

`created_at` fait foi.

`quoted_at` a été écarté après vérification : il est **nul sur 1 690 042 des
2 701 531 lignes** (63 %) et, quand il est renseigné, il porte des heures sans
rapport avec l'insertion (`quoted_at = 20:28` pour une ligne créée à `17:27`).
Il provient d'un champ libre de la source Euronext et n'est pas fiable.

`created_at` est renseigné sur **100 %** des lignes et ne s'écarte jamais de
plus de **10 secondes** du `fetched_at` de l'actualisation parente. Les lignes
dont `created_at` serait nul ne sont rattachables à aucune journée : elles sont
**protégées et jamais supprimées** (les bornes SQL de journée excluent `NULL`).

### Règle de sélection autour de 15 h

Pour chaque journée et chaque cotation :

1. l'écart le plus faible avec 15 h 00 l'emporte, avant **ou** après ;
2. à écart égal, le relevé **postérieur** à 15 h l'emporte ;
3. à égalité complète, l'**identifiant le plus élevé** l'emporte.

La sélection est faite **par cotation**, et non par actualisation entière. Les
actualisations de production contiennent 24, 25, 26 ou 27 lignes selon les
périodes : elles ne sont pas toujours complètes. Sélectionner par cotation
garantit qu'aucune céréale ni échéance présente ce jour-là ne disparaît. Quand
les actualisations sont complètes — le cas normal — tous les relevés retenus
proviennent de la même actualisation et l'instantané quotidien reste cohérent ;
la commande le signale par la mention `[instantané cohérent]`.

Les journées civiles sont découpées dans le fuseau métier, changements d'heure
compris : le 25/10 fait 25 heures, le 29/03 en fait 23, et 15 h reste 15 h
locales dans les deux cas.

## Ce que ça donne

Estimation sur les données du 10/09/2026 (84 journées, 27 cotations distinctes) :

| | Avant | Après |
|---|---:|---:|
| Lignes de prix | ~2 701 500 | **~263 000** |
| dont fenêtre détaillée (8 j) | — | ~261 000 |
| dont historique compacté (76 j) | ~2 440 000 | **~2 050** |
| Lignes supprimées | — | **~2 438 000 (90 %)** |
| Actualisations | ~106 000 | **~11 600** |

En régime permanent la table se stabilise autour de **250 000 lignes** et ne
grossit plus que de **27 lignes par jour** au lieu de 36 000.

## Commande

```bash
php artisan cotations:compact-history
```

| Option | Rôle |
|---|---|
| `--days=7` | Jours de granularité complète conservés |
| `--until=Y-m-d` | Ne pas compacter au-delà de cette date incluse |
| `--max-days=0` | Nombre maximal de journées traitées (0 = illimité) |
| `--batch=2000` | Lignes supprimées par lot |
| `--chunk=5000` | Lignes lues par lot lors de la sélection |
| `--sleep=0` | Pause en millisecondes entre deux lots de suppression |
| `--keep-refreshes` | Ne pas supprimer les actualisations orphelines |
| `--dry-run` | Simule sans rien supprimer |
| `--show-kept` | Détaille les relevés conservés et leur écart à 15 h |

Les réglages par défaut sont dans `config/cotations.php` et surchargeables par
variables d'environnement (`COTATIONS_COMPACT_*`).

### Garanties

* **Idempotente** : une journée déjà compactée ne contient plus qu'un relevé par
  cotation ; la sélection redésigne ce même relevé et aucune suppression n'a
  lieu. Relancer la commande autant de fois qu'on veut est sans effet.
* **Reprenable** : le traitement avance journée par journée, de la plus ancienne
  à la plus récente. Une interruption laisse au pire des lignes redondantes que
  la prochaine exécution supprimera. Aucun état à réinitialiser.
* **Mémoire bornée** : lecture par lots via le Query Builder, aucun modèle
  Eloquent hydraté. La mémoire dépend du nombre de cotations distinctes (27),
  pas du nombre de lignes.
* **Verrous courts** : chaque lot de suppression est une transaction implicite
  de `--batch` lignes. Aucune transaction ne couvre la table ni même une
  journée entière.
* **Garde-fou** : si une journée contient des lignes mais qu'aucune n'a été
  retenue, la journée est refusée au lieu d'être vidée.
* **Une journée en échec** est journalisée et n'empêche pas les suivantes ; la
  commande sort alors en code d'erreur.

## Actualisations orphelines

La clé étrangère `cot_price_refresh_fk` est en `ON DELETE CASCADE` : supprimer
une actualisation supprimerait ses cotations. Le nettoyage ne supprime donc que
les actualisations **n'ayant plus aucune ligne de prix, quelle qu'en soit la
journée**. Une actualisation de 23 h 59 dont les prix ont été insérés le
lendemain reste protégée, comme les 1 975 actualisations en échec qui n'ont
jamais eu de prix — celles-ci disparaissent avec leur journée, leur seule valeur
diagnostique étant l'`error_message` d'un incident vieux de plus d'une semaine.
`--keep-refreshes` désactive entièrement ce nettoyage.

## Planification

```php
Schedule::command('cotations:compact-history')
    ->dailyAt(config('cotations.compaction.schedule_time', '03:30'))
    ->timezone(config('app.timezone', 'Europe/Paris'))
    ->withoutOverlapping()
    ->onOneServer();
```

03 h 30 est une heure creuse ; **elle n'a rien à voir avec les 15 h**, qui sont
l'heure cible du relevé conservé. Aucune concurrence possible avec
`cotations:refresh` : l'import n'écrit que sur la journée en cours, le
compactage ne touche que des journées vieilles d'au moins huit jours.

## Index

`cotation_market_prices` n'avait aucun index sur `created_at`. Le découpage par
journée provoquait un balayage complet de 2,7 millions de lignes **par journée
traitée**. La migration `2026_09_10_180000_add_created_at_index_to_cotation_market_prices`
ajoute :

```sql
KEY cot_price_created_idx (created_at, id)
```

Il couvre les trois accès du traitement : la borne de journée (préfixe
`created_at`), la pagination par identifiant de la passe de sélection
(`ORDER BY id`) et la relecture des identifiants à supprimer, servie directement
par l'index.

MySQL 8 le crée en `ALGORITHM=INPLACE, LOCK=NONE` : les écritures de
`cotations:refresh` continuent pendant la création, qui prend une dizaine de
secondes sur 2,7 millions de lignes.

Les index existants sont conservés. `cot_price_quoted_idx` n'est utilisé par
aucune requête et pourrait être supprimé pour récupérer une trentaine de Mo,
mais ce n'est pas nécessaire au compactage et cela sort du périmètre.

### Plans d'exécution

Avant l'index :

```sql
EXPLAIN SELECT id FROM cotation_market_prices
 WHERE created_at >= '2026-08-01 00:00:00' AND created_at < '2026-08-02 00:00:00'
 ORDER BY id LIMIT 2000;
-- type=index, key=PRIMARY, rows≈2 700 000, Extra=Using where
```

Après l'index :

```sql
-- type=range, key=cot_price_created_idx, rows≈36 000, Extra=Using where; Using index
```

## Procédure de première exécution en production

### 1. Sauvegarde

**Obligatoire.** Aucune table d'archive n'est créée : les lignes supprimées le
sont définitivement, et créer un miroir de 2,4 millions de lignes ne résoudrait
pas le problème de volumétrie. La sauvegarde de production est le seul filet.

```bash
docker compose exec -T mysql mysqldump -u root -p appjeudy \
  cotation_market_prices cotation_market_refreshes \
  | gzip > /opt/backups/cotations-$(date +%F).sql.gz
```

### 2. Déploiement du code et de l'index

```bash
docker compose exec -T app sh -lc 'cd /var/www/html && php artisan migrate --force'
docker compose exec -T app sh -lc 'cd /var/www/html && php artisan optimize:clear'
```

### 3. Contrôle avant suppression

Simulation complète, sans aucune écriture :

```bash
php artisan cotations:compact-history --dry-run
```

Vérification détaillée des relevés qui seront conservés et de leur écart à
15 h, sur quelques journées :

```bash
php artisan cotations:compact-history --dry-run --show-kept --max-days=5
```

La colonne « Écart à la cible » doit afficher des écarts de l'ordre de la
minute (`+00:00:30`), et la ligne de journée doit porter la mention
`[instantané cohérent]`. Une journée affichant `[relevés issus de plusieurs
actualisations]` n'est pas un problème : cela signifie qu'une actualisation
était incomplète et que la cotation manquante a été reprise ailleurs.

### 4. Compactage progressif

Ne pas tout lancer d'un coup la première fois. Procéder par paquets, en
commençant par les journées les plus anciennes :

```bash
php artisan cotations:compact-history --max-days=5  --sleep=50
php artisan cotations:compact-history --max-days=20 --sleep=50
php artisan cotations:compact-history            --sleep=50
```

`--sleep=50` insère 50 ms entre deux lots pour laisser respirer la base.
Chaque commande peut être interrompue (`Ctrl+C`) sans risque et reprise.

### 5. Contrôles après traitement

```sql
-- Une seule ligne par cotation et par journée au-delà de la fenêtre ?
SELECT DATE(created_at) j, COUNT(*) lignes,
       COUNT(DISTINCT product_code, harvest_year, maturity_year, maturity_month, maturity_label) cotations
  FROM cotation_market_prices
 WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 8 DAY)
 GROUP BY DATE(created_at)
 HAVING lignes <> cotations;
-- doit renvoyer 0 ligne

-- Aucune cotation orpheline (la CASCADE n'a rien emporté à tort) ?
SELECT COUNT(*) FROM cotation_market_prices p
  LEFT JOIN cotation_market_refreshes r ON r.id = p.refresh_id
 WHERE r.id IS NULL;
-- doit renvoyer 0

-- Les heures conservées tournent bien autour de 15 h ?
SELECT HOUR(created_at) h, COUNT(*) FROM cotation_market_prices
 WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 8 DAY)
 GROUP BY h;
-- doit être concentré sur 14 et 15
```

Contrôle fonctionnel : ouvrir la page Cotations et l'export PDF, vérifier que
toutes les céréales et échéances sont présentes et que les prix sont inchangés.

### 6. Récupération de l'espace disque (optionnel, hors traitement)

InnoDB ne rend pas l'espace au système après un `DELETE` : les pages sont
réutilisées pour les insertions suivantes. La table restera donc à ~490 Mo sur
disque alors qu'elle ne contiendra plus que ~25 Mo de données utiles.

**La commande ne lance jamais cette opération automatiquement.** Si l'espace
disque doit être récupéré, à faire en fenêtre de maintenance :

```sql
OPTIMIZE TABLE cotation_market_prices;
```

Sur InnoDB, `OPTIMIZE TABLE` équivaut à `ALTER TABLE ... FORCE` : la table est
reconstruite en ligne, mais la fin de l'opération prend un verrou exclusif
court et il faut **autant d'espace disque libre que la taille de la table**.
Prévoir 1 à 2 minutes et couper `cotations:refresh` pendant l'opération.

## Retour arrière

* **Arrêter le compactage** : commenter l'entrée `cotations:compact-history`
  dans `routes/console.php`, ou poser `COTATIONS_COMPACT_RETENTION_DAYS` à une
  valeur très élevée (ex. `36500`) — plus aucune journée n'est alors éligible.
* **Annuler la migration d'index** : `php artisan migrate:rollback --step=1`.
  L'index est le seul objet créé ; aucune donnée n'en dépend.
* **Restaurer les données** : les lignes supprimées ne sont pas récupérables
  autrement que par la sauvegarde de l'étape 1. Restaurer les deux tables
  ensemble, la clé étrangère l'exige.

## Tests

`tests/Feature/CotationHistoryCompactionTest.php` — 26 tests couvrant la
fenêtre de rétention, la journée frontière, les six cas de sélection autour de
15 h, les céréales et échéances multiples, les actualisations incomplètes, les
doublons, `quoted_at` incohérent, `created_at` nul, les deux changements
d'heure, l'idempotence, la reprise après interruption, le mode simulation, le
nettoyage des actualisations parentes, la non-régression des écrans existants
et le comportement sur une journée de 1 440 lignes.

```bash
php artisan test tests/Feature/CotationHistoryCompactionTest.php
```
