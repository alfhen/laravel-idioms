# Style (Spatie guidelines, Pint laravel preset)

Match the project's Pint/PHP-CS-Fixer config when there is one. These rules fill the gaps.

## Control flow

Unhappy path first, one guard per reason, happy path last and unindented. Avoid `else`.
```php
// bad
public function applyTo(Builder $builder, mixed $value): void
{
    if (! $this->containsOnlyIgnoredValues($value)) {
        if ($this->nullable || ! is_null($value)) {
            ($this->filterClass)($builder, $value, $this->internalName);
        }
    }
}

// good
public function applyTo(Builder $builder, mixed $value): void
{
    if ($this->containsOnlyIgnoredValues($value)) {
        return;
    }

    if (! $this->nullable && is_null($value)) {
        return;
    }

    ($this->filterClass)($builder, $value, $this->internalName);
}
```
- if/elseif/else → one `if` per case each ending in `return`, then the default `return` or `throw`.
- Split a compound condition into separate guards when each part is a distinct reason to bail. Don't split mechanically when the parts form one reason.
- `! $x` with a space after the bang. Always brace `if` bodies.

## Expressions

```php
return match ($this->type) {
    'bool' => $this->castToBool($value),
    'int' => (int) $value,
    'string' => (string) $value,
};

$rule = match (true) {
    $rule instanceof ExistsRule => new Exists(rule: $rule),
    $rule instanceof UniqueRule => new Unique(rule: $rule),
    default => null,
};

$this->request = $request
    ? QueryBuilderRequest::fromRequest($request)
    : app(QueryBuilderRequest::class);

$this->info("Deleted {$amountDeleted} record(s) from the activity log.");

json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
```
- `match` over `switch` and value-mapping if-chains.
- Multi-line ternaries with leading `?` / `:` unless very short.
- `"{$var}"` interpolation over concatenation and `sprintf`.
- Named arguments for booleans and optional parameters that would be bare literals.

## Types and docblocks

```php
protected ?string $arrayValueDelimiter = null;

public function delimiter(string $delimiter): static

/** @param array<int, AllowedFilter> $members */
public static function groupOr(string $name, array $members): static

/** @return class-string<Model> */
public static function teamModel(): string
```
Native types on every property, parameter and return (`void`, `static`, `?T`, unions). Docblocks only for generics, array shapes, `class-string`, or non-obvious behaviour. No `@param string $x` restating the signature.

## Classes

```php
class ConversionWillStartEvent
{
    public function __construct(
        public Media $media,
        public Conversion $conversion,
    ) {}
}

final class DataProperty
{
    public function __construct(
        public readonly string $name,
        public readonly DataPropertyType $type,
    ) {}
}
```
- Constructor promotion; `readonly` on value objects; `{}` for an empty body.
- Named static constructors (`for`, `exact`, `fromRequest`) returning `static` instead of flag arguments.
- Fluent setters mutate and `return $this` typed `static`/`self`, with sentence-like names (`usingName`, `withCustomProperties`, `toMediaCollection`).
- Small, single-purpose classes; split large models into capability traits (`Concerns\FiltersQuery`, `HasRoles`).
- One trait per `use` line inside the class body.
- Don't add `final` or `declare(strict_types=1)` by default; Spatie uses neither routinely.

## Exceptions

```php
class DiskDoesNotExist extends FileCannotBeAdded
{
    public static function create(string $diskName): self
    {
        return new static("There is no filesystem disk named `{$diskName}`");
    }
}

throw DiskDoesNotExist::create($diskName);
```
One descriptively named class per failure, built through a static factory; not `throw new \Exception("...")`.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Precondition helper (void, throws) | `ensureX()` / `guardAgainstX()` | `ensureDiskExists()` |
| Value-resolving helper | `determineX()` | `determineDiskName()` |
| Trait | capability | `HasRoles`, `InteractsWithMedia` |
| Interface | plain noun, no `Interface` suffix | `Contracts\Role` |
| Enum | PascalCase cases, lowercase values | `case Descending = 'desc';` |
| Event | tense shows before/after | `SavingSettings`, `SettingsSaved` |
| Job | the action it performs | `PerformConversions` |
| Command class | `...Command` | `CacheResetCommand` |
| Command signature | `namespace:verb-noun` | `permission:cache-reset` |
| Resource controller | plural + `Controller` | `PostsController` |
| Non-CRUD controller | new resource or invokable | `FavoritePostsController`, `UpdateSongController` |
| Config file / keys | kebab-case file, snake_case keys | `config/media-library.php`, `disk_name` |
| URL | kebab-case, no leading slash | `open-source` |
| Route name / parameter | camelCase | `->name('openSource')`, `{newsItem}` |

Use tuple route syntax `[PostsController::class, 'index']`, not `'PostsController@index'`. In an existing app, match its controller and event naming rather than adding suffixes by reflex.

## Validation

Multiple rules as an array, never a pipe string: `'title' => ['required', 'string']`.

## Commands

```php
public function handle(): int
{
    $this->comment('Cleaning activity log...');

    $amountDeleted = Config::cleanActivityLogAction()->execute($days, $this->argument('log'));

    $this->info("Deleted {$amountDeleted} record(s) from the activity log.");

    return self::SUCCESS;
}
```
Return an int exit code and tell the user what happened.

## Whitespace

Blank line before each `return`, between guard blocks, and between unrelated statements. No blank lines just inside braces.

## Tests

Pest: `it('can filter models by partial property', function () { ... })`, shared setup in `beforeEach`, helpers in `tests/Pest.php`, `expect($models)->toHaveCount(1)`. Not PHPUnit classes with `/** @test */ testFoo()` when the project uses Pest. More in `pest.md`.

## Packages

Service providers extend `Spatie\LaravelPackageTools\PackageServiceProvider` and declare assets in `configurePackage(Package $package)` (`->hasConfigFile()`, `->hasMigration()`, `->hasCommands([...])`).

Evidence: `spatie/guidelines.spatie.be/content/code-style/laravel-php.md`, `spatie/laravel-query-builder/src/AllowedFilter.php`, `spatie/laravel-medialibrary/src/MediaCollections/FileAdder.php`, `spatie/laravel-activitylog/src/Commands/CleanActivitylogCommand.php`.
