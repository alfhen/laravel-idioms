| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |
|---|---|---|---|---|---|
| skill | 7.90 | 1.33 | 8 | 3.17 | 7.42 |
| both | 7.07 | 2.08 | 3 | 3.08 | 7.08 |
| boost | 6.19 | 3.00 | 1 | 3.92 | 6.17 |
| plain | 5.61 | 3.58 | 0 | 4.08 | 6.08 |

| Task | skill | both | boost | plain |
|---|---|---|---|---|
| blade-catalogue | 7.95 | 6.95 | 6.30 | 6.40 |
| filament-admin | 8.65 | 8.25 | 7.00 | 5.75 |
| caching | 7.00 | 6.75 | 5.50 | 5.50 |
| webhook-worker | 7.00 | 7.90 | 5.25 | 3.75 |
| sales-report | 8.10 | 6.30 | 6.85 | 5.25 |
| blog-crud | 8.70 | 6.25 | 6.25 | 7.00 |

| Arm | Builds | Avg cost | Avg time | laravel-idioms fired |
|---|---|---|---|---|
| boost | 6 | $2.10 | 464s | 0/6 |
| both | 6 | $2.13 | 514s | 1/6 |
| plain | 6 | $1.89 | 454s | 0/6 |
| skill | 6 | $2.42 | 573s | 6/6 |
