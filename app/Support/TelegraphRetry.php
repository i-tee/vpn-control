<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wrap a Telegraph send() (or any throwing closure) in retries.
 *
 * Background: outbound HTTPS to api.telegram.org from the prod server is
 * filtered intermittently. Originally we saw a ~50% direct success rate
 * (✓✗✓✗✓ pattern in curl) so simple retries were enough. After we added
 * a SOCKS5 proxy via TelegramProxy / config('telegram.*'), this helper now
 * does proxy-first with a direct-connection fallback for the last attempts:
 *
 *   - proxy disabled        → all attempts go direct (original behaviour)
 *   - proxy enabled, no fb  → all attempts go through the proxy
 *   - proxy enabled, fb on  → first attempts via proxy, last 2 attempts
 *                             direct, so we survive even a dead proxy
 *
 * Re-throws the last exception when every attempt fails — caller decides
 * whether to swallow it or let it bubble.
 *
 * Note: switching off the proxy for one attempt mutates the global Http
 * client options for the duration of that attempt and restores them after.
 * Safe because each PHP-FPM / artisan process serves a single request.
 */
class TelegraphRetry
{
    public static function attempt(
        callable $fn,
        int $attempts = 5,
        int $delayMs = 500,
        ?string $context = null
    ): mixed {
        $lastException = null;

        $proxyEnabled    = TelegramProxy::isEnabled();
        $fallbackEnabled = $proxyEnabled && TelegramProxy::fallbackEnabled();

        // If fallback is on, the last two attempts go direct (or the only
        // attempt, if there's just one). Otherwise no attempt goes direct.
        $directFromAttempt = $fallbackEnabled
            ? max(1, $attempts - 1)
            : ($attempts + 1);

        for ($i = 1; $i <= $attempts; $i++) {
            $viaDirect = $proxyEnabled && $i >= $directFromAttempt;
            $route     = $proxyEnabled ? ($viaDirect ? 'direct' : 'proxy') : 'direct';

            if ($viaDirect) {
                TelegramProxy::clearGlobal();
            }

            try {
                $result = $fn();

                if ($viaDirect) {
                    TelegramProxy::applyGlobal();
                }

                if ($i > 1) {
                    Log::info('[TelegraphRetry] Succeeded after retry', [
                        'context' => $context,
                        'attempt' => $i,
                        'of'      => $attempts,
                        'via'     => $route,
                    ]);
                }

                return $result;
            } catch (Throwable $e) {
                $lastException = $e;

                if ($viaDirect) {
                    TelegramProxy::applyGlobal();
                }

                Log::warning('[TelegraphRetry] Attempt failed', [
                    'context' => $context,
                    'attempt' => $i,
                    'of'      => $attempts,
                    'via'     => $route,
                    'error'   => $e->getMessage(),
                ]);

                if ($i < $attempts) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastException;
    }
}
