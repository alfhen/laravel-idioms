| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| prev | 7.69 | 1.50 | 14 | 1.33 | 7.77 |
| skill | 7.65 | 1.62 | 10 | 1.21 | 7.90 |
| plain | 6.09 | 2.70 | 0 | 1.80 | 6.40 |

| Task | prev | skill | plain |
|---|---|---|---|
| caching | 6.25 | 7.40 | – |
| carrier-quote | 8.25 | 7.75 | – |
| coupon-redeem | 8.50 | 7.90 | – |
| orders-today | 8.10 | 8.15 | 6.75 |
| persistent-valkey | 7.00 | 4.50 | 4.50 |
| sales-report | 7.65 | 8.50 | – |
| scheduled-reports | 8.25 | 7.80 | – |
| shipment-sync | 7.75 | 7.85 | – |
| token-login | 8.00 | 8.60 | – |
| valkey-cache | 8.10 | 7.25 | 6.55 |
| valkey-outage | 8.40 | 7.90 | 5.65 |
| valkey-queue | 6.00 | 8.25 | 7.00 |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired | Read a reference |
|---|---|---|---|---|---|
| prev | 28 | $0.49 | 139s | 28/28 | 10/28 |
| skill | 28 | $0.45 | 111s | 28/28 | 11/28 |
| plain | 5 | $0.35 | 128s | 0/5 | 0/5 |
| rev1 | 12 | $0.52 | 138s | 12/12 | 8/12 |
| draft | 28 | $0.45 | 105s | 28/28 | 11/28 |

| Hidden tests | draft | plain | prev | rev1 | skill |
|---|---|---|---|---|---|
| carrier-quote | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) |
| coupon-redeem | 100% (12/12, 2 builds) | – | 100% (12/12, 2 builds) | – | 100% (12/12, 2 builds) |
| orders-today | 100% (8/8, 2 builds) | 100% (4/4, 1 builds) | 100% (8/8, 2 builds) | – | 100% (8/8, 2 builds) |
| persistent-valkey | 100% (45/45, 3 builds) | 73% (11/15, 1 builds) | 73% (33/45, 3 builds) | 100% (45/45, 3 builds) | 100% (45/45, 3 builds) |
| scheduled-reports | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) |
| shipment-sync | 100% (14/14, 2 builds) | – | 100% (14/14, 2 builds) | – | 100% (14/14, 2 builds) |
| token-login | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) |
| valkey-cache | 100% (18/18, 3 builds) | 100% (6/6, 1 builds) | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) | 100% (18/18, 3 builds) |
| valkey-outage | 100% (21/21, 3 builds) | 100% (7/7, 1 builds) | 100% (21/21, 3 builds) | 100% (21/21, 3 builds) | 100% (21/21, 3 builds) |
| valkey-queue | 100% (15/15, 3 builds) | 100% (5/5, 1 builds) | 100% (15/15, 3 builds) | 100% (15/15, 3 builds) | 100% (15/15, 3 builds) |
| ALL | 100% (181/181, 24 builds) | 89% (33/37, 5 builds) | 93% (169/181, 24 builds) | 100% (99/99, 12 builds) | 100% (181/181, 24 builds) |
| ALL without guest_without_accept | 100% (177/177, 24 builds) | 89% (33/37, 5 builds) | 93% (165/177, 24 builds) | 100% (99/99, 12 builds) | 100% (177/177, 24 builds) |
| guest_without_accept | 100% (4/4, 4 builds) | – | 100% (4/4, 4 builds) | – | 100% (4/4, 4 builds) |
