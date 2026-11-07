| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| prev | 8.00 | 2.00 | 2 | 0.75 | 8.44 |
| skill | 7.99 | 2.00 | 2 | 1.12 | 8.38 |
| late | 7.66 | 2.00 | 4 | 1.62 | 7.62 |

| Task | prev | skill | late |
|---|---|---|---|
| blog-crud | 8.60 | 8.30 | 6.90 |
| export-purge-command | 8.10 | 8.25 | 7.80 |
| inactive-count | 7.90 | 8.25 | 7.65 |
| scheduled-reports | 7.40 | 7.15 | 8.30 |

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
