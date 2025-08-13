<?php

namespace App\Orchid\Screens\Transaction;

use App\Models\Transaction;
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
        $query = Transaction::query()->with('user');
        
        // Фильтрация по ID пользователя
        if (request()->filled('user_id')) {
            $query->where('user_id', request('user_id'));
        }
        
        // Фильтрация по дате
        if (request()->filled('created_at')) {
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
        return 'List of all transactions in the system';
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
                    ->render(fn ($transaction) => 
                        \Orchid\Screen\Actions\Link::make($transaction->id)
                            ->route('platform.transactions.edit', $transaction->id)
                    ),
                
                TD::make('user.name', 'User')
                    ->sort()
                    ->render(fn ($transaction) => $transaction->user?->name ?? 'N/A'),
                
                TD::make('type', 'Type')
                    ->sort(),
                
                TD::make('amount', 'Amount')
                    ->sort()
                    ->render(fn ($transaction) => number_format($transaction->amount, 2)),
                
                TD::make('created_at', 'Date')
                    ->sort()
                    ->filter(TD::FILTER_DATE_RANGE)
                    ->render(fn ($transaction) => $transaction->created_at->format('Y-m-d H:i')),
                
                TD::make('is_active', 'Status')
                    ->sort()
                    ->render(fn ($transaction) => $transaction->is_active ? 'Active' : 'Cancelled'),
            ]),
        ];
    }
}