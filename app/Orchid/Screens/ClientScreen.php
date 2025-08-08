<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;

class ClientScreen extends Screen
{
    /**
     * @var Client
     */
    public $client;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @param Client $client
     *
     * @return array
     */
    public function query(Client $client): array
    {
        return [
            'clients' => Client::orderBy('id', 'desc')->paginate(),
            'client'  => $client
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
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): array
    {
        return [
            Link::make('Добавить клиента')
                ->icon('plus')
                ->route('platform.client.create') // Ссылка на новый экран
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
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
                        (string)ModalToggle::make('Редактировать')
                            ->modal('oneAsyncModal')
                            ->modalTitle('Редактирование клиента')
                            ->method('save')
                            ->asyncParameters([
                                'client' => $client->id
                            ])
                    )
            ]),

            Layout::modal('oneAsyncModal', [
                Layout::rows([
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
                ])
            ])->title('Данные клиента')->applyButton('Сохранить')
        ];
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Client $client)
    {
        $client->fill($this->request->get('client'))->save();

        Toast::info('Клиент сохранен');

        return redirect()->route('platform.clients');
    }
}
