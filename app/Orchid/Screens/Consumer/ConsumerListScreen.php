<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\User;
use App\Services\BinderService;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;

class ConsumerListScreen extends Screen
{
    /**
     * @var BinderService
     */
    protected $binderService;

    public function __construct(BinderService $binderService)
    {
        $this->binderService = $binderService;
    }

    public function query(): array
    {
        // Получаем пользователей с ролью consumer
        $users = User::whereHas('roles', fn($q) => $q->where('slug', 'consumer'))
            ->withCount('clients as vpn_clients_count')
            ->withBalance() // наш скоуп
            ->filters()
            ->defaultSort('id')
            ->paginate(15);

        return [
            'users' => $users,
        ];
    }

    public function name(): ?string
    {
        return 'Consumers';
    }

    public function description(): ?string
    {
        return 'List of all consumers in the system';
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
                    ->render(
                        fn($user) =>
                        Link::make($user->id)
                            ->route('platform.consumers.edit', $user->id)
                    ),

                TD::make('name', 'Name')
                    ->sort(),

                TD::make('email', 'Email')
                    ->sort(),

                // Колонка с количеством VPN клиентов
                TD::make('vpn_clients_count', 'VPN Clients')
                    ->sort()
                    ->render(
                        fn($user) =>
                        $user->vpn_clients_count
                    ),

                // Колонка с балансом пользователя
                TD::make('balance', 'Balance')
                    ->sort()
                    ->render(
                        fn($user) =>
                        '₽ ' . number_format($user->balance, 2)
                    ),

                TD::make('created_at', 'Date')
                    ->sort()
                    ->render(fn($user) => $user->created_at->format('Y-m-d H:i')),
            ]),
        ];
    }
}
