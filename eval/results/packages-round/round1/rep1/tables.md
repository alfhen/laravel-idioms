| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| draft | 8.13 | 1.36 | 31 | 0.98 | 8.27 |
| prev | 7.87 | 1.75 | 12 | 1.27 | 7.99 |
| plain | 6.49 | 2.75 | 1 | 1.30 | 7.55 |

| Task | draft | prev | plain |
|---|---|---|---|
| activity-feed | 5.50 | 8.60 | 6.25 |
| blade-catalogue | 7.65 | 7.65 | – |
| block-user | 7.40 | 7.00 | 7.15 |
| blog-crud | 8.20 | 8.25 | – |
| carrier-quote | 8.10 | 7.95 | – |
| document-vault | 8.60 | 7.00 | – |
| export-purge-command | 8.50 | 7.50 | – |
| filament-admin | 8.45 | 7.15 | – |
| invoice-uploads | 8.75 | 8.25 | 6.50 |
| markdown-comments | 8.70 | 8.45 | 7.00 |
| notes-search-api | 8.15 | 8.35 | – |
| orders-api | 8.60 | 8.70 | – |
| password-reset-links | 7.75 | 7.75 | 6.75 |
| payment-states | 8.25 | 8.65 | 5.75 |
| profile-patch | 8.65 | 7.90 | 6.80 |
| sales-report | 8.45 | 7.80 | – |
| shipment-sync | 8.20 | 7.40 | – |
| shipments-api | 8.50 | 8.15 | 6.05 |
| stock-csv-import | 8.25 | 7.75 | – |
| team-permissions | 8.25 | 7.00 | 6.00 |
| team-roles-page | 7.60 | 7.50 | 6.65 |
| token-login | 8.40 | 8.30 | – |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired | Read a reference |
|---|---|---|---|---|---|
| draft | 53 | $0.42 | 99s | 53/53 | 10/53 |
| plain | 10 | $0.26 | 82s | 0/10 | 0/10 |
| prev | 53 | $0.43 | 105s | 53/53 | 6/53 |
| skill | 53 | $0.41 | 92s | 53/53 | 9/53 |
| rev1 | 9 | $0.52 | 137s | 9/9 | 4/9 |

| Hidden tests | draft | plain | prev | skill |
|---|---|---|---|---|
| activity-feed | 100% (24/24, 3 builds) | 100% (8/8, 1 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) |
| block-user | 100% (36/36, 3 builds) | 100% (12/12, 1 builds) | 100% (36/36, 3 builds) | 100% (36/36, 3 builds) |
| carrier-quote | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| document-vault | 100% (18/18, 2 builds) | – | 100% (18/18, 2 builds) | 100% (18/18, 2 builds) |
| export-purge-command | 93% (13/14, 2 builds) | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| invoice-uploads | 100% (36/36, 3 builds) | 100% (12/12, 1 builds) | 100% (36/36, 3 builds) | 100% (36/36, 3 builds) |
| markdown-comments | 100% (27/27, 3 builds) | 100% (9/9, 1 builds) | 93% (25/27, 3 builds) | 100% (27/27, 3 builds) |
| notes-search-api | 100% (38/38, 2 builds) | – | 100% (38/38, 2 builds) | 100% (38/38, 2 builds) |
| orders-api | 100% (18/18, 2 builds) | – | 100% (18/18, 2 builds) | 100% (18/18, 2 builds) |
| password-reset-links | 100% (27/27, 3 builds) | 100% (9/9, 1 builds) | 100% (27/27, 3 builds) | 100% (27/27, 3 builds) |
| payment-states | 100% (27/27, 3 builds) | 100% (9/9, 1 builds) | 100% (27/27, 3 builds) | 100% (27/27, 3 builds) |
| profile-patch | 100% (51/51, 3 builds) | 100% (17/17, 1 builds) | 100% (51/51, 3 builds) | 100% (51/51, 3 builds) |
| shipment-sync | 100% (14/14, 2 builds) | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| shipments-api | 98% (50/51, 3 builds) | 100% (17/17, 1 builds) | 100% (51/51, 3 builds) | 100% (51/51, 3 builds) |
| stock-csv-import | 100% (20/20, 2 builds) | – | 100% (20/20, 2 builds) | 100% (20/20, 2 builds) |
| team-permissions | 97% (29/30, 3 builds) | 100% (10/10, 1 builds) | 97% (29/30, 3 builds) | 100% (30/30, 3 builds) |
| team-roles-page | 100% (10/10, 2 builds) | 100% (5/5, 1 builds) | 100% (10/10, 2 builds) | 100% (10/10, 2 builds) |
| token-login | 100% (16/16, 2 builds) | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| ALL | 99% (470/473, 45 builds) | 100% (108/108, 10 builds) | 99% (470/473, 45 builds) | 100% (473/473, 45 builds) |
| ALL without guest_without_accept | 99% (462/465, 45 builds) | 100% (108/108, 10 builds) | 99% (462/465, 45 builds) | 100% (465/465, 45 builds) |
| guest_without_accept | 100% (8/8, 8 builds) | – | 100% (8/8, 8 builds) | 100% (8/8, 8 builds) |
