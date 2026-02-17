<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VpnClientCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Client $client) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->client->user;
        $telegramUsername = $user->telegram_username ?? null;
        $telegramLink = $telegramUsername ? "https://t.me/{$telegramUsername}" : null;

        return (new MailMessage)
            ->subject('🔐 Создан новый VPN-клиент')
            ->greeting('Здравствуйте!')
            ->line('Создан новый VPN-доступ:')
            ->line('**Имя клиента:** ' . $this->client->name)
            ->line('**Пользователь:** ' . ($user->name ?? 'ID: ' . $this->client->user_id))
            ->line('**Сервер:** ' . $this->client->server_name)
            ->line('**Статус:** ' . ($this->client->is_active ? 'Активен' : 'Неактивен'))
            ->action('in Telegram', $telegramLink ?? url('/'));
    }
}