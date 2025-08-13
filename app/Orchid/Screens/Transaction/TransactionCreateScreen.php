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

class TransactionCreateScreen extends Screen
{
    /**
     * @var Transaction|null
     */
    public ?Transaction $transaction = null;

    /**
     * Query data.
     *
     * @return array
     */
    public function query(): iterable
    {
        // Создаем новый экземпляр модели для формы
        $this->transaction = new Transaction();
        
        return [
            'users' => User::all()->pluck('name', 'id')->toArray(),
        ];
    }

    /**
     * Display header name.
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return 'Create Transaction';
    }

    /**
     * Display header description.
     *
     * @return string|null
     */
    public function description(): ?string
    {
        return 'Create a new transaction in the system';
    }

    /**
     * Button commands.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('Save')
                ->icon('check')
                ->method('save')
                ->post(),
                
            Button::make('Back')
                ->icon('left')
                ->route('platform.transactions.list'),
        ];
    }

    /**
     * Views.
     *
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::rows([
                Relation::make('user_id')
                    ->title('User')
                    ->fromModel(User::class, 'name', 'id')
                    ->required(),
                    
                Select::make('type')
                    ->options([
                        'deposit' => 'Deposit',
                        'withdraw' => 'Withdraw'
                    ])
                    ->title('Type')
                    ->required(),
                    
                Input::make('amount')
                    ->type('number')
                    ->step('0.01')
                    ->title('Amount')
                    ->required(),
                    
                Input::make('subject_type')
                    ->title('Subject Type'),
                    
                Input::make('subject_id')
                    ->type('number')
                    ->title('Subject ID'),
                    
                TextArea::make('comment')
                    ->title('Comment')
                    ->rows(3),
                    
                CheckBox::make('is_active')
                    ->title('Active')
                    ->value(true)
                    ->sendTrueOrFalse(),
            ]),
        ];
    }

    /**
     * Save transaction
     *
     * @param Request $request
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => 'required|in:deposit,withdraw',
            'amount' => 'required|numeric|min:0',
            'subject_type' => 'nullable|string',
            'subject_id' => 'nullable|integer',
            'comment' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        Transaction::createTransaction(
            $data['user_id'],
            $data['type'],
            $data['amount'],
            $data['subject_type'] ?? null,
            $data['subject_id'] ?? null,
            $data['comment'] ?? null,
            $data['is_active'] ?? true
        );

        Toast::success('Transaction created successfully');
        return redirect()->route('platform.transactions.list');
    }
}