# Dates, time zones and money

Checked against Laravel 13.42, Carbon 3.15, PHP 8.4. Carbon 3 ships with Laravel 11+; on Carbon 2 `diffInDays` returns an int.

## Money: storage and type

- Integer minor units: `unsignedBigInteger('amount')` + `char('currency', 3)`. Or `decimal(12, 2)` with a `decimal:2` cast, which returns a string (`'12.30'`).
- Never a float column or cast. Never `(int) ($price * 100)`: `19.99` gives `1998`.
```php
// bad
$minor = (int) ($price * 100);
// good: parse the string (see Input), no float involved
$minor = Money::parseMinor('19.99', 'EUR');
```
- If a float is unavoidable, `(int) round($x * 100)`. It is a last resort.
- A single price field does not need `brick/money` or `moneyphp`: an integer column and one formatter is enough. A value object (a `CastsAttributes` cast over amount + currency) earns its place with cross-currency arithmetic, allocation or many money fields.
- Don't rewrite a working `decimal:2` column to integers. The bug is `(float)` arithmetic, not the column.

## Money: arithmetic

- Multiply first, divide once, round once, at a named point: per line then sum the lines, or on the total. Never both. Which one is a business or legal decision: write it down and test it.
```php
$tax = intdiv($net * $rate + 50, 100);       // half up, non-negative integer $net, integer percent $rate
$tax = (int) round($net * $rate / 100);      // same, PHP rounding
```
- The rounding mode is a decision. PHP `round()` is half away from zero (`round(-2.5)` is `-3`). The `decimal:n` cast rounds HalfUp. Banker's rounding needs `round($x, 0, PHP_ROUND_HALF_EVEN)` or brick's `RoundingMode::HalfEven`.
- Split a total with the largest remainder, never `round($total / $n)` per part. The parts must sum to the total. The snippet is for a non-negative total; split a refund's absolute value and negate the parts:
```php
$base = intdiv($total, $n);
$parts = array_map(fn (int $i) => $base + ($i < $total % $n ? 1 : 0), range(0, $n - 1));   // 10000 / 3 => [3334, 3333, 3333]
```
- Per-line rounding, then a sum of the rounded lines, so the order total always equals what the lines show:
```php
$lines = collect($input['lines'])->map(function (array $l) use ($rate): array {
    $net = $l['unit_minor'] * $l['quantity'];
    $tax = intdiv($net * $rate + 50, 100);      // the one rounding point

    return ['net' => $net, 'tax' => $tax, 'gross' => $net + $tax];
});
$total = $lines->sum('gross');                  // never recomputed from the order net
```
- Weighted allocation (a discount over order lines): give each line `intdiv($total * $w, $sumW)`, then hand the leftover to the lines with the largest fractional remainder. brick and moneyphp `allocate()` do this.

## Money: currencies, display, API

- The minor-unit exponent is per currency: most 2, JPY 0, KWD/BHD 3. `/ 100` and `* 100` are wrong for those. Take it from intl (`NumberFormatter`), `brick/money` (`getDefaultFractionDigits()`) or moneyphp (`ISOCurrencies::subunitFor()`); don't hard-code 100. A single-currency shop may state the assumption in code and validate it.
- `Number::currency(int|float $number, string $in = '', ?string $locale = null, ?int $precision = null)` takes major units, is display only, and needs `ext-intl` (it throws without it). `$minor / 10 ** $exponent` is the major amount, so `$cents / 100` is right only for exponent-2 currencies. `Number::currency(1230, in: 'JPY')` prints `¥1,230`.
- Don't format in the DB layer, in a cast `get`, or in the API response.
- JSON money: integer minor units + currency code (`{"amount": 1230, "currency": "EUR"}`), or a decimal string + code with the scale documented. Never a JSON float. A `decimal:2` cast serialises as a string, which is fine.
- Input: validate `['required', 'decimal:0,2', 'min:0']`, then convert with string math:
```php
[$whole, $frac] = array_pad(explode('.', $input, 2), 2, '');
$minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');   // "12.3" => 1230   (exponent 2 only)
```
  or `BigDecimal::of($input)->withPointMovedRight(2)->toInt()` (brick/math is a Laravel dependency). `numeric` accepts `1e2`, and neither rule accepts a locale comma: reject `"12,30"` or normalise it before validating. `decimal:0,2` rejects `12.345`.
- Tests: assert `'100.00'` or integer minor units, not `(float)`.

## Time zones

- Keep `config/app.php` `'timezone' => 'UTC'`. Don't flip it on a running app: existing `datetime` columns hold the old wall clock, so it needs a data migration.
- Input: parse in the user's zone, then `->utc()`. Output: `setTimezone()` for display only.
```php
$starts = CarbonImmutable::parse($request->input('starts_at'), $user->timezone)->utc();
$label = $booking->starts_at->setTimezone($user->timezone)->isoFormat('LLL');
```
  An ISO string with an offset or `Z` keeps its own zone: the second argument does not override it.
- Never convert zones in a model accessor or mutator based on `Auth::user()`: it leaks the request's user into jobs, commands and `toArray()`. Convert in the resource or view.
- Validate with `'timezone' => ['required', 'timezone:all']` and store the IANA name (`Europe/Copenhagen`), never an offset. `CET` and `+01:00` fail `timezone:all`.
- Per-user zones only when users really span zones. A single-market back office wants one named business zone (`now('Europe/Copenhagen')->startOfDay()`), not a `users.timezone` column.
- JSON: model dates serialise through `toJSON()` as ISO-8601 `Z` with microseconds. Don't override `serializeDate` to local time; format in the client. A `date` cast serialises as a datetime unless `date:Y-m-d`.
- MySQL `timestamp` (what `timestamps()` and `timestamp()` create) converts through the session time zone and ends in 2038; `dateTime` stores what it is given (per the MySQL docs). Use `dateTime` for far-future dates (`valid_until`, expiries). Don't set a connection `timezone` other than `+00:00` while the app is UTC.
- Date-only values (birthdays, due dates, "valid on") use a `date` cast or a string. A midnight timestamp is a different day in another zone. Compare with `toDateString()`.

