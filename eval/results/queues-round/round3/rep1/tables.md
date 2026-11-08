| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| skill | 7.78 | 1.80 | 13 | 2.13 | 7.62 |
| mid | 7.47 | 1.97 | 11 | 1.67 | 7.50 |
| prev | 7.27 | 2.23 | 6 | 2.13 | 7.32 |

| Task | skill | mid | prev |
|---|---|---|---|
| blade-catalogue | 8.45 | 7.40 | 7.20 |
| blog-crud | 6.50 | 8.00 | 8.65 |
| caching | 7.30 | 7.15 | 7.20 |
| cart-discounts | 8.10 | 8.65 | 6.25 |
| csv-import | 7.90 | 7.15 | 8.00 |
| export-purge-command | 7.30 | 7.95 | 7.80 |
| filament-admin | 7.40 | 8.60 | 7.05 |
| inactive-count | 8.25 | 8.00 | 6.75 |
| queue-bugfix | 8.70 | 7.70 | 6.40 |
| sales-report | 7.00 | 8.30 | 7.55 |
| scheduled-reports | 8.75 | 6.90 | 8.15 |
| shipment-sync | 8.05 | 6.60 | 7.70 |
| statement-batch | 7.65 | 5.50 | 5.75 |
| stock-sync-dispatcher | 7.75 | 7.75 | 6.50 |
| webhook-worker | 7.65 | 6.45 | 8.10 |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired | Read a reference |
|---|---|---|---|---|---|
| draft | 27 | $0.40 | 138s | 27/27 | 12/27 |
| mid | 37 | $0.38 | 109s | 37/37 | 10/37 |
| prev | 37 | $0.37 | 106s | 37/37 | 9/37 |
| skill | 39 | $0.38 | 116s | 39/39 | 11/39 |
| late | 10 | $0.35 | 100s | 10/10 | 6/10 |
| plain | 7 | $0.27 | 88s | 0/7 | 0/7 |
| four | 3 | $0.36 | 93s | 3/3 | 3/3 |

| Hidden tests | draft | four | late | mid | plain | prev | skill |
|---|---|---|---|---|---|---|---|
| export-purge-command | 76% (16/21, 3 builds) | – | 100% (21/21, 3 builds) | 100% (21/21, 3 builds) | 100% (7/7, 1 builds) | 100% (21/21, 3 builds) | 100% (21/21, 3 builds) |
| queue-bugfix | 94% (17/18, 3 builds) | – | – | 94% (17/18, 3 builds) | 100% (6/6, 1 builds) | 94% (17/18, 3 builds) | 94% (17/18, 3 builds) |
| scheduled-reports | 92% (22/24, 3 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) | 100% (8/8, 1 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) |
| shipment-sync | 100% (21/21, 3 builds) | – | – | 100% (21/21, 3 builds) | 100% (7/7, 1 builds) | 100% (21/21, 3 builds) | 100% (21/21, 3 builds) |
| statement-batch | 100% (12/12, 3 builds) | – | – | 100% (12/12, 3 builds) | 100% (4/4, 1 builds) | 100% (12/12, 3 builds) | 100% (12/12, 3 builds) |
| stock-sync-dispatcher | 100% (24/24, 3 builds) | – | – | 100% (24/24, 3 builds) | 100% (8/8, 1 builds) | 88% (21/24, 3 builds) | 100% (24/24, 3 builds) |
| ALL | 93% (112/120, 18 builds) | 100% (24/24, 3 builds) | 100% (45/45, 6 builds) | 99% (119/120, 18 builds) | 100% (40/40, 6 builds) | 97% (116/120, 18 builds) | 99% (119/120, 18 builds) |
