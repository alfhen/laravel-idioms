# Installed Spatie packages

Checked 2026-11-21. A section applies only when `composer.lock` lists that package; never install one to follow this file. Package-shipped Boost skills or `.ai` guidelines hold the API specifics; this file wins only on correctness and security defects.

## Contents

- Versions
- spatie/laravel-permission
- spatie/laravel-query-builder
- spatie/laravel-data
- spatie/laravel-model-states
- spatie/laravel-medialibrary

## Versions

| Package | Checked | Needs | Ships a Boost skill |
|---|---|---|---|
| laravel-permission | 8.5.0 | PHP ^8.3, Laravel 12/13 | yes |
| laravel-query-builder | 7.3.9 | PHP ^8.4, Laravel 12/13 | yes (its `allowedFilters('*')` section is wrong for v7: wildcards were removed) |
| laravel-data | 4.23.1 | PHP ^8.1, Laravel 10-13 | no |
| laravel-model-states | 2.14.3 | PHP ^8.4, Laravel 12/13 | no |
| laravel-medialibrary | 11.23.13 | PHP ^8.2, Laravel 12/13 | yes, plus a guideline that always activates it |

Read the installed version from `composer.lock` and write that major's API. Agents mostly remember the previous major.

## spatie/laravel-permission

The package registers a `Gate::before` that returns `$user->checkPermissionTo($ability) ?: null`. It checks the ability name only and ignores the model argument. A permission named like a policy ability is granted for every model before the policy runs.
```php
// bad: 'update' is also ArticlePolicy::update; Gate::authorize('update', $anyArticle) now passes
Permission::create(['name' => 'update']);
enum Ability: string { case Delete = 'delete'; }   // same hole through an enum value

// good: permission names never equal an ability; the policy asks for them
Permission::findOrCreate('edit articles');
Permission::findOrCreate('edit any articles');

public function update(User $user, Article $article): bool
{
    return $user->can('edit any articles')
        || ($user->can('edit articles') && $article->author_id === $user->id);
}
```
- Check: `grep -rnE "['\"](view|viewAny|create|update|delete|restore|forceDelete)['\"]" database/seeders app/Enums`, then read each hit. Only permission names and enum values matter.
- App code checks permissions with `$user->can()`, `@can` or `can:` middleware. `hasPermissionTo()` throws `PermissionDoesNotExist` for an unknown name and skips `Gate::before`; `can()` returns false and honours a Super Admin rule. Check a role (`hasRole()`, `role:` middleware) only when the requirement is the role itself, never as a stand-in for a permission. A Super Admin `Gate::before` returns `true` or `null`, never `false`.
- One mechanism per route: policies, or `permission:` middleware, not both. The package registers no aliases: add them in `bootstrap/app.php` with `$middleware->alias([...])`, or use `PermissionMiddleware::using('edit articles')`. To answer 403 before a route-model 404, `$middleware->prependToPriorityList(SubstituteBindings::class, PermissionMiddleware::class)`.
- Seeders are idempotent: `Permission::findOrCreate()`, `Role::findOrCreate()`, then `syncPermissions()`, never `create()`. Start with `app(PermissionRegistrar::class)->forgetCachedPermissions()`. Package methods flush the cache themselves; a raw `insert()` does not, so flush after it.
- An `int` is looked up as an id and a string as a name, so `syncPermissions($request->input('ids'))` with `"5"` finds the permission named "5". Cast validated ids to `int`.
- With `teams` on, after `setPermissionsTeamId($id)` call `$user->unsetRelation('roles')->unsetRelation('permissions')`: the loaded relations still hold the previous scope.

Tests: one user with the permission and a model they don't own gets 403; a user with no role gets 403; running the seeder twice leaves the same rows; a permission granted mid-test applies after `forgetCachedPermissions()` and a fresh user model.

Renames (8.x names, checked in `src/`): events end in `Event` (`RoleAttachedEvent`, `PermissionDetachedEvent`); command classes end in `Command`; middleware lives in `Spatie\Permission\Middleware` (singular since v6, not `Middlewares`); `findByName()` and `findOrCreate()` take a `BackedEnum`. The dist has no upgrade guide; see `docs/upgrading.md` on GitHub.

## spatie/laravel-query-builder

