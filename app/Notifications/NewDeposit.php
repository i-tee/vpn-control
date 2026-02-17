<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeposit extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Transaction $transaction) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->transaction->user;
        return (new MailMessage)
            ->subject('💰 Новое пополнение')
            ->greeting('Здравствуйте!')
            ->line('Пользователь пополнил баланс:')
            ->line('**Пользователь:** ' . ($user->name ?? 'ID: ' . $this->transaction->user_id))
            ->line('**Сумма:** ' . $this->transaction->amount . ' у.е.')
            ->line('**Новый баланс:** ' . ($user->getBalanceAttribute() ?? 'неизвестно') . ' у.е.')
            ->action('Перейти к транзакции', url('/admin/transactions/' . $this->transaction->id));
    }
}