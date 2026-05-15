<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wrap a Telegraph send() (or any throwing closure) in retries.
 *
 * Background: outbound to api.telegram.org from this server is filtered
 * intermittently — roughly every other attempt hits a 5-second TCP-connect
 * timeout, while the next one succeeds in ~200ms. Curl tests on prod showed
 * ✓✗✓✗✓ pattern. A single attempt therefore has ~50% chance of failing;
 * 5 attempts with a short delay between them pushes success rate above 97%.
 *
 * Usage:
 *     TelegraphRetry::attempt(
 *         fn() => $chat->message($text)->send(),
 *         5,
 *         500,
 *         'BalanceNotify user_id=' . $user->id
 *     );
 *
 * Re-throws the last exception when all attempts fail — caller decides
 * whether to swallow it or let it bubble.
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

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $result = $fn();

                if ($i > 1) {
                    Log::info('[TelegraphRetry] Succeeded after retry', [
                        'context' => $context,
                        'attempt' => $i,
                        'of' => $attempts,
                    ]);
                }

                return $result;
            } catch (Throwable $e) {
                $lastException = $e;
                Log::warning('[TelegraphRetry] Attempt failed', [
                    'context' => $context,
                    'attempt' => $i,
                    'of' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($i < $attempts) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastException;
    }
}
