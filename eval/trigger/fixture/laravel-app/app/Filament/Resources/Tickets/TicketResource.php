<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->options(\App\Models\Customer::all()->pluck('name', 'id'))
                ->required(),
            TextInput::make('subject')->required(),
            Textarea::make('body')->required(),
            Select::make('status')
                ->options(collect(TicketStatus::cases())->mapWithKeys(fn ($case) => [$case->value => ucfirst($case->value)]))
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('subject')->searchable(),
            TextColumn::make('customer.name'),
            TextColumn::make('status')->badge(),
            TextColumn::make('replies_count')
                ->state(fn (Ticket $record) => $record->replies()->count()),
            TextColumn::make('last_reply')
                ->state(fn (Ticket $record) => $record->lastReplyAt()),
        ]);
    }
}
