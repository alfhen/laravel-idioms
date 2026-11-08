| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| mid | 7.98 | 1.46 | 25 | 1.28 | 7.98 |
| prev | 7.56 | 1.76 | 18 | 1.83 | 7.50 |
| plain | 6.95 | 2.50 | 3 | 1.10 | 7.75 |

| Task | mid | prev | plain |
|---|---|---|---|
| blade-catalogue | 7.35 | 7.70 | – |
| blog-crud | 8.45 | 8.25 | – |
| caching | 6.50 | 7.75 | – |
| carrier-quote | 7.50 | 8.75 | 5.75 |
| coupon-redeem | 8.60 | 8.10 | 6.65 |
| csv-import | 7.50 | 7.25 | – |
| document-vault | 8.55 | 8.15 | 7.00 |
| export-purge-command | 8.60 | 8.20 | – |
| inactive-count | 7.50 | 8.25 | – |
| orders-api | 9.00 | 6.50 | 7.25 |
| orders-customer-link | 7.45 | 6.30 | 7.90 |
| profile-nickname | 8.50 | 7.00 | 7.75 |
| queue-bugfix | 7.85 | 7.90 | – |
| sales-report | 8.15 | 8.10 | – |
| scheduled-reports | 8.90 | 7.45 | – |
| ship-order | 8.45 | 8.15 | 6.85 |
| shipment-sync | 8.70 | 6.90 | – |
| statement-batch | 6.50 | 6.25 | – |
| stock-csv-import | 7.50 | 8.15 | 6.50 |
| stock-sync-dispatcher | 8.40 | 6.75 | – |
| thread-mentions | 7.75 | 6.90 | 7.15 |
| token-login | 8.05 | 7.40 | 6.65 |
| webhook-worker | 7.80 | 7.75 | – |

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
