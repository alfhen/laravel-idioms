# Filament 5

Check `filament/filament` in `composer.lock`. Everything here is the v4/v5 API. A v3 app keeps its own API until upgraded; don't mix.

## Scaffold, then fill in

```bash
php artisan make:filament-resource Customer --generate --view --soft-deletes   # --simple for a modal-only Manage page
php artisan make:filament-relation-manager CustomerResource addresses street
php artisan make:filament-page ViewCustomer --resource=CustomerResource --type=ViewRecord
```
Don't hand-write resources in the v3 flat layout.

## Layout

```
app/Filament/Resources/Customers/
    CustomerResource.php
    Pages/            ListCustomers, CreateCustomer, EditCustomer
    Schemas/          CustomerForm.php, CustomerInfolist.php
    Tables/           CustomersTable.php
    RelationManagers/ Widgets/
```
```php
/** @extends Resource<Customer> */
class CustomerResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'name';
    protected static string | UnitEnum | null $navigationGroup = 'Shop';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema { return CustomerForm::configure($schema); }
    public static function table(Table $table): Table { return CustomersTable::configure($table); }
    public static function getGloballySearchableAttributes(): array { return ['name', 'email']; }
}
```
Schema and table classes extend nothing and expose a static `configure()`. Use `Heroicon::*` enum cases, not `'heroicon-o-...'` strings.

## Namespaces (the most common v3 leftovers)

