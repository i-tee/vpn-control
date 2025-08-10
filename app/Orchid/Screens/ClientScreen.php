<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Support\Facades\Toast;
use Orchid\Support\Color;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Illuminate\Http\Request;

class ClientScreen extends Screen
{
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
            ModalToggle::make('Добавить клиента')
                ->modal('createClientModal')
                ->method('createClient')
                ->icon('plus')
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
                        ModalToggle::make('Редактировать')
                            ->modal('editClientModal')
                            ->method('editClient')
                            ->parameters(['client_id' => $client->id])
                            ->type(Color::LINK())
                            ->icon('pencil')
                    )
            ]),
            
            // Модальное окно для создания
            Layout::modal('createClientModal', Layout::rows([
                Input::make('name')
                    ->title('Имя')
                    ->required(),
                Input::make('password')
                    ->title('Пароль')
                    ->type('text')
                    ->required()
                    ->help('Хранится в открытом виде'),
                Select::make('user_id')
                    ->fromModel(\App\Models\User::class, 'name', 'id')
                    ->title('Пользователь системы')
                    ->empty('Не выбран'),
                Input::make('server_name')
                    ->title('Название сервера'),
                Input::make('owner_id')
                    ->title('ID владельца'),
                Input::make('telegram_nickname')
                    ->title('Telegram ник')
            ]))->title('Создать клиента')
              ->applyButton('Сохранить'),

            // Модальное окно для редактирования (асинхронное, с предзаполнением)
            Layout::modal('editClientModal', Layout::rows([
                Input::make('client_id')
                    ->type('hidden'),
                Input::make('name')
                    ->title('Имя')
                    ->required(),
                Input::make('password')
                    ->title('Пароль')
                    ->type('text')
                    ->required()
                    ->help('Хранится в открытом виде'),
                Select::make('user_id')
                    ->fromModel(\App\Models\User::class, 'name', 'id')
                    ->title('Пользователь системы')
                    ->empty('Не выбран'),
                Input::make('server_name')
                    ->title('Название сервера'),
                Input::make('owner_id')
                    ->title('ID владельца'),
                Input::make('telegram_nickname')
                    ->title('Telegram ник')
            ]))->title('Редактировать клиента')
              ->applyButton('Сохранить')
              ->async('asyncLoadClient') // Асинхронная загрузка данных
        ];
    }

    /**
     * Асинхронная загрузка данных для модального редактирования
     */
    public function asyncLoadClient(int $client_id): array
    {
        $client = Client::findOrFail($client_id);
        return [
            'client_id' => $client->id,
            'name' => $client->name,
            'password' => $client->password,
            'user_id' => $client->user_id,
            'server_name' => $client->server_name,
            'owner_id' => $client->owner_id,
            'telegram_nickname' => $client->telegram_nickname
        ];
    }

    /**
     * Создание клиента из модального
     */
    public function createClient(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
            // Добавь другие
        ]);

        Client::create($request->all());
        Toast::info('Клиент создан');
    }

    /**
     * Редактирование клиента из модального
     */
    public function editClient(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
            // Добавь другие
        ]);

        $client = Client::findOrFail($request->input('client_id'));
        $client->update($request->all());
        Toast::info('Клиент обновлен');
    }
}