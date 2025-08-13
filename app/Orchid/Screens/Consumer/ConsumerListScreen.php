<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\User;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;

class ConsumerListScreen extends Screen
{
    public function query(): iterable
    {
        // Получаем пользователей с ролью "consumer"
        $query = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        });
        
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

    public function commandBar(): iterable
    {
        return [
            Link::make('Create new')
                ->icon('plus')
                ->route('platform.consumers.create'),
        ];
    }

    public function layout(): iterable
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
                
                TD::make('created_at', 'Date')
                    ->sort()
                    ->render(fn ($user) => $user->created_at->format('Y-m-d H:i')),
            ]),
        ];
    }
}