A bare string in `allowedFilters()` becomes `AllowedFilter::partial()`: `LOWER(col) LIKE '%value%'`. Right for free text, wrong for anything with an exact meaning.
```php
// bad: filter[assignee_id]=1 also returns 10 and 21; filter[status]=open matches "reopened"
QueryBuilder::for(Ticket::class)
    ->allowedFilters('title', 'status', 'assignee_id', 'is_urgent');

// good
QueryBuilder::for($request->user()->tickets())       // keeps ownership unless a global scope does that
    ->allowedFilters(
        'title',                                      // free text: partial
        AllowedFilter::exact('status'),
        AllowedFilter::exact('assignee_id'),
        AllowedFilter::exact('is_urgent'),            // 'true'/'false' arrive as booleans
        AllowedFilter::exact('external_ref')->delimiter(''),  // value may contain commas
    )
    ->allowedSorts('created_at', 'title')
    ->defaultSort('-created_at')
    ->orderBy('id')                                   // tiebreaker after any requested sort
    ->paginate($request->validated('per_page', 15));   // FormRequest: per_page integer, 1-100
```
- Ids, foreign keys, enums, booleans and dates use `exact()`, `belongsTo()`, `scope()` or `operator('min_total', FilterOperator::GREATER_THAN_OR_EQUAL, internalName: 'total')`. Partial already escapes `%`, `_` and `\`; don't escape again.
- Values split on `,` by default (OR for partial, `whereIn` for exact). Turn it off per filter with `->delimiter('')`.
- `allowedSorts()` is the sort allow-list. Don't repeat it in a `Rule::in`, which also rejects `-created_at` and `a,b`.
- Unknown filter, sort, include or field names throw `InvalidFilterQuery` and friends: `HttpException` 400, not a 422. Filter values are not validated: `filter[assignee_id]=abc` on an integer column is a Postgres 500. Validate them in the FormRequest (`'filter.assignee_id' => ['sometimes', 'integer']`). `per_page` is yours to cap.
- `allowedIncludes()` exposes relations. List each deliberately; an included relation is not run through its policy, and `include=author.tokens` returns whatever `$hidden` lets through.

Tests: `?filter[assignee_id]=1` never returns a row owned by 10; an unknown filter or sort is `assertBadRequest()`; a bad value is 422, never 500; another user's rows never appear.

Renames v7 (`UPGRADING.md`): PHP 8.4, Laravel 12/13. `allowed*()`, `defaultSort(s)()` are variadic, so `allowedFilters(['a'])` is a `TypeError`; spread a dynamic array. `'*'` wildcards removed. `SortDirection::DESCENDING` became the enum case `SortDirection::Descending`. `beginsWithStrict()`/`endsWithStrict()` became `beginsWith()`/`endsWith()`. Static delimiter setters removed: `delimiter` config key or `->delimiter()`. Custom `Filter`, `Sort` and `IncludeInterface` `__invoke` return `void`. Config `disable_invalid_includes_query_exception` became `disable_invalid_include_query_exception`; `count_suffix`/`exists_suffix` became `suffixes`. v6: typed custom filters and sorts.

## spatie/laravel-data

Validation runs only when a Data object is injected or built with `from($request)`. The default `validation_strategy` is `OnlyRequests`, so `from()` with an array never validates.
```php
// bad: unvalidated input; a missing nullable key becomes null and wipes the column
$venue->update(VenueData::from($request->all())->all());

// good
final class VenueData extends Data
{
    public function __construct(
        public string|Optional $name,
        public string|null|Optional $phone,
    ) {}
}

public function update(VenueData $data, Venue $venue): Venue   // injected: validated
{
    $venue->update($data->all());                               // absent keys stay out

    return $venue;
}
```
- Build from input by injection, `Data::from($request)` or `Data::validateAndCreate($array)`. After a FormRequest, `Data::from($request->validated())` is fine. Never `from($request->all())` or `from($request->input())`.
- PATCH properties are `T|Optional`. A missing key on a plain `?T` property is set to `null`. `all()` and `toArray()` drop `Optional` values.
- Only declared properties come through, so privileged columns stay out by not declaring them.
- As a resource, relations are `Lazy::whenLoaded('tracks', $album, fn () => TrackData::collect($album->tracks))`. `TrackData::collect($album->tracks)` in `fromModel()` lazy-loads per row.
- Check: `grep -rnE '::from\(\$request->(all|input)\(' app` finds nothing.

Tests: a PATCH with one field keeps the others; `validateAndCreate()` on a bad array throws `ValidationException`; a list endpoint's query count stays flat.

Renames v4 (`UPGRADING.md`): `Data::collection()` removed, use `collect()` (returns the input's type; `collect($items, DataCollection::class)` for the old type). Properties removed by `exclude` rules are now `Optional`, since the object is built from the validated payload. Custom transformers take a `TransformationContext`. Prefer annotations or plain arrays and Collections over `#[DataCollectionOf]` and `DataCollection`.

## spatie/laravel-model-states

`transitionTo()` checks the transition against the state loaded in memory, then `DefaultTransition` assigns and calls a plain `save()`. Two requests holding the same stale state both pass. Applies where a transition has side effects or money; a state one user flips needs none of it.
```php
// bad: a double click and a retry both confirm and both notify
$booking->state->transitionTo(Confirmed::class);

// good: re-read under a lock; the loser fails the transition check
DB::transaction(function () use ($id) {
    $booking = Booking::lockForUpdate()->findOrFail($id);
    $booking->state->transitionTo(Confirmed::class);   // Confirmed -> Confirmed is not allowed: throws
});
// with #[IgnoreSameState] the loser passes and runs the transition again: check $booking->state->equals(Confirmed::class) first
```
- `StateChanged` and a custom transition's `handle()` run inside that transaction. Mail and HTTP go to listeners or jobs that run after commit (`ShouldQueueAfterCommit`, `->afterCommit()`).
- Change state only through `transitionTo()`. Assigning `$booking->state = Confirmed::class` skips the rules.
- `CouldNotPerformTransition` extends `Exception`: a 500 unless you map it (409 or 422) in `withExceptions()`.
- Validate a requested state with `ValidStateRule::make(BookingState::class)` first. An unknown string reaching `transitionTo()` is instantiated as a class name.
- The column stores the class name unless a state declares `public static $name = 'confirmed';`. Set it before the first row, or renaming a class breaks stored rows.

