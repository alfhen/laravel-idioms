| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| skill | 8.09 | 1.36 | 30 | 1.02 | 8.14 |
| prev | 7.43 | 1.91 | 10 | 1.82 | 7.31 |
| plain | 6.69 | 2.40 | 4 | 1.55 | 7.35 |

| Task | skill | prev | plain |
|---|---|---|---|
| acme-webhooks | 7.40 | 8.00 | 4.65 |
| blade-catalogue | 6.75 | 7.60 | – |
| blog-crud | 8.25 | 6.75 | – |
| carrier-quote | 8.65 | 7.30 | – |
| cart-discounts | 7.70 | 8.30 | – |
| export-purge-command | 8.40 | 7.90 | – |
| filament-admin | 8.25 | 6.75 | – |
| inertia-projects | 7.45 | 6.90 | 7.25 |
| invoice-totals | 8.05 | 8.30 | 5.65 |
| livewire-notes | 6.50 | 7.50 | 8.00 |
| notes-search-api | 9.00 | 6.50 | 6.75 |
| orders-api | 8.00 | 6.75 | – |
| partner-sync | 8.50 | 6.75 | 7.50 |
| payments-errors | 7.75 | 6.75 | 6.50 |
| sales-report | 8.60 | 7.60 | – |
| scheduled-reports | 8.55 | 7.65 | – |
| shipment-sync | 8.15 | 8.40 | – |
| stock-csv-import | 7.85 | 7.10 | – |
| subscription-renewals | 8.80 | 8.30 | 6.85 |
| team-activity | 8.90 | 8.50 | 7.90 |
| team-invitations | 8.70 | 7.90 | 5.85 |
| token-login | 7.75 | 6.00 | – |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired | Read a reference |
|---|---|---|---|---|---|
| plain | 10 | $0.24 | 75s | 0/10 | 0/10 |
| prev | 53 | $0.34 | 92s | 53/53 | 8/53 |
| rev | 53 | $0.40 | 107s | 53/53 | 14/53 |
| skill | 53 | $0.39 | 93s | 53/53 | 19/53 |

| Hidden tests | plain | prev | rev | skill |
|---|---|---|---|---|
| acme-webhooks | 100% (13/13, 1 builds) | 100% (39/39, 3 builds) | 100% (39/39, 3 builds) | 100% (39/39, 3 builds) |
| carrier-quote | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| export-purge-command | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| inertia-projects | 100% (18/18, 1 builds) | 100% (54/54, 3 builds) | 100% (54/54, 3 builds) | 100% (54/54, 3 builds) |
| invoice-totals | 100% (13/13, 1 builds) | 100% (39/39, 3 builds) | 100% (39/39, 3 builds) | 100% (39/39, 3 builds) |
| livewire-notes | 100% (20/20, 1 builds) | 100% (60/60, 3 builds) | 100% (60/60, 3 builds) | 100% (60/60, 3 builds) |
| notes-search-api | 100% (19/19, 1 builds) | 100% (57/57, 3 builds) | 100% (57/57, 3 builds) | 100% (57/57, 3 builds) |
| orders-api | – | 100% (18/18, 2 builds) | 100% (18/18, 2 builds) | 100% (18/18, 2 builds) |
| partner-sync | 100% (8/8, 1 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) | 100% (24/24, 3 builds) |
| payments-errors | 100% (11/11, 1 builds) | 100% (33/33, 3 builds) | 100% (33/33, 3 builds) | 100% (33/33, 3 builds) |
| scheduled-reports | – | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| shipment-sync | – | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) | 100% (14/14, 2 builds) |
| stock-csv-import | – | 90% (18/20, 2 builds) | 100% (20/20, 2 builds) | 100% (20/20, 2 builds) |
| subscription-renewals | 100% (12/12, 1 builds) | 100% (36/36, 3 builds) | 100% (36/36, 3 builds) | 100% (36/36, 3 builds) |
| team-activity | 100% (6/6, 1 builds) | 100% (12/12, 2 builds) | 100% (12/12, 2 builds) | 100% (12/12, 2 builds) |
| team-invitations | 100% (14/14, 1 builds) | 100% (42/42, 3 builds) | 100% (42/42, 3 builds) | 100% (42/42, 3 builds) |
| token-login | – | 88% (14/16, 2 builds) | 100% (16/16, 2 builds) | 100% (16/16, 2 builds) |
| ALL | 100% (134/134, 10 builds) | 99% (506/510, 43 builds) | 100% (510/510, 43 builds) | 100% (510/510, 43 builds) |
| ALL without guest_without_accept | 100% (134/134, 10 builds) | 100% (504/504, 43 builds) | 100% (504/504, 43 builds) | 100% (504/504, 43 builds) |
| guest_without_accept | – | 33% (2/6, 6 builds) | 100% (6/6, 6 builds) | 100% (6/6, 6 builds) |
