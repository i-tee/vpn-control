<?php

namespace App\Orchid\Screens;

use App\Models\Client;
use App\Models\User;
use App\Services\VpnService;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Support\Facades\Toast;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        return 'Clients';
    }

    public function description(): ?string
    {
        return 'Manage clients on remote VPN servers';
    }

    public function commandBar(): array
    {
        return [
            ModalToggle::make('Add Client')
                ->modal('createClientModal')
                ->method('save')
                ->icon('plus')
                ->className('btn btn-success'),
        ];
    }

    public function layout(): array
    {
        // 🔐 Только пользователи с ролью consumer
        $owners = User::whereHas('roles', function ($query) {
            $query->where('name', 'consumer');
        })->pluck('name', 'id')->toArray();

        if (empty($owners)) {
            $owners = ['0' => 'No consumer users found'];
        }

        // 🌐 Серверы: ключи (имена) как значения, IP — в скобках
        $servers = collect(config('vpn.servers'))
            ->mapWithKeys(fn($server, $key) => [
                $key => "$key ({$server['host']})"
            ])
            ->toArray();

        return [
            // Таблица с клиентами — все поля
            Layout::table('clients', [
                TD::make('id', '#'),
                TD::make('name', 'Username'),
                TD::make('password', 'Password'), // Пароль — просто текст
                TD::make('user_id', 'Owner')
                    ->render(fn(Client $client) => $client->user?->name ?? '—'),
                TD::make('server_name', 'Server'),
                TD::make('telegram_nickname', 'Telegram'),
                TD::make('created_at', 'Created')
                    ->render(fn($client) => $client->created_at->format('d.m.Y H:i')),

                TD::make('actions', 'Actions')
                    ->align(TD::ALIGN_CENTER)
                    ->render(
                        fn(Client $client) =>
                        Button::make('Delete')
                            ->icon('trash')
                            ->confirm("Delete client '{$client->name}'?")
                            ->method('delete', ['id' => $client->id])
                            ->className('btn btn-danger btn-sm')
                    ),
            ]),

            // Модальное окно: только создание
            Layout::modal('createClientModal', [
                Layout::rows([
                    Input::make('client.name')
                        ->title('Username')
                        ->placeholder('Enter username')
                        ->required(),

                    Input::make('client.password')
                        ->title('Password')
                        //->type('password')
                        ->placeholder('Enter password')
                        ->required(),

                    Select::make('client.user_id')
                        ->title('Owner')
                        ->options($owners)
                        ->value(array_key_first($owners)) // первый по умолчанию
                        ->required(),

                    Select::make('client.server_name')
                        ->title('Server')
                        ->options($servers)
                        ->value(config('vpn.default_server'))
                        ->required(),

                    Input::make('client.telegram_nickname')
                        ->title('Telegram')
                        ->placeholder('@username'),
                ]),
            ])->title('Create Client')
                ->applyButton('Create')
                ->closeButton('Cancel'),
        ];
    }

    public function save(Request $request)
    {
        $request->validate([
            'client.name' => 'required|string|max:50|unique:clients,name',
            'client.password' => 'required|string|min:8',
            'client.user_id' => 'required|exists:users,id',
            'client.server_name' => 'required|string',
        ]);

        $data = $request->input('client');

        try {
            // Сначала создаем клиента в БД
            $client = Client::create($data);

            // Затем инициализируем VpnService с ВЫБРАННЫМ сервером
            $vpn = new VpnService($client->server_name); // Вот ключевое изменение!
            $vpn->addUser($client->name, $client->password);

            Toast::success("✅ Client '{$client->name}' created on server {$client->server_name}");
        } catch (\Exception $e) {
            // Удаляем клиента из БД, если не удалось добавить на VPN
            if (isset($client)) {
                $client->delete();
            }
            Toast::error('❌ Error: ' . $e->getMessage());
        }
    }

    public function delete(Request $request, VpnService $vpn)
    {
        $request->validate(['id' => 'required|exists:clients,id']);

        $client = Client::findOrFail($request->input('id'));
        $name = $client->name;

        try {
            // Создаем экземпляр VpnService с сервером из записи клиента
            $vpn = new VpnService($client->server_name);

            $vpn->removeUser($name);
            $client->delete();

            Toast::success("🗑️ Client '{$name}' removed from server {$vpn->getCurrentServer()}");
        } catch (\Exception $e) {
            Toast::error('❌ Error: ' . $e->getMessage());
        }
    }
}
