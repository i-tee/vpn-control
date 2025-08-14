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

    public function query($id): iterable
    {
        $this->transaction = Transaction::findOrFail($id);
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
        return 'Manage transaction in the system';
    }

    public function commandBar(): iterable
    {
        return [
            // КРИТИЧЕСКИ ВАЖНО: Используем правильную комбинацию методов
            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->post()
                ->noAjax()
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                // Скрытое поле для явного указания ID (необязательно, но полезно)
                Input::make('transaction_id')
                    ->type('hidden')
                    ->value($this->transaction->id)
                    ->title(''),
                    
                Relation::make('transaction.user_id')
                    ->title('User')
                    ->fromModel(User::class, 'name', 'id')
                    ->value($this->transaction->user_id)
                    ->required(),
                    
                Select::make('transaction.type')
                    ->options([
                        'deposit' => 'Deposit', 
                        'withdraw' => 'Withdraw'
                    ])
                    ->title('Type')
                    ->value($this->transaction->type)
                    ->required(),
                    
                Input::make('transaction.amount')
                    ->type('number')
                    ->step('0.01')
                    ->title('Amount')
                    ->value($this->transaction->amount)
                    ->required(),
                    
                Input::make('transaction.subject_type')
                    ->title('Subject Type')
                    ->value($this->transaction->subject_type),
                    
                Input::make('transaction.subject_id')
                    ->type('number')
                    ->title('Subject ID')
                    ->value($this->transaction->subject_id),
                    
                TextArea::make('transaction.comment')
                    ->title('Comment')
                    ->rows(3)
                    ->value($this->transaction->comment),
                    
                CheckBox::make('transaction.is_active')
                    ->title('Active')
                    ->value($this->transaction->is_active)
                    ->sendTrueOrFalse(),
            ]),
        ];
    }

    public function save(Request $request)
    {
        // Получаем ID транзакции из URL, а не из параметров
        $id = $request->route('id');
        $this->transaction = Transaction::findOrFail($id);
        
        $data = $request->validate([
            'transaction.user_id' => 'required|exists:users,id',
            'transaction.type' => 'required|in:deposit,withdraw',
            'transaction.amount' => 'required|numeric|min:0',
            'transaction.subject_type' => 'nullable|string',
            'transaction.subject_id' => 'nullable|integer',
            'transaction.comment' => 'nullable|string',
            'transaction.is_active' => 'boolean',
        ])['transaction'];

        $this->transaction->updateTransaction(
            $data['type'],
            $data['amount'],
            $data['subject_type'],
            $data['subject_id'],
            $data['comment'],
            $data['is_active']
        );

        Toast::info('Transaction updated successfully');
        return redirect()->route('platform.transactions.list');
    }
}