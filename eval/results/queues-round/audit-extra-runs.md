| Trap | head-1 | head-2 | main-1 | main-2 |
|---|---|---|---|---|
| a: list<string> via array_keys(array_filter()) | pass (left alone) | pass (left alone) | pass (left alone) | pass (left alone) |
| b: strict in_array dedupe | FAIL (rewrite breaks PHPStan and tests); F-011: PHPStan 2 err, tests FAIL | pass (flagged safely, verified); F-011: PHPStan 0 err, tests pass | UNVERIFIED (rewrite not applicable); F-009: not applicable | pass (flagged safely, verified); F-008: PHPStan 0 err, tests pass |
| c: array_unique() on strings | pass (left alone) | pass (flagged safely) | pass (flagged safely) | pass (left alone) |
| d: scopeAccepted() vs attribute reads | FAIL (rewrite breaks PHPStan and tests); F-008: PHPStan 6 err, tests FAIL | FAIL (rewrite breaks PHPStan and tests); F-006: PHPStan 1 err, tests FAIL | UNVERIFIED (rewrite not applicable); F-007: not applicable; F-008: not applicable | FAIL (rewrite breaks PHPStan and tests); F-006: PHPStan 3 err, tests FAIL |
| e: TrimStrings stub with default $except | pass (flagged safely) | pass (flagged safely) | pass (flagged safely) | pass (flagged safely) |
| f1: keyed accumulate-in-foreach | pass | pass | pass | pass |
| f2: private list property built in foreach | pass | pass | pass | pass |
| f3: $request->all() into create() | pass | pass | pass | pass |