## Mutability and arithmetic

- `now()` and `Date::parse()` return mutable `Carbon` by default: `$end = $start->addDays(30)` moves both. Never mutate a date you didn't create: `->copy()`, `toImmutable()`, or a `CarbonImmutable` local. This bites locals, parameters, and properties of DTOs and jobs. A model's own cast attribute is rebuilt on each read, so mutating `$order->created_at` does not change or dirty the model.
- `Date::use(CarbonImmutable::class)` changes the whole app: statement-form `$d->addDay();` silently stops working and packages that pass dates around can break. It is a project decision, not part of a feature. Grep for statement-position `->add*/sub*/startOf*/setTimezone` first.
- `Carbon::parse()` called directly bypasses `Date::use`. `immutable_datetime` forces an immutable per attribute.
- `addMonth()` overflows: Jan 31 + 1 month is Mar 3. Use `addMonthNoOverflow()` (Feb 28). Anchor a series on the original date, never on the previous result:
```php
// bad: Jan 31 -> Feb 28 -> Mar 28
$next = $last->addMonthNoOverflow();
// good: Jan 31 -> Feb 28 -> Mar 31
$next = $start->addMonthsNoOverflow($i);
```
  Same for `addYear()` on 29 Feb, `subMonth()`, and `now()->addMonths($i)->startOfMonth()` (overflows before `startOfMonth`; write `startOfMonth()->addMonthsNoOverflow($i)`).
```php
$start = CarbonImmutable::parse('2026-03-28 00:00', 'Europe/Copenhagen');
$start->addDays(2);       // 2026-03-30 00:00 +02:00, the next calendar day
$start->addHours(48);     // 2026-03-30 01:00 +02:00 after the DST change
```
- Calendar days vs elapsed time across DST: `addDay()` keeps the wall clock, `addHours(24)` keeps elapsed time (Copenhagen, 29 Mar 2026: `00:00 + addDay()` is `00:00` on the 30th, `+ addHours(24)` is `01:00`). Calendar steps use `addDay(s)`; TTLs and timeouts use `addHours`/`addSeconds`. A day can be 23 or 25 hours.
- Carbon 3 `diffInDays` is a signed float (`1.5` for 36 h) and negative when the other date is earlier unless `absolute: true`. `$a->diffInDays($b)` is `$b - $a`. For whole days, `startOfDay()` both sides and cast, or use `isSameDay`.
- Compare with `eq`, `lt`, `lte`, `gt`, `gte`, `isSameDay`. Never `===` (distinct objects); `==` fails on differing microseconds.

## Day boundaries

A user's "today" is the half-open range `[local midnight, next local midnight)` converted to UTC. Not `whereDate()`, which compiles to `date(col) = ?`, cannot use an index and compares the UTC day.
```php
$day = CarbonImmutable::now($user->timezone)->startOfDay();
Order::where('placed_at', '>=', $day->utc())->where('placed_at', '<', $day->addDay()->utc());   // addDay() on the local start, not +24 h
```
- `today()` and `now()->startOfDay()` are app-timezone (UTC) midnight. `today('Pacific/Auckland')` and `now($tz)->startOfDay()` name the zone.
- `endOfDay()` closes at 23:59:59 and can miss fractions on `timestamp(6)`; prefer `<` next midnight.
- `whereDate` on a small unindexed admin table is not worth rewriting. The UTC-versus-user-day error is the real problem.

## Tests

- `travelTo(CarbonImmutable::parse('2026-11-14 12:30:00', 'UTC'))` or `freezeTime()`: always build the instant in an explicit zone. `travelTo` sets the test clock for both Carbon classes. After a bare `Carbon::setTestNow()`, clear it in teardown.
- Day-boundary tests need two zones and the instants either side of local midnight (for example Pacific/Auckland 23:30 on the 14th and 01:30 on the 15th), plus a DST day.
```php
it('puts a 01:30 Auckland order on the 15th, not the 14th', function () {
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);
    travelTo(CarbonImmutable::parse('2026-11-14 12:30:00', 'UTC'));        // 01:30 on the 15th locally
    $order = Order::factory()->for($user)->create(['placed_at' => now()]);

    travelTo(CarbonImmutable::parse('2026-11-14 12:45:00', 'UTC'));
    expect(Order::placedToday($user)->pluck('id'))->toContain($order->id);
});
```
- Month tests: start on the 31st, advance three times, and on 29 Feb in a leap year.
- Money tests: `19.99` is `1999`, three lines of `0.10` with 25% tax, a `10000 / 3` split summing back, a 0-digit currency, and `"1e2"`, `"12,30"`, `"12.345"` rejected.

Evidence: `Illuminate/Support/{Number,DateFactory,Carbon}.php`, `Database/Eloquent/Concerns/HasAttributes.php`, `Database/Query/Grammars/Grammar.php`, `Database/Schema/Grammars/MySqlGrammar.php`, `Validation/Concerns/ValidatesAttributes.php`, `Foundation/Testing/Concerns/InteractsWithTime.php`, `Foundation/helpers.php`; `nesbot/carbon/src/Carbon/Traits/{Difference,Converter}.php`; `laravel/docs/{helpers,eloquent-mutators,validation,mocking}.md`.
