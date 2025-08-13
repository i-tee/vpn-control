<?php

namespace App\Orchid\Screens\Transaction;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Relation;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;

class TransactionEditScreen extends Screen
{
    /**
     * @var Transaction|null
     */
    public ?Transaction $transaction = null;

    public function query(Transaction $transaction): iterable
    {
        // Если транзакция не существует (новая), создаём пустой объект
        $this->transaction = $transaction->exists ? $transaction : new Transaction();
        return [
            'transaction' => $this->transaction,
            'users' => User::all()->pluck('name', 'id')->toArray(),
        ];
    }

    public function name(): ?string
    {
        return $this->transaction->exists ? 'Edit Transaction' : 'Create Transaction';
    }

    public function description(): ?string
    {
        return 'Manage transaction in the VPN system';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Save')
                ->icon('check')
                ->method('save'),
            Button::make('Delete')
                ->icon('trash')
                ->method('remove')
                ->canSee($this->transaction->exists),
            Button::make('Back')
                ->icon('left')
                ->route('platform.transactions.list'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Relation::make('transaction.user_id')
                    ->title('User')
                    ->fromModel(User::class, 'name', 'id')
                    ->required(),
                Select::make('transaction.type')
                    ->options(['deposit' => 'Deposit', 'withdraw' => 'Withdraw'])
                    ->title('Type')
                    ->required(),
                Input::make('transaction.amount')
                    ->type('number')
                    ->title('Amount')
                    ->required(),
                Input::make('transaction.subject_type')
                    ->title('Subject Type')
                    ->placeholder('e.g., TopUp, VpnService'),
                Input::make('transaction.subject_id')
                    ->type('number')
                    ->title('Subject ID'),
                TextArea::make('transaction.comment')
                    ->title('Comment')
                    ->rows(3),
                CheckBox::make('transaction.is_active')
                    ->title('Active')
                    ->sendTrueOrFalse(),
            ]),
        ];
    }

    public function save(Request $request, Transaction $transaction)
    {
        // Валидация данных
        $data = $request->validate([
            'transaction.user_id' => 'required|exists:users,id',
            'transaction.type' => 'required|in:deposit,withdraw',
            'transaction.amount' => 'required|numeric|min:0',
            'transaction.subject_type' => 'nullable|string',
            'transaction.subject_id' => 'nullable|integer',
            'transaction.comment' => 'nullable|string',
            'transaction.is_active' => 'boolean',
        ])['transaction'];

        if ($transaction->exists) {
            // Обновление существующей транзакции
            $transaction->updateTransaction(
                $data['type'],
                $data['amount'],
                $data['subject_type'],
                $data['subject_id'],
                $data['comment'],
                $data['is_active']
            );
            Toast::info('Transaction updated successfully');
        } else {
            // Создание новой транзакции
            Transaction::createTransaction(
                $data['user_id'],
                $data['type'],
                $data['amount'],
                $data['subject_type'],
                $data['subject_id'],
                $data['comment'],
                $data['is_active'] ?? true
            );
            Toast::success('Transaction created successfully');
        }

        return redirect()->route('platform.transactions.list');
    }

    public function remove(Transaction $transaction)
    {
        // Удаление транзакции
        $transaction->delete();
        Toast::success('Transaction deleted');
        return redirect()->route('platform.transactions.list');
    }
}