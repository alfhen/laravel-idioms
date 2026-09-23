# Eloquent

## Model configuration

Laravel 13 docs and skeleton configure models with class attributes from `Illuminate\Database\Eloquent\Attributes`. The properties still work; match what the project already uses.
```php
#[Table('my_flights', key: 'uuid', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable(['name', 'email'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([FlightObserver::class])]
#[ScopedBy([AncientScope::class])]
#[UsePolicy(FlightPolicy::class)]
#[UseFactory(FlightFactory::class)]
class Flight extends Model
{
    use HasFactory;
}
```
Existing attributes: `Fillable, Guarded, Unguarded, Hidden, Visible, Appends, Table, Connection, Touches, WithoutTimestamps, WithoutIncrementing, DateFormat, RouteKey, CollectedBy, UseEloquentBuilder, UsePolicy, UseResource, UseResourceCollection, UseFactory, ObservedBy, ScopedBy, Scope, Refreshes, Boot, Initialize`. There is no `#[Casts]`, `#[Accessor]`, `#[Relation]`, `#[SoftDeletes]` or `#[Prunable]` — don't invent them.

## Mass assignment

```php
User::create($request->validated());      // never $request->all()
```
Never `#[Unguarded]` a model that request data reaches. With guarded models, nested JSON keys (`options->enabled`) need an explicit fillable entry.

## Casts

```php
protected function casts(): array
{
    return [
        'published_at' => 'datetime',
        'password' => 'hashed',
        'status' => ServerStatus::class,
        'options' => AsArrayObject::class,
        'tags' => AsCollection::of(Tag::class),
        'statuses' => AsEnumCollection::of(ServerStatus::class),
    ];
}
```
- Use the method, not `protected $casts`; only the method can call `AsCollection::of()`.
- `protected $dates` is gone; use `datetime` / `immutable_datetime` casts.
- The plain `array` cast returns a copy: `$user->options['theme'] = 'dark'` fails. Use `AsArrayObject` / `AsCollection` for JSON you mutate in place.
- Compare enum cases: `$server->status === ServerStatus::Provisioned`.

## Accessors and mutators

```php
protected function firstName(): Attribute
{
    return Attribute::make(
        get: fn (string $value) => ucfirst($value),
        set: fn (string $value) => strtolower($value),
    );
}
```
Not `getFirstNameAttribute` / `setFirstNameAttribute`.

## Scopes

```php
#[Scope]
protected function ofType(Builder $query, string $type): void
{
    $query->where('type', $type);
}

#[Scope]
protected function draft(Builder $query): void
{
    $query->withAttributes(['hidden' => true]);   // Post::draft()->create([...]) gets hidden=true
}

User::popular()->ofType('admin')->get();
```
Protected (not private), no `scope` prefix, returns void. Inside the model, call through `static::query()->ofType(...)`. The `scopeX` form still resolves but is not the documented style.

## Strict mode

```php
// AppServiceProvider::boot()
Model::shouldBeStrict(! $this->app->isProduction());
```
Enables `preventLazyLoading`, `preventSilentlyDiscardingAttributes` and `preventAccessingMissingAttributes`. `Model::automaticallyEagerLoadRelationships()` is a separate opt-in that lazy-eager-loads instead of throwing.

## N+1 and aggregates

```php
$books = Book::with(['author:id,name', 'publisher'])->get();
$book->loadMissing('author');

$posts = Post::withCount('comments')
    ->withSum('comments as total_votes', 'votes')
    ->withExists('comments')
    ->get();   // $post->comments_count, ->total_votes, ->comments_exists
```
Never `$post->comments()->count()` or `$post->comments->sum(...)` inside a loop.

## Relationship queries and writes

