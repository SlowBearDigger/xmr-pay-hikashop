<?php
namespace XmrPay\Adapter;

use XmrPay\Util;

// Keep offer closure separate from verified receipts and financial settlement.
class InvoicePolicy
{
    public static function evaluate(array $order, array $payment, string $expectedAtomic, int $now): array
    {
        $state = $order['invoice_status'] ?? 'created';
        $expiresAt = $order['expires_at'] ?? 0;
        $fundedAt = $order['funded_at'] ?? null;
        $fundedAmount = $order['funded_amount_atomic'] ?? null;
        $reason = $order['closed_reason'] ?? null;
        if (!in_array($state, ['created', 'processing', 'expired', 'cancelled', 'review', 'settled', 'invalid'], true)
            || !is_int($expiresAt) || $expiresAt < 0 || ($reason !== null && !is_string($reason))
            || ($fundedAmount !== null && (!is_string($fundedAmount) || !preg_match('/^[0-9]+$/D', $fundedAmount)))
            || ($fundedAt !== null && (!is_int($fundedAt) || $fundedAt < 0 || $fundedAt > $now))) {
            return self::decision('invalid', null, 'invalid-invoice', false);
        }
        if (($order['status'] ?? '') === 'paid' || $state === 'settled') {
            return self::decision('settled', $fundedAt, $reason, false, $fundedAmount);
        }
        $financialState = $order['status'] ?? 'pending';
        if (in_array($financialState, ['cancelled', 'failed', 'refunded'], true)) {
            $state = 'cancelled';
            $reason = $financialState;
        } elseif ($financialState === 'expired') {
            $state = 'expired';
            $reason = 'expired';
        }
        $seen = $payment['seen_pico'] ?? '0';
        // Funding observed for an earlier quote cannot authorize a later, larger quote.
        if ($fundedAmount !== $expectedAtomic) {
            $fundedAt = null;
        }
        $hasFunds = gmp_cmp($seen, '0') > 0;
        $fullySeen = gmp_cmp($seen, $expectedAtomic) >= 0;
        if (!$fullySeen && !empty($order['scan_complete'])) {
            $fundedAt = null;
        }
        if (in_array($state, ['expired', 'cancelled', 'review', 'invalid'], true)) {
            if ($state !== 'invalid' && $hasFunds) {
                $reason = $reason ?? ($state === 'review' ? 'manual-review' : $state);
                $state = 'review';
            }
            return self::decision($state, $fundedAt, $reason, false, $expectedAtomic);
        }
        if ($fullySeen && $fundedAt === null && ($expiresAt === 0 || $now < $expiresAt)) {
            $fundedAt = $now;
        }
        $timelyFunded = $fundedAt !== null && ($expiresAt === 0 || $fundedAt < $expiresAt);
        if ($expiresAt > 0 && $now >= $expiresAt && !$timelyFunded) {
            if (array_key_exists('scan_complete', $order) && $order['scan_complete'] !== true) {
                return self::decision($hasFunds ? 'processing' : 'created', $fundedAt, null, false, $expectedAtomic);
            }
            return self::decision($hasFunds ? 'review' : 'expired', $fundedAt, 'expired', false, $expectedAtomic);
        }
        return self::decision($hasFunds ? 'processing' : 'created', $fundedAt, null, true, $expectedAtomic);
    }

    public static function mergeProgress(array $order, array $patch): array
    {
        if (($order['status'] ?? '') === 'paid') {
            return $order;
        }
        $closed = in_array($order['invoice_status'] ?? '', ['expired', 'cancelled', 'review', 'invalid'], true);
        $reopens = in_array($patch['invoice_status'] ?? '', ['created', 'processing', 'settled'], true);
        if ($closed && $reopens) {
            $patch['invoice_status'] = $order['invoice_status'];
            $patch['closed_reason'] = $order['closed_reason'] ?? $order['invoice_status'];
        }
        return array_merge($order, $patch);
    }

    public static function assertPayable(array $order, array $payment, bool $acceptExpired = false): void
    {
        $expected = Util::xmr_to_pico_exact($order['xmr_amount']);
        if (gmp_cmp($expected, '0') <= 0) {
            throw new \RuntimeException('Invoice amount must be positive');
        }
        $confirmed = $payment['confirmed_pico'] ?? null;
        if (!is_string($confirmed) || !preg_match('/^[0-9]+$/D', $confirmed)
            || ($payment['paid'] ?? false) !== true || gmp_cmp($confirmed, $expected) < 0) {
            throw new \RuntimeException('Confirmed payment does not cover the current invoice amount');
        }
        $decision = self::evaluate($order, $payment, $expected, time());
        if ($acceptExpired) {
            if (($order['status'] ?? '') !== 'pending' || ($order['scan_complete'] ?? false) !== true
                || !in_array($order['invoice_status'] ?? '', ['review', 'expired'], true)
                || ($order['closed_reason'] ?? '') !== 'expired'
                || $decision['patch']['invoice_status'] !== 'review'
                || $decision['patch']['closed_reason'] !== 'expired'
                || !is_int($order['expires_at'] ?? null) || $order['expires_at'] < 1 || $order['expires_at'] > time()) {
                throw new \RuntimeException('Invoice is not eligible for late payment acceptance');
            }
            return;
        }
        if (!$decision['may_settle']) {
            throw new \RuntimeException('Invoice is not eligible for automatic settlement');
        }
    }

    private static function decision(string $state, ?int $fundedAt, ?string $reason, bool $maySettle, ?string $fundedAmount = null): array
    {
        return [
            'patch' => [
                'invoice_status' => $state,
                'funded_at' => $fundedAt,
                'funded_amount_atomic' => $fundedAt === null ? null : $fundedAmount,
                'closed_reason' => $reason,
            ],
            'may_settle' => $maySettle,
        ];
    }
}
