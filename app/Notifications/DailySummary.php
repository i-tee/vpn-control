<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class DailySummary extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $totalConsumers,
        protected int $totalClients,
        protected int $activeClients,
        protected int $inactiveClients,
        protected float $totalCharged,
        protected int $blockedToday
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        Log::debug('[DailySummary] Формирование письма', [
            'totalConsumers' => $this->totalConsumers,
            'totalClients' => $this->totalClients,
            'active' => $this->activeClients,
            'inactive' => $this->inactiveClients,
            'charged' => $this->totalCharged,
            'blocked' => $this->blockedToday
        ]);

        $mail = (new MailMessage)
            ->subject('📊 Ежедневный отчёт VPN')
            ->greeting('Здравствуйте!')
            ->line('Ежедневный отчёт о состоянии системы VPN:')
            ->line("👥 **Всего потребителей:** {$this->totalConsumers}")
            ->line("🔑 **Всего клиентов:** {$this->totalClients}")
            ->line("   ✅ Активных: {$this->activeClients}")
            ->line("   ❌ Неактивных: {$this->inactiveClients}")
            ->line("💰 **Списано сегодня:** {$this->totalCharged} у.е.")
            ->line("🚫 **Заблокировано сегодня:** {$this->blockedToday}");

        if ($this->blockedToday > 0) {
            $mail->line('❗️ Есть заблокированные клиенты (подробности в отдельном письме).');
        }

        return $mail;
    }
}
