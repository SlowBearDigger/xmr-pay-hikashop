<?php
namespace XmrPay\Adapter;

/** Optional settlement capability for stores that atomically claim individual outputs. */
interface OutputOrderStore extends OrderStore
{
    /**
     * Check both the transaction/output location and the one-time output key.
     * Receipt fields: txid, output_index, out_key, and amount_atomic.
     */
    public function isOutputSettled(array $receipt): bool;

    /**
     * Claim every receipt and persist the paid order and fulfillment intent atomically.
     * Recheck conflicts during the write; the preflight check cannot prevent races.
     * Re-read invoice closure under the same transaction before claiming any output.
     */
    public function markPaidFromOutputs(int $orderId, array $receipts, array $verdict): void;
}
