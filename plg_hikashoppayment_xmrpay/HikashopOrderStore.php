<?php
// Map payment-order storage to HikaShop.


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

    public function loadOne(int $orderId)
    {
        $order = hikashop_get('class.order')->get($orderId);
        if (!$order) {
            return null;
        }

        $p = (array) (isset($order->order_payment_params) ? $order->order_payment_params : array());

        $matches = array();
        if (!empty($p['xmrpay_matches'])) {
            $decoded = json_decode($p['xmrpay_matches'], true);
            $matches = is_array($decoded) ? $decoded : array();
        }

        $result = array(
            'id'              => $orderId,
            'birthday_height' => (int) (isset($p['xmrpay_birthday_height']) ? $p['xmrpay_birthday_height'] : 0),
            'scanned_to'      => (int) (isset($p['xmrpay_scanned_to']) ? $p['xmrpay_scanned_to'] : 0),
            'matches'         => $matches,
            'xmr_amount'      => (string) (isset($p['xmrpay_amount']) ? $p['xmrpay_amount'] : '0'),
            'status'          => 'pending',

            'received_pico'   => (string) (isset($p['xmrpay_received_pico']) ? $p['xmrpay_received_pico'] : '0'),
        );
        foreach (array('scan_checkpoint', 'partial_block', 'scan_complete', 'seen_pico',
            'invoice_status', 'funded_at', 'funded_amount_atomic', 'closed_reason') as $field) {
            if (array_key_exists('xmrpay_' . $field, $p)) {
                $result[$field] = $p['xmrpay_' . $field];
            }
        }
        return $result;
    }

    public function saveProgress(int $orderId, array $patch): void
    {
        $orderClass = hikashop_get('class.order');
        $order      = $orderClass->get($orderId);
        if (!$order) {
            return;
        }

        $raw = isset($order->order_payment_params) ? $order->order_payment_params : null;
        $p   = is_object($raw) ? $raw : (object) ((array) $raw);

        if (array_key_exists('birthday_height', $patch)) $p->xmrpay_birthday_height = (int) $patch['birthday_height'];
        if (array_key_exists('scanned_to', $patch))      $p->xmrpay_scanned_to      = (int) $patch['scanned_to'];
        if (array_key_exists('matches', $patch))         $p->xmrpay_matches         = json_encode($patch['matches']);
        if (array_key_exists('received_pico', $patch))   $p->xmrpay_received_pico   = (string) $patch['received_pico'];
        foreach (array('scan_checkpoint', 'partial_block', 'scan_complete', 'seen_pico',
            'invoice_status', 'funded_at', 'funded_amount_atomic', 'closed_reason') as $field) {
            if (array_key_exists($field, $patch)) {
                $p->{'xmrpay_' . $field} = $patch[$field];
            }
        }

        if (array_key_exists('xmr_amount', $patch))      $p->xmrpay_amount          = (string) $patch['xmr_amount'];
        if (array_key_exists('subaddress', $patch))      $p->xmrpay_subaddress      = (string) $patch['subaddress'];
        if (array_key_exists('rate', $patch) && $patch['rate'] !== null) $p->xmrpay_rate = (string) $patch['rate'];

        $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__hikashop_order'))
            ->set($db->quoteName('order_payment_params') . ' = ' . $db->quote(serialize($p)))
            ->set($db->quoteName('order_modified') . ' = ' . time())
            ->where($db->quoteName('order_id') . ' = ' . (int) $orderId);
        $db->setQuery($query);
        $db->execute();
    }

    public function isSettled(string $txid): bool
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__xmrpay_txids') . ' WHERE ' . $db->quoteName('txid') . ' = ' . $db->quote($txid));
            return (bool) $db->loadResult();
        } catch (\Throwable $e) {

            error_log('[xmr-pay] dedup table query failed (is #__xmrpay_txids installed?): ' . $e->getMessage());
            return false;
        }
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        // Keep the pending check, transaction claim and native update atomic.
        $db->transactionStart(true);
        try {
            $db->setQuery('SELECT order_status FROM #__hikashop_order WHERE order_id=' . (int) $orderId
                . ' AND order_payment_method=' . $db->quote($this->methodName)
                . ($this->paymentId > 0 ? ' AND order_payment_id=' . $this->paymentId : '') . ' FOR UPDATE');
            if ($db->loadResult() !== $this->pendingStatus) {
                throw new RuntimeException('Order is no longer pending for this payment method');
            }

            $claim             = new stdClass();
            $claim->txid       = $txid;
            $claim->order_id   = $orderId;
            $claim->settled_at = time();
            $db->insertObject('#__xmrpay_txids', $claim);

            $history = array(
                'notified' => $this->notifyEmail ? 1 : 0,
                'type'     => 'payment',
                'data'     => 'XMR txid: ' . $txid,
            );
            if (!empty($verdict['received_pico'])) {
                $history['amount'] = $verdict['received_pico'];
            }

            $oid = $orderId;
            if ($this->hasStatefulApplication()) {
                hikashop_get('class.order')->modifyOrder($oid, $this->paidStatus, $this->methodName, $history, false, array('xmrpay_txid' => $txid));
            } else {
                $this->markPaidDirect($orderId, $txid, $history);
            }
            $db->setQuery('SELECT order_status FROM #__hikashop_order WHERE order_id=' . (int) $orderId);
            if ($db->loadResult() !== $this->paidStatus) {
                throw new RuntimeException('Native order status update failed');
            }
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);
            throw $e;
        }
    }

    private function hasStatefulApplication(): bool
    {
        try {
            $app = \Joomla\CMS\Factory::getApplication();
            return method_exists($app, 'getUserState') && method_exists($app, 'setUserState');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function markPaidDirect(int $orderId, string $txid, array $history): void
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        $db->setQuery(
            'SELECT ' . $db->quoteName('order_payment_params') .
            ' FROM ' . $db->quoteName('#__hikashop_order') .
            ' WHERE ' . $db->quoteName('order_id') . ' = ' . (int) $orderId
        );
        $raw = $db->loadResult();
        $p   = function_exists('hikashop_unserialize') ? hikashop_unserialize($raw) : @unserialize((string) $raw);
        $p   = is_object($p) ? $p : new stdClass();
        $p->xmrpay_txid = $txid;

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__hikashop_order'))
            ->set($db->quoteName('order_status') . ' = ' . $db->quote($this->paidStatus))
            ->set($db->quoteName('order_payment_params') . ' = ' . $db->quote(serialize($p)))
            ->set($db->quoteName('order_modified') . ' = ' . time())
            ->where($db->quoteName('order_id') . ' = ' . (int) $orderId)
            ->where($db->quoteName('order_status') . ' = ' . $db->quote($this->pendingStatus))
            ->where($db->quoteName('order_payment_method') . ' = ' . $db->quote($this->methodName));
        if ($this->paymentId > 0) {
            $query->where($db->quoteName('order_payment_id') . ' = ' . (int) $this->paymentId);
        }
        $db->setQuery($query);
        $db->execute();

        if ($db->getAffectedRows() < 1) {
            throw new RuntimeException('HikaShop order was not updated');
        }

        $row                         = new stdClass();
        $row->history_order_id       = $orderId;
        $row->history_created        = time();
        $row->history_ip             = '';
        $row->history_new_status     = $this->paidStatus;
        $row->history_reason         = class_exists('JText') ? \JText::sprintf('AUTOMATIC_PAYMENT_NOTIFICATION') : 'Automatic payment notification';
        $row->history_notified       = 0;
        $row->history_amount         = !empty($history['amount']) ? (string) $history['amount'] : '';
        $row->history_package_id     = 0;
        $row->history_payment_id     = $this->paymentId > 0 ? (string) $this->paymentId : '';
        $row->history_payment_method = $this->methodName;
        $row->history_data           = !empty($history['data']) ? (string) $history['data'] : ('XMR txid: ' . $txid);
        $row->history_type           = !empty($history['type']) ? (string) $history['type'] : 'payment';
        $row->history_user_id        = 0;
        $db->insertObject('#__hikashop_history', $row);
    }
}
