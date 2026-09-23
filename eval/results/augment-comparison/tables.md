| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| augment | 7.27 | 2.00 | 6 | 3.25 | 6.83 |
| skill | 7.10 | 2.17 | 4 | 3.83 | 6.50 |
| both | 6.54 | 2.58 | 2 | 3.25 | 6.71 |
| boost | 5.82 | 3.25 | 0 | 4.50 | 5.67 |

| Task | augment | skill | both | boost |
|---|---|---|---|---|
| blade-catalogue | 7.60 | 6.90 | 6.25 | 6.20 |
| filament-admin | 6.95 | 7.45 | 7.60 | 6.15 |
| caching | 6.55 | 5.90 | 6.65 | 6.00 |
| webhook-worker | 7.80 | 6.30 | 6.60 | 4.30 |
| sales-report | 6.25 | 7.75 | 5.50 | 6.75 |
| blog-crud | 8.45 | 8.30 | 6.65 | 5.50 |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired |
|---|---|---|---|---|
| augment | 6 | $3.27 | 741s | 6/6 |
| boost | 6 | $2.10 | 464s | 0/6 |
| both | 6 | $2.13 | 514s | 1/6 |
| skill | 6 | $2.42 | 573s | 6/6 |
