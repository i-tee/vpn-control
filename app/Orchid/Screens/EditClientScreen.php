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

class EditClientScreen extends Screen
{
    /**
     * @var Client
     */
    public $client;

    /**
     * Fetch data to be displayed on the screen.
     */
    public function query(Client $client): array
    {
        return [
            'client' => $client
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Редактирование клиента';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Измените данные клиента';
    }

    /**
     * The screen's layout elements.
     */
    public function layout(): array
    {
        return [
            Layout::rows([
                Input::make('client.id')
                    ->type('hidden')
                    ->value($this->client->id),
                    
                Input::make('client.name')
                    ->title('Имя')
                    ->required(),
                Input::make('client.password')
                    ->title('Пароль')
                    ->type('text')
                    ->required()
                    ->help('Хранится в открытом виде'),
                Select::make('client.user_id')
                    ->fromModel(\App\Models\User::class, 'name', 'id')
                    ->title('Пользователь системы')
                    ->empty('Не выбран'),
                Input::make('client.server_name')
                    ->title('Название сервера'),
                Input::make('client.owner_id')
                    ->title('ID владельца'),
                Input::make('client.telegram_nickname')
                    ->title('Telegram ник')
            ]),
            
            Layout::rows([
                Button::make('Отмена')
                    ->url(route('platform.clients'))
                    ->type(Color::LIGHT()),
                Button::make('Сохранить')
                    ->method('save')
                    ->canSee(true)
                    ->type(Color::PRIMARY())
            ])
        ];
    }

    /**
     * Сохранение клиента
     */
    public function save()
    {
        // Получаем ID клиента из формы
        $clientId = request()->input('client.id');
        
        // Находим клиента по ID
        $client = Client::findOrFail($clientId);
        
        $clientData = request()->input('client', []);
        
        // Убедимся, что все поля заполнены
        $clientData['owner_id'] = $clientData['owner_id'] ?? '';
        $clientData['telegram_nickname'] = $clientData['telegram_nickname'] ?? '';
        
        $client->fill($clientData)->save();
        
        Toast::info('Клиент сохранен');
        
        return redirect()->route('platform.clients');
    }
}