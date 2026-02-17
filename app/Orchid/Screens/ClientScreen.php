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
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\WhereDateStartEnd;

class ClientScreen extends Screen
{
    public function query(): array
    {
        return [
            'clients' => Client::query()
                ->with('user')
                ->filters()
                ->defaultSort('created_at', 'desc')
                ->paginate(),
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

        // 🌐 Серверы: ключи (имена) как значения
        $servers = collect(config('vpn.servers'))
            ->mapWithKeys(fn($server, $key) => [
                $key => "$key"
            ])
            ->toArray();

        return [
            // Таблица с клиентами — все поля с фильтрами и сортировкой
            Layout::table('clients', [
                TD::make('id', 'ID')
                    ->sort()
                    ->filter(),

                TD::make('name', 'Username')
                    ->sort()
                    ->filter(),

                TD::make('password', 'Password')
                    ->render(fn(Client $client) => $client->password),

                // Owner: фильтр и сортировка по user_id, отображаем ID + имя
                TD::make('user_id', 'ID : Owner')
                    ->sort()
                    ->filter()
                    ->render(fn(Client $client) => 
                        $client->user_id . ' : ' . ($client->user?->name ?? '—')
                    ),

                TD::make('server_name', 'Server')
                    ->sort()
                    ->filter(),

                TD::make('created_at', 'Created')
                    ->sort()
                    ->filter(TD::FILTER_DATE_RANGE)
                    ->render(fn($client) => $client->created_at->format('d.m.Y H:i')),

                TD::make('is_active', 'Status')
                    ->sort()
                    ->render(
                        fn(Client $client) =>
                        $client->is_active
                            ? '<span class="badge bg-success">Active</span>'
                            : '<span class="badge bg-danger">Inactive</span>'
                    ),

                TD::make('swapstatus', 'Actions')
                    ->align(TD::ALIGN_CENTER)
                    ->render(
                        fn(Client $client) =>
                        Button::make('Swap Status')
                            ->icon('recycle')
                            ->confirm("Swap Status from '{$client->name}'?")
                            ->method('swap', ['id' => $client->id])
                            ->className('btn btn-danger btn-sm')
                    ),

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
                        ->placeholder('Enter password')
                        ->required(),

                    Select::make('client.user_id')
                        ->title('Owner')
                        ->options($owners)
                        ->value(array_key_first($owners))
                        ->required(),

                    Select::make('client.server_name')
                        ->title('Server')
                        ->options($servers)
                        ->value(config('vpn.default_server'))
                        ->required(),
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
            $client = Client::create($data);
            $vpn = new VpnService($client->server_name);
            $vpn->addUser($client->name, $client->password);

            Toast::success("Client '{$client->name}' created on server {$client->server_name}");
        } catch (\Exception $e) {
            if (isset($client)) {
                $client->delete();
            }
            Toast::error('Error: ' . $e->getMessage());
        }
    }

    public function delete(Request $request, VpnService $vpn)
    {
        $request->validate(['id' => 'required|exists:clients,id']);

        $client = Client::findOrFail($request->input('id'));
        $name = $client->name;

        try {
            $vpn = new VpnService($client->server_name);
            $vpn->removeUser($name);
            $client->delete();

            Toast::success("Client '{$name}' removed from server {$vpn->getCurrentServer()}");
        } catch (\Exception $e) {
            Toast::error('Error: ' . $e->getMessage());
        }
    }

    public function swap(Request $request, VpnService $vpn)
    {
        $request->validate(['id' => 'required|exists:clients,id']);

        $client = Client::findOrFail($request->input('id'));

        try {
            $vpn = new VpnService($client->server_name);

            if ($client->is_active) {
                $vpn->deactivateClient($client->id);
            } else {
                $vpn->activateClient($client->id);
            }

            Toast::success("Status now '" . ($client->fresh()->is_active ? 'Active' : 'Inactive') . "'");
        } catch (\Exception $e) {
            Toast::error('Error: ' . $e->getMessage());
        }
    }
}