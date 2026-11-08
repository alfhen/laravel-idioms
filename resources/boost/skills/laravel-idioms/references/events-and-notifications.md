# Events, observers, notifications and mail

Queue mechanics for listeners, notifications and mailables (attributes, `failed()`, fan-out, routing) are in `queues-and-workers.md`. This file covers when to use each piece, the after-commit switches, what skips model events, and how to test it.

## Event or direct call

```php
// one effect that must succeed with the request: a direct, synchronous call
app(UpdateWishlist::class)($userId);
// one fact, several independent effects: an event
OrderWasShipped::dispatch($order);   // listeners: SendShipmentNotification, SyncToErp, UpdateStats
```
- Use an event when one fact has two or more independent effects, or crosses a module or package boundary. One effect, one caller → a direct call or one queued job. Keep an event the brief asks for.
- Events carry no return value, so never use them for control flow. A listener `handle(): void`; one that returns `false` stops every later listener silently.
- A queued listener whose only work is dispatching one job is an extra hop. Do the work in the listener or dispatch the job directly.
- Does not apply to framework events you only observe (`Registered`, `Login`, `MessageSent`).

## Discovery and registration

- Under `Application::configure()` the framework discovers `app/Listeners`: every public method named `handle*` or `__invoke` whose **first parameter has a class type**. A union type registers each class. `handle(object $event)` (the plain `make:listener` stub), an untyped or a `mixed` first parameter is skipped silently, and the listener never fires. Use `make:listener --event=` or type-hint the event.
- Register each listener once. Discovery plus `Event::listen()` or a `$listen` entry runs it twice. The same goes for `#[ObservedBy]` plus `Model::observe()`.
- Exception: an upgraded app that still builds `new Illuminate\Foundation\Application` and keeps an `EventServiceProvider` subclass does not discover. There `$listen` is required and is not a duplicate. Run `php artisan event:list` before removing anything.
- Matching is by event class or implemented interface, not parent class. Other directories need `->withEvents(discover: [...])`. A class opts out with `ShouldBeDiscovered::shouldBeDiscovered()` returning false.
- `optimize` includes `event:cache`, which freezes the map. A new listener that "does nothing" locally needs `event:clear`. Deploy scripts run `optimize`.

## After-commit: pick the switch by what you delay

Applies when the code runs inside `DB::transaction()`. Without an open transaction the callback runs at once, and a queue connection with `after_commit => true` already covers queued work.

