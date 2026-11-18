<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Зачисление оплаты из Telegram-инвойса (ЮKassa).
 */
class PaymentService
{
    public const RESULT_CREDITED = 'credited';
    public const RESULT_DUPLICATE = 'duplicate';

    /**
     * Зачислить платёж ровно один раз.
     *
     * Ключ идемпотентности — provider_payment_charge_id: Telegram может прислать
     * один и тот же successful_payment повторно, если вебхук не ответил вовремя.
     * Сумма берётся из total_amount платежа, а не из payload инвойса.
     *
     * @return array{status: string, amount: float, referral_bonus: float, referrer_id: ?int}
     */
    public function credit(User $user, int $totalAmountMinor, string $providerChargeId): array
    {
        $amount = round($totalAmountMinor / 100, 2);
        $result = ['status' => self::RESULT_DUPLICATE, 'amount' => $amount, 'referral_bonus' => 0.0, 'referrer_id' => null];

        if (Transaction::where('external_id', $this->externalId($providerChargeId))->exists()) {
            Log::channel('payments')->warning('[Payment] Повторное уведомление о платеже, пропускаем', ['charge_id' => $providerChargeId]);
            return $result;
        }

        try {
            // 3 попытки на "database is locked" в SQLite; при повторе всё начинается заново
            DB::transaction(function () use ($user, $amount, $providerChargeId, &$result) {
                $result['referral_bonus'] = 0.0;
                $result['referrer_id'] = null;

                Transaction::createTransaction(
                    userId: $user->id,
                    type: 'deposit',
                    amount: $amount,
                    subjectType: 'yookassa',
                    comment: "Оплата через ЮKassa, транзакция: {$providerChargeId}",
                    externalId: $this->externalId($providerChargeId),
                );

                $result['status'] = self::RESULT_CREDITED;

                if (!$user->referrer_id || !User::whereKey($user->referrer_id)->exists()) {
                    return;
                }

                $bonus = round($amount * Pricing::referralPercent() / 100, 2);
                if ($bonus <= 0) {
                    return;
                }

                Transaction::createTransaction(
                    userId: $user->referrer_id,
                    type: 'deposit',
                    amount: $bonus,
                    subjectType: 'referral_bonus',
                    subjectId: $user->id,
                    comment: "Бонус за пополнение приглашённого #{$user->id}",
                );

                $result['referral_bonus'] = $bonus;
                $result['referrer_id'] = $user->referrer_id;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Два одинаковых вебхука пришли одновременно, второй упёрся в unique
            Log::channel('payments')->warning('[Payment] Параллельный дубль платежа, пропускаем', ['charge_id' => $providerChargeId]);
            return ['status' => self::RESULT_DUPLICATE, 'amount' => $amount, 'referral_bonus' => 0.0, 'referrer_id' => null];
        }

        Log::channel('payments')->info('[Payment] Платёж зачислен', [
            'user_id' => $user->id,
            'amount' => $amount,
            'charge_id' => $providerChargeId,
            'referral_bonus' => $result['referral_bonus'],
        ]);

        return $result;
    }

    private function externalId(string $providerChargeId): string
    {
        return 'yookassa:' . $providerChargeId;
    }
}
