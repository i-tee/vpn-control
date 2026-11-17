<?php

namespace App\Support;

use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Log;

/**
 * Сообщение админу в Telegram. Best effort: ошибка логируется и не бросается.
 */
class AdminNotifier
{
    public static function telegram(string $text, string $context = 'AdminNotifier'): bool
    {
        $botId = config('gatekeeper.notify_bot_id');
        $adminChatId = config('gatekeeper.admin_chat_id');

        if (!$botId || !$adminChatId) {
            Log::warning("[{$context}] TELEGRAPH_BOT_NOTIFY_ID или ADMIN_CHAT_ID не заданы");
            return false;
        }

        $chat = TelegraphChat::where('chat_id', $adminChatId)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) {
            Log::warning("[{$context}] Чат администратора не найден", ['chat_id' => $adminChatId, 'bot_id' => $botId]);
            return false;
        }

        try {
            $response = TelegraphRetry::attempt(fn() => $chat->message($text)->send(), 5, 500, $context);
            return $response->json('ok') === true;
        } catch (\Throwable $e) {
            Log::error("[{$context}] Не удалось отправить сообщение админу", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
