| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| draft | 7.79 | 1.57 | 25 | 1.48 | 7.82 |
| prev | 7.67 | 1.74 | 18 | 1.80 | 7.74 |
| plain | 7.11 | 2.30 | 3 | 1.05 | 8.00 |

| Task | draft | prev | plain |
|---|---|---|---|
| blade-catalogue | 8.00 | 7.25 | – |
| blog-crud | 8.00 | 8.60 | – |
| caching | 7.40 | 7.75 | – |
| carrier-quote | 7.65 | 8.75 | 6.25 |
| coupon-redeem | 6.85 | 8.40 | 6.70 |
| csv-import | 7.00 | 7.50 | – |
| document-vault | 7.90 | 8.65 | 7.00 |
| export-purge-command | 7.70 | 8.05 | – |
| inactive-count | 8.35 | 8.35 | – |
| orders-api | 5.50 | 7.75 | 7.50 |
| orders-customer-link | 7.50 | 6.50 | 7.50 |
| profile-nickname | 8.45 | 7.90 | 8.50 |
| queue-bugfix | 8.00 | 7.60 | – |
| sales-report | 8.00 | 8.00 | – |
| scheduled-reports | 8.80 | 7.40 | – |
| ship-order | 8.00 | 8.75 | 7.15 |
| shipment-sync | 8.50 | 7.00 | – |
| statement-batch | 7.25 | 6.50 | – |
| stock-csv-import | 6.25 | 8.15 | 6.65 |
| stock-sync-dispatcher | 8.40 | 6.80 | – |
| thread-mentions | 8.65 | 6.55 | 7.35 |
| token-login | 8.80 | 6.85 | 6.45 |
| webhook-worker | 8.20 | 7.30 | – |

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
