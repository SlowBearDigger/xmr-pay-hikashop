<?php

/**
 * The HikaShop side of the storage contract the engine's Settler drives. Pending orders live in
 * #__hikashop_order; our per-order scan state (the receiving subaddress, the locked XMR amount, the
 * birthday height, the scan checkpoint and the accumulated matches) lives in that order's serialized
 * order_payment_params under xmrpay_* keys, so no schema change is needed for it. Txid dedup is the
 * one thing HikaShop has no primitive for, so it gets its own tiny table with a UNIQUE index.
 */

defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/engine/load.php';

use XmrPay\Adapter\OrderStore;

class HikashopOrderStore implements OrderStore
{
    private $methodName;
    private $pendingStatus;
    private $paidStatus;
    private $notifyEmail;
    private $paymentId;

    public function __construct(array $opts = array())
    {
        $this->methodName    = isset($opts['method_name']) ? $opts['method_name'] : 'xmrpay';
        $this->pendingStatus = isset($opts['pending_status']) ? $opts['pending_status'] : 'created';
        $this->paidStatus    = isset($opts['paid_status']) ? $opts['paid_status'] : 'confirmed';
        $this->notifyEmail   = !empty($opts['notify_email']);
        // when set, only orders paid through this specific method instance are swept — so two xmrpay
        // methods with different wallets never settle against each other's config.
        $this->paymentId     = isset($opts['payment_id']) ? (int) $opts['payment_id'] : 0;
    }

    public function loadPending(): iterable
    {
        $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName('#__hikashop_order'))
            ->where($db->quoteName('order_payment_method') . ' = ' . $db->quote($this->methodName))
            ->where($db->quoteName('order_status') . ' = ' . $db->quote($this->pendingStatus));
        if ($this->paymentId > 0) {
            $query->where($db->quoteName('order_payment_id') . ' = ' . (int) $this->paymentId);
        }
        $db->setQuery($query);
        $ids = (array) $db->loadColumn();

        $out = array();
        foreach ($ids as $id) {
            $row = $this->loadOne((int) $id);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** The contract row for one order, or null if it is gone. Reused by the checkout poll. */
    public function loadOne(int $orderId)
    {
        $order = hikashop_get('class.order')->get($orderId);
        if (!$order) {
            return null;
        }
        // HikaShop hands order_payment_params back as a stdClass or an array depending on how it was
        // last written; read it uniformly as an array.
        $p = (array) (isset($order->order_payment_params) ? $order->order_payment_params : array());

        $matches = array();
        if (!empty($p['xmrpay_matches'])) {
            $decoded = json_decode($p['xmrpay_matches'], true);
            $matches = is_array($decoded) ? $decoded : array();
        }

        return array(
            'id'              => $orderId,
            'birthday_height' => (int) (isset($p['xmrpay_birthday_height']) ? $p['xmrpay_birthday_height'] : 0),
            'scanned_to'      => (int) (isset($p['xmrpay_scanned_to']) ? $p['xmrpay_scanned_to'] : 0),
            'matches'         => $matches,
            'xmr_amount'      => (string) (isset($p['xmrpay_amount']) ? $p['xmrpay_amount'] : '0'),
            'status'          => 'pending',
            // extra (not part of the OrderStore contract; the Settler ignores it): funds seen so far,
            // so the checkout poll can report partial-payment progress to the buyer.
            'received_pico'   => (string) (isset($p['xmrpay_received_pico']) ? $p['xmrpay_received_pico'] : '0'),
        );
    }

    public function saveProgress(int $orderId, array $patch): void
    {
        $orderClass = hikashop_get('class.order');
        $order      = $orderClass->get($orderId);
        if (!$order) {
            return;
        }
        // normalise to an object regardless of how HikaShop returned it, then assign our keys
        $raw = isset($order->order_payment_params) ? $order->order_payment_params : null;
        $p   = is_object($raw) ? $raw : (object) ((array) $raw);

        // contract keys
        if (array_key_exists('birthday_height', $patch)) $p->xmrpay_birthday_height = (int) $patch['birthday_height'];
        if (array_key_exists('scanned_to', $patch))      $p->xmrpay_scanned_to      = (int) $patch['scanned_to'];
        if (array_key_exists('matches', $patch))         $p->xmrpay_matches         = json_encode($patch['matches']);
        if (array_key_exists('received_pico', $patch))   $p->xmrpay_received_pico   = (string) $patch['received_pico'];
        // checkout lock-in (HikaShop-specific extras)
        if (array_key_exists('xmr_amount', $patch))      $p->xmrpay_amount          = (string) $patch['xmr_amount'];
        if (array_key_exists('subaddress', $patch))      $p->xmrpay_subaddress      = (string) $patch['subaddress'];
        if (array_key_exists('rate', $patch) && $patch['rate'] !== null) $p->xmrpay_rate = (string) $patch['rate'];

        $update                       = new stdClass();
        $update->order_id             = $orderId;
        $update->order_payment_params = $p;   // class.order::save serializes it
        $orderClass->save($update);
    }

    public function isSettled(string $txid): bool
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__xmrpay_txids') . ' WHERE ' . $db->quoteName('txid') . ' = ' . $db->quote($txid));
            return (bool) $db->loadResult();
        } catch (\Throwable $e) {
            // most likely the dedup table is missing (a partial install) — surface it instead of
            // silently failing every settlement forever, which would look like "nothing ever pays".
            error_log('[xmr-pay] dedup table query failed (is #__xmrpay_txids installed?): ' . $e->getMessage());
            return false;
        }
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        // claim the txid first: the UNIQUE index is the real guard against two overlapping runs both
        // crediting. if the insert loses the race, another run already settled it — stop here.
        try {
            $claim             = new stdClass();
            $claim->txid       = $txid;
            $claim->order_id   = $orderId;
            $claim->settled_at = time();
            $db->insertObject('#__xmrpay_txids', $claim);
        } catch (\Throwable $e) {
            // a duplicate-key collision means a concurrent run already claimed this txid — stop quietly.
            // any OTHER error (e.g. the table is missing) must surface, not be mistaken for "settled".
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return;
            }
            error_log('[xmr-pay] could not claim txid (is #__xmrpay_txids installed?): ' . $e->getMessage());
            throw $e;
        }

        $history = array(
            'notified' => $this->notifyEmail ? 1 : 0,
            'type'     => 'payment',
            'data'     => 'XMR txid: ' . $txid,
        );
        if (!empty($verdict['received_pico'])) {
            $history['amount'] = $verdict['received_pico'];
        }

        // if the status change fails AFTER the txid was claimed, release the claim so the next run
        // retries — otherwise the order is stuck forever (txid consumed, status never promoted).
        try {
            $oid = $orderId;   // modifyOrder takes the id by reference
            // $history['notified'] drives the CUSTOMER "order status changed" email; passing false (not
            // null) as $email lets HikaShop also send the store's own payment-notification email when the
            // merchant has configured a payment_notification_email address (null would suppress it).
            hikashop_get('class.order')->modifyOrder($oid, $this->paidStatus, $this->methodName, $history, false, array('xmrpay_txid' => $txid));
        } catch (\Throwable $e) {
            try {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__xmrpay_txids') . ' WHERE ' . $db->quoteName('txid') . ' = ' . $db->quote($txid));
                $db->execute();
            } catch (\Throwable $e2) {
            }
            throw $e;
        }
    }
}
