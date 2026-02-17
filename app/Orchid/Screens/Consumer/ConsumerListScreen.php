<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\User;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;

class ConsumerListScreen extends Screen
{
    public function query(): array
    {
        $users = User::whereHas('roles', fn($q) => $q->where('slug', 'consumer'))
            ->withCount('clients as vpn_clients_count')
            ->withBalance()
            ->filters() // автоматически применяет фильтры из allowedFilters
            ->defaultSort('id')
            ->paginate(15);

        return ['users' => $users];
    }

    public function name(): ?string
    {
        return 'Consumers';
    }

    public function description(): ?string
    {
        return 'List of all consumers in the system. Click on filter icons in the table headers to search.';
    }

    public function commandBar(): array
    {
        return [
            Link::make('Create new')
                ->icon('plus')
                ->route('platform.consumers.create'),
        ];
    }

    public function layout(): array
    {
        return [
            Layout::table('users', [
                TD::make('id', 'ID')
                    ->sort()
                    ->render(fn($user) => Link::make($user->id)
                        ->route('platform.consumers.edit', $user->id)),

                TD::make('name', 'Name')
                    ->sort()
                    ->filter(), // добавляет иконку фильтра

                TD::make('email', 'Email')
                    ->sort()
                    ->filter(),

                TD::make('vpn_clients_count', 'VPN Clients')
                    ->sort(),

                TD::make('balance', 'Balance')
                    ->sort()
                    ->render(fn($user) => '₽ ' . number_format($user->balance, 2)),

                TD::make('created_at', 'Date')
                    ->sort()
                    ->render(fn($user) => $user->created_at->format('Y-m-d H:i')),
            ]),
        ];
    }
}
