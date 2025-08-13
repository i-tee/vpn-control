<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\User;
use App\Services\BinderService;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;
use Illuminate\Http\Request;

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
        $query = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->withCount('clients'); // Загружаем количество клиентов для оптимизации
        
        return [
            'users' => $query->orderBy('created_at', 'desc')->paginate(20),
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
                    ->render(fn ($user) => 
                        Link::make($user->id)
                            ->route('platform.consumers.edit', $user->id)
                    ),
                
                TD::make('name', 'Name')
                    ->sort(),
                
                TD::make('email', 'Email')
                    ->sort(),
                
                // НОВАЯ КОЛОНКА С КОЛИЧЕСТВОМ VPN КЛИЕНТОВ
                TD::make('clients_count', 'VPN Clients')
                    ->sort()
                    ->render(fn ($user) => 
                        $this->binderService->countVpnClientsForUser($user)
                    ),
                
                TD::make('created_at', 'Date')
                    ->sort()
                    ->render(fn ($user) => $user->created_at->format('Y-m-d H:i')),
            ]),
        ];
    }
}