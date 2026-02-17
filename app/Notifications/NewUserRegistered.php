<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\BinderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class NewUserRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected User $user) {}

    public function via($notifiable): array
    {
        Log::debug('NOTIKI -- Notification via()');
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        Log::debug('NOTIKI -- Notification function toMail');

        // Получаем баланс через BinderService
        $balance = app(BinderService::class)->getUserBalance($this->user);

        $telegramUsername = $this->user->telegram_username ?? null;
        $telegramLink = $telegramUsername ? "https://t.me/{$telegramUsername}" : null;

        return (new MailMessage)
            ->subject('👤 Новый пользователь в GateKeeper')
            ->greeting('Здравствуйте!')
            ->line('Зарегистрирован новый пользователь:')
            ->line('**Имя:** ' . ($this->user->name ?? 'Не указано'))
            // ->line('**Email:** ' . ($this->user->email ?? 'Не указан'))
            ->line('**Telegram ID:** ' . $this->user->telegram_id)
            ->line('**Telegram profile:** ' . ($telegramUsername ? "@{$telegramUsername}" : 'Не указано'))
            ->line('**Telegram link:** ' . ($telegramLink ?? 'Не указано'))
            ->line('**Баланс:** ' . $balance . ' у.е.')
            ->action('in Telegram', $telegramLink ?? url('/'));
    }
}