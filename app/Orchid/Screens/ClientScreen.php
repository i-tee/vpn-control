<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;
use Orchid\Support\Color;

class ClientScreen extends Screen
{
    /**
     * @var Client
     */
    public $client;

    /**
     * Fetch data to be displayed on the screen.
     */
    public function query(): array
    {
        return [
            'clients' => Client::orderBy('id', 'desc')->paginate()
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Управление клиентами';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Создавайте и управляйте клиентами системы';
    }

    /**
     * The screen's action buttons.
     */
    public function commandBar(): array
    {
        return [
            Link::make('Добавить клиента')
                ->icon('plus')
                ->route('platform.client.create')
        ];
    }

    /**
     * The screen's layout elements.
     */
    public function layout(): array
    {
        return [
            Layout::table('clients', [
                TD::make('id', '#'),
                TD::make('name', 'Имя'),
                TD::make('server_name', 'Сервер'),
                TD::make('telegram_nickname', 'Telegram'),
                TD::make('created_at', 'Дата создания')
                    ->render(function ($client) {
                        return $client->created_at->toDateTimeString();
                    }),
                TD::make(__('Actions'))
                    ->align(TD::ALIGN_RIGHT)
                    ->render(
                        fn(Client $client) =>
                        Button::make('Редактировать')
                            ->route('platform.client.edit', ['client' => $client->id])
                            ->type(Color::LINK())
                            ->icon('pencil')
                    )
            ])
        ];
    }
}