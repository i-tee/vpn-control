<?php

namespace App\Orchid\Screens\Transaction;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Toast;

class TransactionListScreen extends Screen
{
    public function query(): iterable
    {
        $query = Transaction::query();
        // Фильтрация по ID пользователя, если передано
        if (request()->has('user_id')) {
            $query->where('user_id', request('user_id'));
        }
        // Фильтрация по дате, если передано
        if (request()->has('created_at')) {
            $query->whereDate('created_at', request('created_at'));
        }
        return [
            'transactions' => $query->orderBy('created_at', 'desc')->paginate(20),
        ];
    }

    public function name(): ?string
    {
        return 'Transactions';
    }

    public function description(): ?string
    {
        return 'List of all transactions in the VPN system';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Create new')
                ->icon('plus')
                ->route('platform.transactions.create'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::table('transactions', [
                TD::make('id', 'ID')
                    ->sort()
                    ->render(function (Transaction $transaction) {
                        return $transaction->id; // Явно возвращаем ID как значение
                    }),
                TD::make('user_id', 'User')
                    ->sort()
                    ->render(function (Transaction $transaction) {
                        return $transaction->user->name ?? 'N/A'; // Проверяем связь
                    }),
                TD::make('created_at', 'Date')
                    ->sort()
                    ->filter(TD::FILTER_DATE_RANGE)
                    ->render(function (Transaction $transaction) {
                        return $transaction->created_at; // Явно возвращаем дату
                    }),
                TD::make('Actions')
                    ->align(TD::ALIGN_CENTER)
                    ->render(function (Transaction $transaction) {
                        return Button::make('Edit')
                            ->icon('pencil')
                            ->route('platform.transactions.edit', $transaction) .
                            Button::make('Cancel')
                            ->icon('ban')
                            ->method('cancel')
                            ->parameters(['id' => $transaction->id])
                            ->canSee($transaction->is_active);
                    }),
            ]),
        ];
    }

    public function cancel(Request $request)
    {
        // Поиск и отмена транзакции
        $transaction = Transaction::findOrFail($request->get('id'));
        $transaction->cancel('Cancel from admin panel');
        Toast::success('Transaction canceled');
    }
}