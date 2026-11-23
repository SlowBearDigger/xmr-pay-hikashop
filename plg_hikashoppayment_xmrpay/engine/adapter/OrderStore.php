<?php
// Define payment-order storage operations.

namespace XmrPay\Adapter;

interface OrderStore
{

    public function loadPending(): iterable;

    public function saveProgress(int $orderId, array $patch): void;

    public function isSettled(string $txid): bool;

    /** Recheck invoice eligibility atomically before changing payment state or claims. */
    public function markPaid(int $orderId, string $txid, array $verdict): void;
}
