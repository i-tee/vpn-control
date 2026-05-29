<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Apply / remove the SOCKS5 (or HTTP) proxy used to reach api.telegram.org.
 *
 * Configuration lives in config/telegram.php and is driven by the
 * TELEGRAM_PROXY_* env vars. Keeping this in one place means TelegraphRetry
 * (and anyone else) can flip proxy on and off per-attempt without each
 * caller knowing how globalOptions actually works.
 *
 * Note: applyGlobal()/clearGlobal() mutate per-process state on the Http
 * factory. That's fine because PHP-FPM gives every webhook its own process
 * and every artisan command runs in its own process — there's no race here.
 */
class TelegramProxy
{
    public static function isEnabled(): bool
    {
        return (bool) config('telegram.proxy_enabled') && config('telegram.proxy_url');
    }

    public static function fallbackEnabled(): bool
    {
        return (bool) config('telegram.proxy_fallback_direct');
    }

    public static function applyGlobal(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        Http::globalOptions([
            'proxy' => [
                // Only HTTPS — our VPN API uses plain HTTP and shouldn't be proxied.
                'https' => config('telegram.proxy_url'),
                'no'    => config('telegram.proxy_no_hosts', []),
            ],
        ]);
    }

    public static function clearGlobal(): void
    {
        Http::globalOptions([]);
    }
}
