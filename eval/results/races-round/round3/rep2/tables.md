| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| skill | 7.96 | 1.39 | 28 | 1.48 | 8.04 |
| prev | 7.65 | 1.61 | 18 | 1.78 | 7.58 |

| Task | skill | prev |
|---|---|---|
| blade-catalogue | 8.00 | 8.00 |
| blog-crud | 8.35 | 7.00 |
| caching | 8.25 | 6.15 |
| carrier-quote | 8.40 | 8.40 |
| coupon-redeem | 8.55 | 8.05 |
| csv-import | 7.00 | 7.00 |
| document-vault | 8.45 | 7.40 |
| export-purge-command | 7.85 | 8.35 |
| inactive-count | 7.50 | 8.65 |
| orders-api | 8.25 | 8.70 |
| orders-customer-link | 7.30 | 8.45 |
| profile-nickname | 9.00 | 7.90 |
| queue-bugfix | 7.95 | 8.15 |
| sales-report | 8.65 | 7.75 |
| scheduled-reports | 7.65 | 7.00 |
| ship-order | 7.50 | 8.00 |
| shipment-sync | 8.50 | 7.00 |
| statement-batch | 7.25 | 7.25 |
| stock-csv-import | 7.50 | 7.50 |
| stock-sync-dispatcher | 7.90 | 7.80 |
| thread-mentions | 8.35 | 8.05 |
| token-login | 8.00 | 6.50 |
| webhook-worker | 7.00 | 7.00 |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired | Read a reference |
|---|---|---|---|---|---|
| skill | 55 | $0.30 | 83s | 55/55 | 13/55 |
| prev | 55 | $0.32 | 91s | 55/55 | 9/55 |
| mid | 55 | $0.35 | 88s | 55/55 | 11/55 |
| draft | 55 | $0.37 | 96s | 55/55 | 18/55 |
| plain | 10 | $0.17 | 50s | 0/10 | 0/10 |

| Hidden tests | draft | mid | plain | prev | skill |
|---|---|---|---|---|---|
| carrier-quote | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) | 100% (8/8, 1 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) |
| coupon-redeem | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) | 83% (5/6, 1 builds) | 83% (15/18, 3 builds) | 100% (18/18, 3 builds) |
| document-vault | 100% (27/27, 3 builds) | 100% (27/27, 3 builds) | 89% (8/9, 1 builds) | 89% (24/27, 3 builds) | 100% (27/27, 3 builds) |
| export-purge-command | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| orders-api | 100% (27/27, 3 builds) | 100% (27/27, 3 builds) | 100% (9/9, 1 builds) | 100% (27/27, 3 builds) | 100% (27/27, 3 builds) |
| orders-customer-link | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) | 100% (6/6, 1 builds) | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) |
| profile-nickname | 100% (8/8, 2 builds) | 100% (8/8, 2 builds) | 75% (3/4, 1 builds) | 75% (6/8, 2 builds) | 100% (8/8, 2 builds) |
| queue-bugfix | 100% (12/12, 2 builds) | 100% (12/12, 2 builds) | – | 92% (11/12, 2 builds) | 100% (12/12, 2 builds) |
| scheduled-reports | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| ship-order | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) | 83% (5/6, 1 builds) | 83% (15/18, 3 builds) | 100% (18/18, 3 builds) |
| shipment-sync | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| statement-batch | 100% (8/8, 2 builds) | 100% (8/8, 2 builds) | – | 100% (8/8, 2 builds) | 100% (8/8, 2 builds) |
| stock-csv-import | 100% (30/30, 3 builds) | 100% (30/30, 3 builds) | 90% (9/10, 1 builds) | 90% (27/30, 3 builds) | 100% (30/30, 3 builds) |
| stock-sync-dispatcher | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| thread-mentions | 100% (15/15, 3 builds) | 100% (15/15, 3 builds) | 80% (4/5, 1 builds) | 80% (12/15, 3 builds) | 100% (15/15, 3 builds) |
| token-login | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) | 88% (7/8, 1 builds) | 88% (21/24, 3 builds) | 100% (24/24, 3 builds) |
| ALL | 100% (289/289, 41 builds) | 100% (289/289, 41 builds) | 90% (64/71, 10 builds) | 93% (268/289, 41 builds) | 100% (289/289, 41 builds) |
| ALL without guest_without_accept | 100% (266/266, 41 builds) | 100% (266/266, 41 builds) | 100% (63/63, 10 builds) | 100% (265/266, 41 builds) | 100% (266/266, 41 builds) |
| guest_without_accept | 100% (23/23, 23 builds) | 100% (23/23, 23 builds) | 12% (1/8, 8 builds) | 13% (3/23, 23 builds) | 100% (23/23, 23 builds) |
