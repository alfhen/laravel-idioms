| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| late | 7.96 | 1.75 | 4 | 1.12 | 8.12 |
| prev | 7.85 | 1.88 | 3 | 0.88 | 8.50 |
| skill | 7.50 | 2.38 | 1 | 1.25 | 7.88 |

| Task | late | prev | skill |
|---|---|---|---|
| blog-crud | 6.90 | 8.70 | 8.15 |
| export-purge-command | 8.05 | 7.80 | 7.20 |
| inactive-count | 8.40 | 6.90 | 8.15 |
| scheduled-reports | 8.50 | 8.00 | 6.50 |

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
