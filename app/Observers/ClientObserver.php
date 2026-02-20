<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\Button;
use Illuminate\Support\Facades\Log;

class ClientObserver
{
    /**
     * Handle the Client "updated" event.
     */
    public function updated(Client $client): void
    {
        // Проверяем, изменилось ли поле is_active
        if ($client->isDirty('is_active')) {
            $old = $client->getOriginal('is_active'); // старое значение (true/false)
            $new = $client->is_active;                // новое значение

            // Определяем тип события
            if ($new === true && $old === false) {
                $this->notifyUser($client, 'activated');
            } elseif ($new === false && $old === true) {
                $this->notifyUser($client, 'blocked');
            }
        }
    }

    /**
     * Отправить уведомление пользователю.
     */
    protected function notifyUser(Client $client, string $action): void
    {
        // Получаем пользователя
        $user = $client->user;
        if (!$user) {
            Log::warning('[ClientObserver] Пользователь не найден для клиента', ['client_id' => $client->id]);
            return;
        }

        // Определяем текст и кнопки
        if ($action === 'activated') {
            $text = "✅ *VPN-канал активирован*\n\n";
            $text .= "🔑 Канал: `{$client->name}`\n";
            $text .= "Сервер: {$client->server_name}\n";
            $text .= "💰 Текущий баланс: " . $user->balance . " у.е.\n\n";
            $keyboard = Keyboard::make()->row([
                Button::make('Подключить')->action('instructionsGagets')
            ]);
        } else {
            $text = "🚫 *VPN-канал заблокирован*\n\n";
            $keyboard = Keyboard::make()->row([
                Button::make('🆘 Техподдержка')->url(config('bot.link.support'))
            ]);
        }



        // Отправляем через нужного бота
        $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
        if (!$botId) {
            Log::error('[ClientObserver] TELEGRAPH_BOT_NOTIFY_ID не задан');
            return;
        }

        $chat = TelegraphChat::where('chat_id', $user->telegram_id)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) {
            Log::warning('[ClientObserver] Чат не найден', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'bot_id' => $botId
            ]);
            return;
        }

        try {
            $response = $chat->message($text)
                ->keyboard($keyboard)
                ->send();

            if ($response->json('ok') === true) {
                Log::info('[ClientObserver] Уведомление отправлено', [
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'action' => $action,
                    'message_id' => $response->json('result.message_id')
                ]);
            } else {
                Log::error('[ClientObserver] Ошибка отправки', [
                    'response' => $response->json()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[ClientObserver] Исключение при отправке', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