| Thing | v4/v5 |
|---|---|
| Form/infolist signature | `form(Schema $schema): Schema` with `$schema->components([...])`. Not `Filament\Forms\Form` / `$form->schema()` |
| Layout: Section, Grid, Tabs, Tabs\Tab, Wizard\Step, Fieldset, Group | `Filament\Schemas\Components\*` |
| `Get` / `Set` | `Filament\Schemas\Components\Utilities\{Get, Set}` |
| Fields (TextInput, Select, Toggle, Repeater) | `Filament\Forms\Components\*` |
| Entries (TextEntry, IconEntry) | `Filament\Infolists\Components\*` |
| Every action (Edit, Delete, Create, BulkAction, BulkActionGroup, DeleteBulkAction, ActionGroup, Action) | `Filament\Actions\*`. `Filament\Tables\Actions\*` no longer exists |
| Table row / bulk actions | `->recordActions([...])`, `->toolbarActions([BulkActionGroup::make([...])])`. `->actions()`/`->bulkActions()` are deprecated |
| Action/filter modal fields | `->schema([...])`. `->form()` is deprecated |
| Page header buttons | `getHeaderActions()`. `getActions()` is deprecated (the demo still uses it; don't copy) |
| Widget `$heading`, `$maxHeight`, `$pollingInterval` | non-static instance properties (static is a fatal redeclaration) |
| Relation manager `form()` / `table()` | instance methods, not static |
| `MultiSelectFilter` | `SelectFilter::make()->multiple()` |

## Forms

```php
return $schema->components([
    Section::make()->schema([
        TextInput::make('name')->required()->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(function (string $operation, $state, Set $set): void {
                if ($operation !== 'create') {
                    return;
                }
                $set('slug', Str::slug($state));
            }),
        TextInput::make('email')->email()->required()->unique(Employee::class, 'email', ignoreRecord: true),
        Select::make('department_id')->relationship('department', 'name')->searchable()->preload(),
        TextInput::make('password')->password()->required()->hiddenOn(Operation::Edit),
        TextInput::make('salary')->visible(fn (Get $get): bool =>
            in_array($get('employment_type'), [EmploymentType::FullTime, EmploymentType::FullTime->value])),
    ]),
]);
```
- `->relationship()` for belongs-to selects, not hand-loaded `pluck()` options.
- Only `->live()` fields re-render; text inputs that drive others use `live(onBlur: true)`.
- Live `$get()` state may hold the enum or its raw value; compare both.

## Enums carry label, colour, icon

```php
enum OrderStatus: string implements HasColor, HasIcon, HasLabel   // Filament\Support\Contracts
{
    case New = 'new';
    public function getColor(): string { return match ($this) { self::New => 'info', /* ... */ }; }
    // getLabel(), getIcon()
}

TextColumn::make('status')->badge();                          // picks up label/colour/icon
SelectFilter::make('status')->options(OrderStatus::class);
```
Cast the model attribute to the enum. No per-column `->color(fn ...)` maps or hand-built option arrays.

## Tables

```php
return $table
    ->columns([
        TextColumn::make('customer.name')->searchable()->sortable()->toggleable(),   // dot notation eager-loads
        TextColumn::make('total_price')->money()->alignEnd()->sortable()->summarize(Sum::make()->money()),
        TextColumn::make('users_count')->counts('users'),
    ])
    ->defaultSort('created_at', 'desc')
    ->filters([
        SelectFilter::make('department')->relationship('department', 'name'),
        TernaryFilter::make('is_active'),
        TrashedFilter::make(),
        Filter::make('created_at')
            ->schema([DatePicker::make('created_from'), DatePicker::make('created_until')])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['created_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($data['created_until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
    ])
    ->recordActions([EditAction::make()])
    ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
```
- Formatters (`->money()`, `->date()`, `->numeric()`, IconColumn `->boolean()`, `->counts()`) over `formatStateUsing` closures. `getStateUsing(fn ($r) => $r->customer->name)` is an N+1.
- Custom filters add `->indicateUsing()` so active filters show as chips.
- Status tabs on the List page: `getTabs()` returning `Tab::make()->query(fn ($q) => ...)->badge(fn () => ...)->deferBadge()->badgeColor(OrderStatus::New->getColor())`.

## Actions

```php
Action::make('cancel')
    ->color('danger')
    ->authorize('cancel')                                        // OrderPolicy::cancel()
    ->visible(fn (Order $record): bool => ! in_array($record->status, [OrderStatus::Delivered, OrderStatus::Cancelled]))
    ->requiresConfirmation()
    ->action(function (Order $record): void {
        $record->update(['status' => OrderStatus::Cancelled]);
        Notification::make()->title('Order cancelled')->danger()->send();
    });

Action::make('change_department')
    ->fillForm(fn (Employee $record): array => ['department_id' => $record->department_id])
    ->schema([Select::make('department_id')->relationship('department', 'name')->required()])
    ->action(fn (Employee $record, array $data) => $record->update($data));
```
- Type-hint `$record`/`$data` in closures. Group row menus with `ActionGroup::make([...])`; use `->slideOver()` for longer input.
- Feedback: `Notification::make()->title()->success()|warning()|danger()->send()`. Persisted: `->sendToDatabase($user)`, which needs `->databaseNotifications()` on the panel. URLs via `OrderResource::getUrl('edit', ['record' => $order])`, not `route()`.

## Pages and relation managers

- Lifecycle logic in hooks: `mutateFormDataBeforeCreate/Save/Fill`, `handleRecordCreation`, `afterCreate`/`afterSave`, `getRedirectUrl`. Don't override `create()`/`save()`.
- Relation manager: `protected static string $relationship = 'comments';`, instance `form(Schema)`, `infolist(Schema)`, `table(Table)`; `CreateAction` in `->headerActions()`, View/Edit/Delete in `->recordActions()`; registered in the resource's `getRelations()`. `--attach`/`--associate` for linking; a `ManageRelatedRecords` page when the relation deserves its own page.
- Widgets: `StatsOverviewWidget::getStats()` returning `Stat::make($label, $value)->chart([...])`; `ChartWidget` implementing `getType()` and `getData()`. Stats that follow the list's filters: `ExposesTableToWidgets` on the List page, `InteractsWithPageTable` + `getTablePage()` in the widget, querying `getPageTableQuery()`.

## Authorization and security

- A normal model policy drives resources: `viewAny`, `create`, `update`, `view`, `delete`/`deleteAny`, `forceDelete(Any)`, `restore(Any)`, `reorder`. A missing or false `viewAny()` hides the resource from navigation with no error.
- Bulk actions check `deleteAny`; add `->authorizeIndividualRecords()` when each record must pass `delete()`.
- Custom `Action`s, inline-editable columns (`ToggleColumn`, `SelectColumn`, `TextInputColumn`) and custom pages are **not** policy-checked. Add `->authorize()`, `->visible()` or `->disabled(fn (Product $record) => ! auth()->user()->can('update', $record))`.
- Production panel access needs `User implements FilamentUser` with `canAccessPanel(Panel $panel): bool`; without it the panel works locally and returns 403 once deployed.
- Scope data in `getEloquentQuery()` (applies to every page and global search): tenant constraints, eager loads, `withoutGlobalScopes([SoftDeletingScope::class])`. `$table->modifyQueryUsing()` for list-only changes; eager-load global-search details in `getGlobalSearchEloquentQuery()->with([...])`. Filters and tabs are not a security boundary.
- Every attribute not in `$hidden` reaches the browser on Edit/View pages. Hide sensitive columns or `unset()` them in `mutateFormDataBeforeFill()`. User-supplied `->url()` values go through `Str::sanitizeUrl()`.

## Testing

```php
// tests/Pest.php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->actingAs(User::factory()->create()))
    ->in('Filament');

it('can filter trashed orders', function () {
    $active = Order::factory()->create();
    $trashed = tap(Order::factory()->create())->delete();

    livewire(ListOrders::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$trashed]);
});

it('validates the form data', function (array $data, array $errors) {
    livewire(CreateOrder::class)
        ->fillForm(['customer_id' => Customer::factory()->create()->id, 'status' => OrderStatus::New, ...$data])
        ->call('create')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`status` is required' => [['status' => null], ['status' => 'required']],
]);

livewire(ListOrders::class)
    ->callAction(TestAction::make('ship')->table($order), ['notes' => 'Handle with care'])
    ->assertNotified();
livewire(ListOrders::class)->assertActionDisabled(TestAction::make('cancel')->table($shippedOrder));
livewire(ListOrders::class)->selectTableRecords($orders)->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());
livewire(EditUser::class, ['record' => $user->id])->assertSchemaStateSet(['name' => $user->name])->callAction(DeleteAction::class)->assertRedirect();
livewire(CommentsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
    ->callAction(TestAction::make('create')->table(), ['title' => 'Great', 'customer_id' => $customer->id, 'content' => 'x'])
    ->assertNotified();
```
- Test pages as Livewire components, not `$this->get('/admin/...')->assertSee()`. Build input with `Factory::make()`; check persistence with `assertDatabaseHas(Model::class, [...])`.
- Sort order: `assertCanSeeTableRecords($records, inOrder: true)` after `sortTable()`.
- Actions via `Filament\Actions\Testing\TestAction`; `callTableAction`, `callTableBulkAction`, `assertTableActionHidden` and `assertFormSet` are deprecated. Gating: `assertActionHidden`/`assertActionDisabled`.
- Relation managers need both `ownerRecord` and `pageClass`.
- Repeaters: `$undo = Repeater::fake(); ... $undo();`.

Evidence: `filamentphp/filament` `docs/03-resources/*`, `docs/09-advanced/{03-enums,06-security}.md`, `docs/10-testing/*`, `packages/{actions,schemas,tables,widgets}/src`; filamentphp/demo `app/Filament/`, `app/Enums/`, `tests/`.
