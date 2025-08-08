<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;
use Orchid\Support\Color;

class CreateClientScreen extends Screen
{
    /**
     * Название экрана
     */
    public function name(): ?string
    {
        return 'Создать клиента';
    }

    /**
     * Описание экрана
     */
    public function description(): ?string
    {
        return 'Заполните форму для создания нового клиента';
    }

    /**
     * Запрос данных
     */
    public function query(): iterable
    {
        return [];
    }

    /**
     * Элементы интерфейса
     */
    public function layout(): array
    {
        return [
            Layout::rows([
                Input::make('client.name')
                    ->title('Имя')
                    ->required()
                    ->id('client-name'),
                Input::make('client.password')
                    ->title('Пароль')
                    ->type('text')
                    ->required()
                    ->help('Хранится в открытом виде')
                    ->id('client-password'),
                Select::make('client.user_id')
                    ->fromModel(\App\Models\User::class, 'name', 'id')
                    ->title('Пользователь системы')
                    ->empty('Не выбран')
                    ->id('client-user-id'),
                Input::make('client.server_name')
                    ->title('Название сервера')
                    ->id('client-server-name'),
                Input::make('client.owner_id')
                    ->title('ID владельца')
                    ->id('client-owner-id'),
                Input::make('client.telegram_nickname')
                    ->title('Telegram ник')
                    ->id('client-telegram-nickname'),

                // КНОПКИ ДОЛЖНЫ БЫТЬ ЧАСТЬЮ ТОГО ЖЕ МАКЕТА
                Button::make('Отмена')
                    ->url(route('platform.clients'))
                    ->type(Color::LIGHT()),
                Button::make('Сохранить')
                    ->method('save')
                    ->type(Color::PRIMARY())
            ])
        ];
    }

    /**
     * Обработчик сохранения
     */
    public function save(Request $request): \Illuminate\Http\RedirectResponse
    {
        $client = new Client();
        $client->fill($request->get('client'))->save();

        Toast::info('Клиент успешно создан');

        return redirect()->route('platform.clients');
    }
}
