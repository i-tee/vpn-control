<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ClientsBlocked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Collection $clients) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {

        Log::debug('NOTIKI -- ClientsBlocked toMail called', ['count' => $this->clients->count()]);

        $mail = (new MailMessage)
            ->subject('⛔ Заблокированы VPN-клиенты')
            ->greeting('Здравствуйте!')
            ->line('Следующие VPN-клиенты были деактивированы из-за отрицательного баланса:');

        foreach ($this->clients as $client) {
            $user = $client->user;
            $mail->line("- **{$client->name}** (пользователь: " . ($user->name ?? $client->user_id) . ")");
        }

        $mail->line('Всего заблокировано: ' . $this->clients->count());

        return $mail;
    }
}