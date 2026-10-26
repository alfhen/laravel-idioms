<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TicketStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $tickets = Ticket::all();

        $open = 0;
        $resolved = 0;
        $hours = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->status == TicketStatus::Open || $ticket->status == TicketStatus::Pending) {
                $open++;
            }

            if ($ticket->status == TicketStatus::Resolved && $ticket->resolved_at) {
                $resolved++;
                $hours += $ticket->created_at->diffInHours($ticket->resolved_at);
            }
        }

        return [
            Stat::make('Open tickets', $open),
            Stat::make('Resolved tickets', $resolved),
            Stat::make('Average resolution (hours)', $resolved ? round($hours / $resolved, 1) : 0),
        ];
    }
}
