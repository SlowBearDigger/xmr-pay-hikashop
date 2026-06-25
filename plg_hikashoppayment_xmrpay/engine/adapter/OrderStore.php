<?php

namespace XmrPay\Adapter;

/**
 * Storage contract a cart adapter implements so {@see Settler} can drive settlement without knowing
 * the platform. The whole "scan pending orders -> mark paid" loop lives in the engine + Settler;
 * the cart only has to read and write its own orders through these four methods.
 *
 * A pending order row is an associative array the store fills with:
 *   id              int     the order id (also the subaddress index source)
 *   birthday_height int     chain height when the order was created (0 if the node was down then)
 *   scanned_to      int     last height scanned for this order (0 if never scanned)
 *   matches         array   accumulated engine match rows persisted across runs
 *   xmr_amount      string  the locked XMR total the buyer owes
 *   status          string  'pending' while open
 *
 * xmr_amount is the amount the adapter locked onto the order at checkout — read it back from where
 * it was stored, never re-derive it from a live rate, or a rate move would change what the buyer
 * owes mid-order. None of the carts (HikaShop, VirtueMart, J2Store) dedupes external txids for you,
 * so isSettled / markPaid must be backed by a DB-level UNIQUE constraint on the txid, not the PHP
 * check alone: two overlapping settlement runs can both pass isSettled, and only the unique index
 * stops a double credit.
 */
interface OrderStore
{
    /** All orders still awaiting payment, each shaped as documented above. May be a lazy iterator. */
    public function loadPending(): iterable;

    /**
     * Persist progress for one order. $patch is a subset of:
     *   birthday_height int, scanned_to int, matches array, received_pico string
     * The store maps these onto its own columns / json however it likes.
     */
    public function saveProgress(int $orderId, array $patch): void;

    /** True if this txid was already credited, so a payment is never applied twice (burning-bug + reruns). */
    public function isSettled(string $txid): bool;

    /** Mark the order paid and release the goods. $verdict is the engine's summarize result. */
    public function markPaid(int $orderId, string $txid, array $verdict): void;
}