| What runs inside the transaction | Switch |
|---|---|
| the event, so every listener waits | `ShouldDispatchAfterCommit` on the event |
| a queued listener, notification or mailable | `ShouldQueueAfterCommit` (it extends `ShouldQueue`, don't add both), or `afterCommit()` / `public bool $afterCommit = true` on the object |
| a sync listener or observer | `ShouldHandleEventsAfterCommit` |
| a sync mailable or notification | none: send after `DB::transaction()` returns |

```php
// bad: the mail goes out even when the transaction rolls back
DB::transaction(function () use ($order) {
    $order->update(['status' => 'shipped']);
    $order->customer->notify(new OrderShipped($order));
});
// good
final class OrderShipped extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
}
```
- `ShouldHandleEventsAfterCommit` on a queued listener does nothing. `PendingMail` (`Mail::to()`) has no `afterCommit()`: the method lives on the mailable via `Queueable`.
- Synchronous before-events (`creating`, `updating`, `saving`, `deleting`, `restoring`, `forceDeleting`) ignore every after-commit switch and always run inside the transaction. A queued observer (`ShouldQueue`/`ShouldQueueAfterCommit`) is picked before that check, so its `creating()` runs later on a worker and can neither set fields nor veto the write: keep before-event invariants synchronous. Do side effects (jobs, HTTP, cache) in `created`/`updated`/`deleted`. A `deleting` handler that dispatches also fires when the delete is later aborted or rolled back. Snapshot what you need and act in `deleted()`.
- With hand-rolled `DB::beginTransaction()`, dispatch after `DB::commit()` and roll back on every early return. Prefer the closure form.
- An observer that waits for commit reads changes with `wasChanged('col')` / `getChanges()`. It runs after `syncOriginal()`, so `isDirty()` is false and `getOriginal('col')` already returns the new value. In a `RefreshDatabase` test only the wrapper transaction is open, so the callback runs at once and `isDirty()` still passes. `wasChanged()` is right in both cases. In a plain sync `updated()`, `isDirty()` works.

## Model events: what skips them

```php
// bad: the observer that stamps the audit row and busts the cache never runs
Article::where('category_id', $id)->update(['archived_at' => now()]);
// good: one query per row, events fire
Article::where('category_id', $id)->lazyById()->each->update(['archived_at' => now()]);
```
- Instance `save()`, `create()`, `delete()` fire events. These do not: `where()->update()/delete()`, `insert()`, `insertOrIgnore()`, `upsert()`, `DB::table()`, builder `increment()`, `MassPrunable`, and `attach()/detach()/sync()` on a pivot without `->using()`.
- A bulk path that must trigger a rule: loop with `lazyById()`, enforce it in the database (`cascadeOnDelete()`, a generated column), or call the side effect explicitly in the bulk code. When skipping events is the point (an import, a backfill), say so in the command.
- A pivot fires events only for a custom class: `belongsToMany(...)->using(Follow::class)` (`Follow extends Pivot`) makes `attach()`, `detach()` and `updateExistingPivot()` save or delete each row. Observe the pivot class and expect one event per row.
- `saveQuietly()`, `updateQuietly()`, `createQuietly()`, `deleteQuietly()` and `Model::withoutEvents()` mute every listener for the call, and `withoutEvents` mutes every model inside the closure. Use them to stop an observer re-entering itself, or in a deliberate backfill, with a one-line comment saying why. Instance `increment()` fires `updating`/`updated`, not `saving`/`saved`.
- `updated` fires only when something was dirty. `saved` always fires.

## Observers

```php
public function updated(User $user): void
{
    if (! $user->wasChanged('shop_domain') || $user->shop_domain === null) {
        return;
    }

    ActivateAwaitingPoints::dispatch($user);
}
```
- Observers hold single-model invariants: derive a field, stamp a column, forget a cache key, clean up children. Cross-aggregate workflows, outbound HTTP, notifications and deleting other models belong in an event or a queued job.
- Guard update handlers with `wasChanged('col')` so an unrelated update runs no query. Observers also fire for seeders, factories, imports and tests, so anything slow goes behind a queue.
- Don't queue the observer itself: dispatch an event or job from it.

## Queued listeners

```php
final class FetchOrderHistory implements ShouldQueueAfterCommit
{
    public function shouldQueue(UserSeen $event): bool
    {
        return $event->user->isNotSyncedRecently();
    }

    public function handle(UserSeen $event): void { /* ... */ }
}
```
- Put a cheap guard for a hot event in `shouldQueue()`. The dispatcher calls it inline, before anything is pushed. A guard in `handle()` runs on the worker after a job was queued for every dispatch.
- Bursts of one event: `ShouldBeUnique` + `uniqueId(Event $e)` + `#[UniqueFor]`, or `#[DebounceFor(30)]` + `debounceId()`. They are exclusive: both together throw `LogicException`. Rare events need neither.
- Anything queued that carries a model serializes it by id. Events: `use Dispatchable, SerializesModels` (`Dispatchable` does not add it). Mailables: `use Queueable, SerializesModels` (`Mailable` has neither trait). `Notification` has `SerializesModels` but not `Queueable`.
- A raw Eloquent event string (`eloquent.created: X`) reaches a queued listener serialized by value. Use a custom event class. A model deleted before the worker runs throws `ModelNotFoundException`: add `#[DeleteWhenMissingModels]` when that is fine.

## Notifications

```php
public function via(object $notifiable): array
{
    return $notifiable->email ? ['mail', 'database'] : ['database'];   // queued: runs at dispatch, keep it cheap
}

public function shouldSend(object $notifiable, string $channel): bool
{
    return ! $this->invoice->isVoid();   // runs on the worker, per channel
}
```
- Notifications and mailables are `ShouldQueue`, unless the call already runs in a worker. A sync `notify()` or `Notification::send($many)` sends inline, one notifiable at a time, and the first exception aborts the loop. A single critical mail that should fail the request (a sync password reset) is the exception. Large audiences fan out from a job (see `queues-and-workers.md`).
- The mail channel silently skips a notifiable with no routable email. Drop only `mail` from `via()` for that case (as above), so the other channels still send.
- The `database` channel needs the table: `php artisan make:notifications-table && php artisan migrate`. The skeleton ships no migration. `toDatabase()` wins over `toArray()`.
- `Notification::route('mail', $email)` makes an `AnonymousNotifiable`: no locale preference, and `route('database', ...)` throws `InvalidArgumentException`.
- `NotificationSent` and `NotificationFailed` fire per channel and attempt.

## Mail

```php
// bad: one message, every address in To, and recipients see each other
Mail::to($users)->send(new WeeklyDigest);
// bad: to() appends, so each pass re-mails the earlier recipients
$mail = new Invoice($order);
foreach ($users as $user) { Mail::to($user)->send($mail); }
// good
foreach ($users as $user) {
    Mail::to($user)->queue(new WeeklyDigest($user));
}
```
- One new mailable per recipient. Use Bcc for a list only when that is intended.
- A `ShouldQueue` mailable is queued by `send()` too, and returns once the job is pushed. `Mail::queue()` / `later()` on a non-queued mailable still pushes it.
- A "sent" marker next to a queued send means "queued", and a `try/catch` around it never sees delivery errors. For must-deliver mail, write the marker from a `MessageSent` / `NotificationSent` listener or inside the queued job after the send, and give it tries and `failed()`. Never set a record to processed, then send sync in the same `try`: a transport error flips it to failed.
- Mailables use `envelope(): Envelope` and `content(): Content`. A legacy `build()` still runs in 13, so migrate only when you touch the class. Public properties reach the view. Markdown views must not indent text (indented lines render as code). Attach from storage: the mail renders on the worker.
- Email verification: `SendEmailVerificationNotification` is already registered for `Registered`. Dispatch `event(new Registered($user))` and stop. A second `$user->sendEmailVerificationNotification()` or a separate `Event::listen()` sends two mails. A `$listen` entry for it is skipped by the framework. Applies only when `User implements MustVerifyEmail`.

## Testing

```php
// bad: silences observers and model events for the rest of the test
protected function setUp(): void { parent::setUp(); Event::fake(); }
// good
Event::fake([PrizeAwarded::class]);   // other events, observers and model events still run
Event::assertDispatched(PrizeAwarded::class);
Event::assertListening(OrderShipped::class, SendShipmentNotification::class);
```
- Fake only what you assert: `Event::fake([X::class])`, `Event::fakeExcept([...])`, or `Event::fakeFor(fn () => ..., [...])` for one block. A bare `Event::fake()` also stops model events.
- Wiring and behaviour are separate tests. `assertListening()` proves registration and fails when discovery skipped an `object`-typed handler. Call `(new SendShipmentNotification)->handle(new OrderShipped($order))` to prove the behaviour. `assertDispatched` alone proves neither.
- `Mail::fake()` records mailables and never renders them, so a broken view or `content()` passes. Render each mailable once:
```php
Mail::fake();
$this->postJson('/api/invoices/1/send')->assertOk();
Mail::assertQueued(InvoicePaid::class, fn ($mail) => $mail->hasTo($user->email));
Mail::assertQueuedOnce(InvoicePaid::class);
(new InvoicePaid($invoice))->assertSeeInHtml($invoice->number);
```
- A `ShouldQueue` mailable counts as queued even when sent with `send()`: use `assertQueued`, not `assertSent`. Use `assertOutgoingCount()` / `assertNothingOutgoing()` when you don't care which. `Mail::raw()`, `html()`, `plain()` and `send('view')` with a non-mailable are silent no-ops in the fake.
- `Notification::fake()` runs `via()` and `shouldSend()` but never `toMail()` or `toArray()`. Assert recipients and channels, then test content apart:
```php
Notification::fake()->serializeAndRestore();   // catches serialization bugs in queued notifications
Notification::assertSentTo($order->customer, OrderShipped::class, fn ($n, $channels) => $channels === ['mail', 'database']);
Notification::assertSentOnDemand(Alert::class);   // for Notification::route(...)
$html = (new OrderShipped($order))->toMail($order->customer)->render();
```
- `assertSentTo([])` with an empty list throws. `toMail()` has `render()` only when it returns a `MailMessage`, not a `Mailable`.
- Push one test through the real database channel, with no fake, and assert `assertDatabaseCount('notifications', 1)`.
- For end-to-end counts use the real mail path on the `array` transport (the skeleton's `phpunit.xml` sets `MAIL_MAILER=array`) and `Queue::fake()` or the sync queue. A fake cannot show a rendering or recipient bug.

Evidence: `Illuminate/Events/Dispatcher.php`, `Foundation/Events/DiscoverEvents.php`, `Foundation/Support/Providers/EventServiceProvider.php`, `Contracts/Events/{ShouldDispatchAfterCommit,ShouldHandleEventsAfterCommit}.php`, `Contracts/Queue/ShouldQueueAfterCommit.php`, `Bus/Queueable.php`, `Mail/{PendingMail,Mailable,SendQueuedMailable}.php`, `Notifications/{NotificationSender,AnonymousNotifiable,SendQueuedNotifications}.php`, `Notifications/Console/NotificationTableCommand.php`, `Database/Eloquent/Relations/Concerns/InteractsWithPivotTable.php`, `Support/Testing/Fakes/{EventFake,MailFake,NotificationFake}.php`, `Support/Facades/Event.php`; `laravel/docs/events.md`, `eloquent.md`, `notifications.md`, `mail.md`, `mocking.md`, `verification.md`; laravel/laravel `phpunit.xml`.
