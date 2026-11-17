<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Notifications\NewDeposit;
use App\Services\AccessService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Пополнение или правка транзакции сразу включает или блокирует каналы юзера.
 *
 * Срабатывает только после коммита: если транзакция создаётся внутри
 * DB::transaction, запросы к VPN-серверу не держат базу и не уходят
 * при откате.
 */
class TransactionObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private AccessService $access)
    {
    }

    public function created(Transaction $transaction): void
    {
        // Списания создаёт крон, он сам вызывает AccessService
        if ($transaction->type !== 'deposit') {
            return;
        }

        if ($adminEmail = config('gatekeeper.admin_email')) {
            try {
                Notification::route('mail', $adminEmail)->notify(new NewDeposit($transaction));
            } catch (\Throwable $e) {
                Log::error('[TransactionObserver] Не удалось отправить письмо NewDeposit', ['error' => $e->getMessage()]);
            }
        }

        $this->access->sync($transaction->user_id);
    }

    public function updated(Transaction $transaction): void
    {
        // wasChanged, а не isDirty: после коммита модель уже сохранена
        if ($transaction->wasChanged('is_active') || $transaction->wasChanged('amount') || $transaction->wasChanged('type')) {
            $this->access->sync($transaction->user_id);
        }
    }
}
