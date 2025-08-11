<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Support\Facades\Toast;
use Orchid\Support\Color;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Illuminate\Http\Request;
use App\Services\VpnService;

class ClientScreen extends Screen
{
    public function query(): array
    {
        return [
            'clients' => Client::latest()->paginate()
        ];
    }

    public function name(): ?string
    {
        return 'Клиенты';
    }

    public function description(): ?string
    {
        return 'Управление клиентами системы';
    }

    public function commandBar(): array
    {
        return [
            ModalToggle::make('Добавить клиента')
                ->modal('clientModal')
                ->method('save')
                ->icon('plus')
        ];
    }

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
                TD::make('Действия')
                    ->render(function (Client $client) {
                        return ModalToggle::make('')
                            ->modal('clientModal')
                            ->method('save')
                            ->asyncParameters(['client' => $client->id])
                            ->icon('pencil')
                            ->class('btn btn-primary mr-2')
                            .
                            Button::make('')
                            ->icon('trash')
                            ->method('delete', ['id' => $client->id])
                            ->confirm('Удалить клиента?')
                            ->class('btn btn-danger');
                    })
            ]),

            Layout::modal('clientModal', Layout::rows([
                Input::make('client.id')->type('hidden'),
                Input::make('client.name')->title('Имя')->required(),
                Input::make('client.password')->title('Пароль')->required(),
                Select::make('client.user_id')
                    ->fromModel(\App\Models\User::class, 'name', 'id')
                    ->title('Пользователь')
                    ->empty('Не выбран'),
                Input::make('client.server_name')->title('Сервер'),
                Input::make('client.owner_id')->title('ID владельца'),
                Input::make('client.telegram_nickname')->title('Telegram')
            ]))
                ->title('Форма клиента')
                ->applyButton('Сохранить')
                ->closeButton('Отмена')
                ->async('asyncGetClient')
        ];
    }

    public function asyncGetClient(Client $client): array
    {
        return [
            'client' => $client
        ];
    }

    public function save(Request $request, VpnService $vpn)
    {
        $request->validate([
            'client.name' => 'required|string|max:255',
            'client.password' => 'required|string'
        ]);

        $data = $request->input('client');

        try {
            if (empty($data['id'])) {

                $client = Client::create($data);
                // Добавляем в VPN
                $vpn->addUser($client->name, $client->password);
                Toast::success('Клиент создан');
            } else {
                Client::findOrFail($data['id'])->update($data);
                Toast::success('Клиент обновлён');
            }
        } catch (\Exception $e) {
            Toast::error('Ошибка: ' . $e->getMessage());
        }
    }

    public function delete(Request $request, VpnService $vpn)
    {
        $request->validate(['id' => 'required|exists:clients,id']);

        $client = Client::findOrFail($request->input('id'));
        $name = $client->name;

        try {
            // Сначала удаляем из VPN
            $vpn->removeUser($name);
            // Потом удаляем из базы
            $client->delete();

            Toast::success("Клиент {$name} удалён из VPN и базы");
        } catch (\Exception $e) {
            Toast::error('Ошибка при удалении: ' . $e->getMessage());
        }
    }
}
