| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| skill | 7.79 | 1.73 | 15 | 1.83 | 7.77 |
| mid | 7.75 | 1.73 | 11 | 2.13 | 7.57 |
| prev | 6.95 | 2.53 | 4 | 2.87 | 6.73 |

| Task | skill | mid | prev |
|---|---|---|---|
| blade-catalogue | 8.40 | 7.00 | 6.00 |
| blog-crud | 6.80 | 8.15 | 8.20 |
| caching | 7.55 | 8.15 | 6.30 |
| cart-discounts | 6.60 | 8.15 | 7.10 |
| csv-import | 7.90 | 7.95 | 6.70 |
| export-purge-command | 7.20 | 8.45 | 7.85 |
| filament-admin | 8.00 | 7.65 | 6.80 |
| inactive-count | 7.00 | 7.25 | 8.00 |
| queue-bugfix | 8.80 | 8.00 | 6.60 |
| sales-report | 7.80 | 8.80 | 7.40 |
| scheduled-reports | 8.35 | 7.20 | 7.00 |
| shipment-sync | 8.75 | 7.70 | 7.40 |
| statement-batch | 8.25 | 6.75 | 6.00 |
| stock-sync-dispatcher | 7.70 | 7.40 | 6.25 |
| webhook-worker | 7.70 | 7.60 | 6.60 |

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