```php
Post::whereBelongsTo($user)->get();                 // not where('user_id', $user->id)
Post::whereBelongsTo($user, 'author')->get();
Post::whereRelation('comments', 'is_approved', false)->get();
User::withWhereHas('posts', fn ($q) => $q->where('published', true))->get();   // filter + eager load
Post::whereAttachedTo($tag)->get();                 // belongsToMany

$post = $user->posts()->create(['title' => $title]);
$post->comments()->createMany([...]);
$user->account()->associate($account)->save();
```

## Relationship definitions

```php
public function orders(): HasMany
{
    return $this->hasMany(Order::class);
}

public function latestOrder(): HasOne
{
    return $this->orders()->one()->latestOfMany();
}
```
Always add the return type. Use `latestOfMany()` / `ofMany()` rather than `$user->orders->sortByDesc('id')->first()`. Use `->chaperone()` on a hasMany to hydrate the inverse without extra queries.

## Retrieval

| Want | Use |
|---|---|
| one column | `->value('email')` (not `->first()->email`, which crashes on null) |
| list / key map | `->pluck('title', 'name')` on the builder |
| existence | `->exists()` / `->doesntExist()` (not `count() > 0`) |
| exactly one | `->sole()` |
| 404 if missing | `findOrFail($id)` / `firstOrFail()` (not `find` + `abort(404)`) |

## Upserts

```php
$flight = Flight::firstOrCreate(['name' => $name], ['delayed' => 1]);
$flight = Flight::updateOrCreate(['departure' => 'Oakland', 'destination' => 'San Diego'], ['price' => 99]);
if ($flight->wasRecentlyCreated) { /* ... */ }

Flight::upsert($rows, uniqueBy: ['departure', 'destination'], update: ['price']);   // bulk; no model events
Stat::incrementOrCreate(['day' => $day], 'count');
Stat::createOrFirst(['day' => $day]);   // unique index + likely races
```
MySQL/MariaDB ignore `uniqueBy` and use the table's unique indexes; an empty `uniqueBy` throws on them in 13.

## Large datasets

```php
Flight::where(fn ($q) => $q->where('delayed', true)->orWhere('cancelled', true))
    ->chunkById(200, fn (Collection $flights) => $flights->each->update(['departed' => false]));

User::where('active', true)->lazyById()->each(fn (User $user) => $user->recalculateScore());
```
Group your own OR conditions in a closure: `chunkById` adds its own where clause.

## Events and observers

```php
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable {}

class UserObserver implements ShouldHandleEventsAfterCommit
{
    public function created(User $user): void { /* ... */ }
}

$user->saveQuietly();
$user->posts()->createQuietly([...]);
User::withoutEvents(fn () => User::findOrFail(1)->delete());
```
Observers over `User::observe()` in a provider; closures in `booted()` only for one-liners. `upsert()` and `MassPrunable` fire no model events.

## Lifecycle traits

```php
use Prunable;   // or MassPrunable when no delete events are needed

public function prunable(): Builder
{
    return static::where('created_at', '<=', now()->subMonth());
}
```
Run by `model:prune`; replaces hand-written cleanup commands. `SoftDeletes` + `withTrashed()` / `onlyTrashed()` / `restore()`, never `whereNull('deleted_at')`. `HasUuids` generates ordered UUIDv7.

## Factories

```php
public function suspended(): static
{
    return $this->state(fn (array $attributes) => ['account_status' => 'suspended']);
}

User::factory()->suspended()->create();
User::factory()->count(10)->state(new Sequence(['admin' => 'Y'], ['admin' => 'N']))->create();
User::factory()->hasPosts(3)->create();
Post::factory()->count(3)->for($user)->create();
Ticket::factory()->recycle(Airline::factory()->create())->create();
User::factory()->trashed()->create();
```
Not `Post::factory()->create(['user_id' => $user->id])` repeated across tests.

Evidence: `Illuminate/Database/Eloquent/Concerns/HasAttributes.php:1733`, `Concerns/QueriesRelationships.php`, `Builder.php`, `Attributes/`, `Model.php:576`, `laravel/docs/eloquent.md`, `eloquent-relationships.md`, `eloquent-factories.md`.
