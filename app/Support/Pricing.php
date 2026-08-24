<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;

/**
 * Единый центр расчёта цен и сроков.
 *
 * Вся тарификация определяется в config/vpn.php:
 *   - default_price — базовая цена за клиента в сутки (единственный источник правды)
 *   - servers.<name>.price — переопределение цены для конкретного сервера,
 *     указывается ТОЛЬКО если она отличается от default_price
 *   - entry_bonus — вступительный бонус
 *
 * Раньше цена читалась через config() в шести местах с тремя разными
 * фолбэками (12, 1, 360), а дни считались то через ceil, то через floor —
 * бот и крон показывали юзеру разные цифры. Теперь всё здесь.
 */
class Pricing
{
    /** Аварийный фолбэк на случай пустого конфига — чтобы не делить на ноль. */
    private const FALLBACK_PRICE = 10.0;

    /**
     * Базовая цена за один клиент в сутки.
     */
    public static function default(): float
    {
        return (float) (config('vpn.default_price') ?: self::FALLBACK_PRICE);
    }

    /**
     * Цена за клиента на конкретном сервере.
     * Если у сервера нет своей цены — берётся базовая.
     */
    public static function forServer(?string $serverName): float
    {
        if ($serverName === null) {
            return self::default();
        }

        // Через индекс массива, а НЕ config("vpn.servers.{$serverName}.price"):
        // имена серверов содержат точки (x.xab.su), а config() трактует точку
        // как разделитель уровней и такой ключ просто не найдёт.
        $servers = config('vpn.servers', []);
        $price = $servers[$serverName]['price'] ?? null;

        return $price !== null ? (float) $price : self::default();
    }

    /**
     * Вступительный бонус новому пользователю.
     */
    public static function entryBonus(): float
    {
        return (float) config('vpn.entry_bonus', 0);
    }

    /**
     * Сколько дней бесплатного теста даёт вступительный бонус.
     * Используется в тексте приветствия ({days}).
     */
    public static function freeDays(): int
    {
        return (int) floor(self::entryBonus() / self::default());
    }

    /**
     * Суточный расход конкретного пользователя — сумма цен всех его
     * активных клиентов. Если активных нет, возвращает базовую цену,
     * чтобы «дней осталось» считалось хоть по чему-то.
     */
    public static function dailyCostForUser(User|int $user): float
    {
        $userId = $user instanceof User ? $user->id : $user;

        $cost = Client::where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->sum(fn(Client $client) => self::forServer($client->server_name));

        return $cost > 0 ? (float) $cost : self::default();
    }

    /**
     * Сколько полных суток проживёт баланс при заданном суточном расходе.
     * Всегда floor: обещать лишний день, которого не хватит, нельзя.
     */
    public static function daysLeft(float $balance, ?float $dailyCost = null): int
    {
        if ($balance <= 0) {
            return 0;
        }

        $dailyCost = $dailyCost ?: self::default();

        if ($dailyCost <= 0) {
            return 0;
        }

        return (int) floor($balance / $dailyCost);
    }
}
