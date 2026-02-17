<?php

namespace App\Orchid\Screens\Consumer;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VpnService;
use Orchid\Platform\Models\Role;
use Illuminate\Http\Request;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Toast;

class ConsumerEditScreen extends Screen
{
    public ?User $user = null;

    public function query($id): iterable
    {
        $this->user = User::findOrFail($id);

        // Получаем клиентов этого пользователя с пагинацией
        $clients = Client::where('user_id', $this->user->id)
            ->latest()
            ->paginate(10);

        return [
            'user'    => $this->user,
            'clients' => $clients,
            'balance' => $this->user->balance(), // передаём баланс для использования
        ];
    }

    public function name(): ?string
    {
        return $this->user->exists ? 'Edit Consumer' : 'Create Consumer';
    }

    public function description(): ?string
    {
        // Отображаем баланс прямо в описании
        return 'Manage consumer. Current balance: ₽ ' . number_format($this->user->balance(), 2);
    }

    public function commandBar(): iterable
    {
        return [
            // Кнопка открытия модального окна для создания транзакции
            ModalToggle::make('Add Transaction')
                ->modal('createTransactionModal')
                ->method('createTransaction')
                ->icon('plus')
                ->className('btn btn-success'),

            // Кнопка сохранения изменений пользователя
            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->post()
                ->noAjax()
                ->className('btn btn-primary'),
        ];
    }

    public function layout(): iterable
    {
        return [
            // Форма редактирования пользователя
            Layout::rows([
                Input::make('user_id')
                    ->type('hidden')
                    ->value($this->user->id),

                Input::make('user.name')
                    ->title('Name')
                    ->required()
                    ->value($this->user->name),

                Input::make('user.email')
                    ->title('Email')
                    ->type('email')
                    ->required()
                    ->value($this->user->email),

                Password::make('user.password')
                    ->title('New Password')
                    ->placeholder('Leave empty to keep current password'),
            ]),

            // Таблица с клиентами потребителя
            Layout::table('clients', [
                TD::make('id', 'ID')->sort(),
                TD::make('name', 'Username')->sort(),
                TD::make('password', 'Password'),
                TD::make('server_name', 'Server')->sort(),
                TD::make('is_active', 'Status')
                    ->sort()
                    ->render(fn (Client $client) => $client->is_active
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-danger">Inactive</span>'
                    ),
                TD::make('actions', 'Actions')
                    ->align(TD::ALIGN_CENTER)
                    ->render(function (Client $client) {
                        return
                            Button::make('Swap Status')
                                ->icon('recycle')
                                ->confirm("Swap status for '{$client->name}'?")
                                ->method('swapClient')
                                ->parameters(['client_id' => $client->id])
                                ->className('btn btn-warning btn-sm me-1') .
                            Button::make('Delete')
                                ->icon('trash')
                                ->confirm("Delete client '{$client->name}'?")
                                ->method('deleteClient')
                                ->parameters(['client_id' => $client->id])
                                ->className('btn btn-danger btn-sm');
                    }),
            ])->title('Clients of this consumer'),

            // Модальное окно для создания транзакции
            Layout::modal('createTransactionModal', [
                Layout::rows([
                    // ID пользователя передаётся скрытым полем
                    Input::make('transaction.user_id')
                        ->type('hidden')
                        ->value($this->user->id),

                    Select::make('transaction.type')
                        ->options([
                            'deposit'  => 'Deposit',
                            'withdraw' => 'Withdraw',
                        ])
                        ->title('Type')
                        ->required(),

                    Input::make('transaction.amount')
                        ->type('number')
                        ->step('0.01')
                        ->title('Amount')
                        ->required(),

                    TextArea::make('transaction.comment')
                        ->title('Comment')
                        ->rows(3),
                ]),
            ])->title('Create Transaction for ' . $this->user->name)
              ->applyButton('Create')
              ->closeButton('Cancel'),
        ];
    }

    public function save(Request $request)
    {
        $id = $request->route('id');
        $this->user = User::findOrFail($id);

        $data = $request->validate([
            'user.name'  => 'required|string|max:255',
            'user.email' => 'required|email|unique:users,email,' . $id,
            'user.password' => 'nullable|string|min:6',
        ])['user'];

        $this->user->name = $data['name'];
        $this->user->email = $data['email'];

        if (!empty($data['password'])) {
            $this->user->password = bcrypt($data['password']);
        }

        $this->user->save();

        // Убедимся, что у пользователя есть роль consumer
        $consumerRole = Role::where('slug', 'consumer')->first();
        if ($consumerRole && !$this->user->roles->contains($consumerRole)) {
            $this->user->roles()->attach($consumerRole);
        }

        Toast::info('Consumer updated successfully');
        return redirect()->route('platform.consumers.list');
    }

    public function swapClient(Request $request)
    {
        $clientId = $request->input('client_id');
        $client = Client::findOrFail($clientId);
        $userId = $client->user_id;

        try {
            $vpn = new VpnService($client->server_name);

            if ($client->is_active) {
                $vpn->deactivateClient($client->id);
            } else {
                $vpn->activateClient($client->id);
            }

            Toast::success("Status changed for client '{$client->name}'");
        } catch (\Exception $e) {
            Toast::error('Error: ' . $e->getMessage());
        }

        return redirect()->route('platform.consumers.edit', $userId);
    }

    public function deleteClient(Request $request)
    {
        $clientId = $request->input('client_id');
        $client = Client::findOrFail($clientId);
        $userId = $client->user_id;

        try {
            $vpn = new VpnService($client->server_name);
            $vpn->removeUser($client->name);
            $client->delete();

            Toast::success("Client '{$client->name}' deleted");
        } catch (\Exception $e) {
            Toast::error('Error: ' . $e->getMessage());
        }

        return redirect()->route('platform.consumers.edit', $userId);
    }

    /**
     * Создание транзакции для текущего пользователя
     */
    public function createTransaction(Request $request)
    {
        $data = $request->validate([
            'transaction.user_id' => 'required|exists:users,id',
            'transaction.type'    => 'required|in:deposit,withdraw',
            'transaction.amount'  => 'required|numeric|min:0',
            'transaction.comment' => 'nullable|string',
        ])['transaction'];

        // Используем метод модели для создания транзакции
        Transaction::createTransaction(
            $data['user_id'],
            $data['type'],
            $data['amount'],
            null, // subject_type
            null, // subject_id
            $data['comment'] ?? null,
            true  // is_active
        );

        Toast::success('Transaction created successfully');

        // Возвращаемся на страницу редактирования этого же пользователя
        return redirect()->route('platform.consumers.edit', $data['user_id']);
    }
}