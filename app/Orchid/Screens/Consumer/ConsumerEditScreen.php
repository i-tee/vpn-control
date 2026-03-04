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
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Toast;

class ConsumerEditScreen extends Screen
{
    public ?User $user = null;
    public array $availableReferrals = [];

    public function query($id): iterable
    {
        $this->user = User::findOrFail($id);

        $clients = Client::where('user_id', $this->user->id)
            ->latest()
            ->paginate(10);

        $referrals = $this->user->referrals()
            ->latest()
            ->paginate(10, ['*'], 'referrals_page');

        // Формируем массив доступных рефералов с дополнительной информацией
        $this->availableReferrals = User::whereHas('roles', fn($q) => $q->where('slug', 'consumer'))
            ->whereNull('referrer_id')
            ->where('id', '!=', $this->user->id)
            ->get()
            ->mapWithKeys(fn($user) => [
                $user->id => "ID: {$user->id}, {$user->name}, {$user->email}"
            ])
            ->toArray();

        return [
            'user'      => $this->user,
            'clients'   => $clients,
            'referrals' => $referrals,
            'availableReferrals' => $this->availableReferrals,
            'balance'   => $this->user->balance(),
        ];
    }

    public function name(): ?string
    {
        return $this->user->exists
            ? 'Consumer [ID: ' . $this->user->id . '] ' . $this->user->name
            : 'Create Consumer';
    }

    public function description(): ?string
    {
        $balance = number_format($this->user->balance(), 2);
        return 'Current balance: ₽ ' . $balance;
    }

    public function commandBar(): iterable
    {
        $buttons = [
            ModalToggle::make('Add Transaction')
                ->modal('createTransactionModal')
                ->method('createTransaction')
                ->icon('plus')
                ->className('btn btn-success'),

            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->post()
                ->noAjax()
                ->className('btn btn-primary'),
        ];

        if ($this->user->exists) {
            $buttons[] = Button::make('Delete Consumer')
                ->icon('trash')
                ->confirm('Are you sure you want to delete this consumer? This action cannot be undone.')
                ->method('deleteConsumer')
                ->parameters(['id' => $this->user->id])
                ->className('btn btn-danger');
        }

        return $buttons;
    }

    public function layout(): iterable
    {
        return [
            // Main information
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
            ])->title('Main Information'),

            // Clients table
            Layout::table('clients', [
                TD::make('id', 'ID')->sort(),
                TD::make('name', 'Username')->sort(),
                TD::make('password', 'Password'),
                TD::make('server_name', 'Server')->sort(),
                TD::make('is_active', 'Status')
                    ->sort()
                    ->render(fn(Client $client) => $client->is_active
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-danger">Inactive</span>'),
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

            // Transaction modal
            Layout::modal('createTransactionModal', [
                Layout::rows([
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

            // Referrals table
            Layout::table('referrals', [
                TD::make('id', 'ID')->sort(),
                TD::make('name', 'Name')->sort(),
                TD::make('email', 'Email')->sort(),
                TD::make('created_at', 'Registered')
                    ->render(fn(User $referral) => $referral->created_at->format('d.m.Y H:i')),
                TD::make('actions', 'Actions')
                    ->align(TD::ALIGN_CENTER)
                    ->render(function (User $referral) {
                        return Button::make('Detach')
                            ->icon('trash')
                            ->confirm('Detach this referral?')
                            ->method('detachReferral')
                            ->parameters(['referral_id' => $referral->id])
                            ->className('btn btn-danger btn-sm');
                    }),
            ])->title('Referrals of this consumer'),

            // Button to open attach referral modal (standard styling)
            Layout::rows([
                ModalToggle::make('Attach Referral')
                    ->modal('attachReferralModal')
                    ->method('attachReferral')
                    ->icon('plus'),
            ]),

            // Attach referral modal
            Layout::modal('attachReferralModal', [
                Layout::rows([
                    // Hidden field with current user ID
                    Input::make('user_id')
                        ->type('hidden')
                        ->value($this->user->id),

                    Select::make('new_referral_id')
                        ->title('Select consumer')
                        ->options($this->availableReferrals)
                        ->empty('No consumers available')
                        ->required(),
                ]),
            ])->title('Attach Referral')
                ->applyButton('Attach')
                ->closeButton('Cancel'),
        ];
    }

    /**
     * Attach an existing consumer as a referral
     */
    public function attachReferral(Request $request)
    {
        $userId = $request->input('user_id') ?? $request->route('id');
        $user = User::findOrFail($userId);

        $request->validate([
            'new_referral_id' => 'required|exists:users,id',
        ]);

        $newReferral = User::findOrFail($request->input('new_referral_id'));

        if ($newReferral->referrer_id !== null) {
            Toast::error('This user is already attached to another referrer');
            return back();
        }

        if ($newReferral->id === $user->id) {
            Toast::error('You cannot attach yourself');
            return back();
        }

        $newReferral->referrer_id = $user->id;
        $newReferral->save();

        Toast::success('Referral attached successfully');
        return back();
    }

    /**
     * Detach a referral (clear referrer_id)
     */
    public function detachReferral(Request $request)
    {
        $user = User::findOrFail($request->route('id'));

        $referralId = $request->input('referral_id');
        $referral = User::findOrFail($referralId);

        if ($referral->referrer_id !== $user->id) {
            Toast::error('This user is not a referral of this consumer');
            return back();
        }

        $referral->referrer_id = null;
        $referral->save();

        Toast::info('Referral detached successfully');
        return back();
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

    public function createTransaction(Request $request)
    {
        $data = $request->validate([
            'transaction.user_id' => 'required|exists:users,id',
            'transaction.type'    => 'required|in:deposit,withdraw',
            'transaction.amount'  => 'required|numeric|min:0',
            'transaction.comment' => 'nullable|string',
        ])['transaction'];

        Transaction::createTransaction(
            $data['user_id'],
            $data['type'],
            $data['amount'],
            null,
            null,
            $data['comment'] ?? null,
            true
        );

        Toast::success('Transaction created successfully');
        return redirect()->route('platform.consumers.edit', $data['user_id']);
    }

    public function deleteConsumer(Request $request)
    {
        $userId = $request->input('id');
        $user = User::findOrFail($userId);

        $clientsCount = $user->clients()->count();
        if ($clientsCount > 0) {
            Toast::error("Cannot delete consumer: they still have {$clientsCount} client(s). Please delete all clients first.");
            return redirect()->route('platform.consumers.edit', $userId);
        }

        $user->delete();

        Toast::success('Consumer deleted successfully');
        return redirect()->route('platform.consumers.list');
    }
}