Tests: two instances loaded before either transition, both transition: one side effect and one changed row; a disallowed transition and an unknown state answer 4xx; nothing is sent when the transaction rolls back.

Renames: v2 (PHP 8.4, Laravel 12/13 in 2.14) configures states in `public static function config(): StateConfig` (`parent::config()->default()->allowTransition()`), or with the `#[DefaultState]`, `#[AllowTransition]`, `#[RegisterState]` and `#[IgnoreSameState]` class attributes. The dist has no upgrade guide; see the GitHub repo.

## spatie/laravel-medialibrary

Defaults: `disk_name` is `env('MEDIA_DISK', 'public')`, the path is `{media id}/`, and `file_name` and `name` come from the client's file name. Without changes every upload is public at a guessable URL. Applies to any collection that holds private files.
```php
// bad: public disk, sequential path, client file name stored
$lease->addMediaFromRequest('file')->toMediaCollection('attachments');

// good
public function registerMediaCollections(): void
{
    $this->addMediaCollection('attachments')->useDisk('local')->singleFile();
}

$file = $request->validated('file');                // type and size checked in the FormRequest
$lease->addMedia($file)
    ->usingName('attachment')
    ->usingFileName($file->hashName())
    ->toMediaCollection('attachments');

// download route, after the policy check: Media is Responsable
return $lease->getFirstMedia('attachments') ?? abort(404);
```
- Validate type and size in the FormRequest first. `acceptsMimeTypes()` and `max_file_size` throw `FileUnacceptableForCollection` / `FileIsTooBig` (plain exceptions, 500s).
- Serve private media from an authorized route returning the `Media` model, or `getTemporaryUrl()` on S3. Never `getUrl()` for a private disk.
- `singleFile()` keeps the latest item and deletes the older ones with their files.
- Eager load `media` for lists: `getFirstMediaUrl()` per row is an N+1 otherwise.
- Conversions queue by default, after the database commit.

Tests: `Storage::fake('local')` and `Storage::fake('public')`, then assert the public disk is empty; a non-owner gets 403 or 404; no stored `name` or `file_name` equals the client name; `Queue::fake()` or `->nonQueued()` on the conversion to assert conversions.

Renames: v11 (Laravel 12/13 in 11.23) uses the `HasMedia` interface with the `InteractsWithMedia` trait (not `HasMediaTrait`), and `registerMediaConversions(?Media $media = null): void`. The dist has no upgrade guide; see `UPGRADING.md` on GitHub.

Evidence (installed sources under `vendor/spatie/`): laravel-permission `src/PermissionRegistrar.php` (`registerPermissions`), `src/Traits/{HasPermissions,RefreshesPermissionCache}.php`, `src/Models/{Permission,Role}.php`, `src/Middleware/PermissionMiddleware.php`, `src/Events/`, `src/helpers.php`, `config/permission.php`, `resources/boost/skills/laravel-permission-development/SKILL.md`; laravel-query-builder `UPGRADING.md`, `src/Concerns/{FiltersQuery,SortsQuery}.php`, `src/Filters/{FiltersPartial,FiltersExact}.php`, `src/{AllowedFilter,QueryBuilder,QueryBuilderRequest}.php`, `src/Exceptions/{InvalidQuery,InvalidFilterQuery,InvalidSortQuery}.php`, `src/Enums/FilterOperator.php`, `config/query-builder.php`, `resources/boost/skills/laravel-query-builder/SKILL.md`; laravel-data `UPGRADING.md`, `docs/validation/introduction.md`, `docs/as-a-data-transfer-object/optional-properties.md`, `docs/as-a-resource/lazy-properties.md`, `src/DataPipes/{ValidatePropertiesDataPipe,DefaultValuesDataPipe}.php`, `src/Resolvers/VisibleDataFieldsResolver.php`, `src/Lazy.php`, `config/data.php`; laravel-model-states `src/{State,StateConfig,StateCaster,DefaultTransition}.php`, `src/Validation/ValidStateRule.php`, `src/Attributes/`, `src/Exceptions/CouldNotPerformTransition.php`; laravel-medialibrary `config/media-library.php`, `src/MediaCollections/{FileAdder,MediaCollection}.php`, `src/MediaCollections/Models/Media.php`, `src/MediaCollections/Exceptions/`, `src/{HasMedia,InteractsWithMedia}.php`, `src/Support/PathGenerator/DefaultPathGenerator.php`, `src/Conversions/Conversion.php`, `resources/boost/guidelines/core.blade.php`; laravel/framework `Foundation/Configuration/Middleware.php` (`prependToPriorityList`), `Http/FileHelpers.php` (`hashName`); spatie/laravel-permission `docs/upgrading.md` on GitHub.
