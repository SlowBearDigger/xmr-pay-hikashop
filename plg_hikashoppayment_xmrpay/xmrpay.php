<?php
// Integrate Monero payments with HikaShop.


defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/engine/load.php';
require_once __DIR__ . '/HikashopOrderStore.php';

use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

#[\AllowDynamicProperties]
class plgHikashoppaymentXmrpay extends hikashopPaymentPlugin
{
    public $name     = 'xmrpay';
    public $multiple = true;

    public $pluginConfig = array(
        'address'            => array('XMRPAY_ADDRESS', 'input', ''),
        'view_key'           => array('XMRPAY_VIEW_KEY', 'input', ''),
        'nodes'              => array('XMRPAY_NODES', 'textarea', ''),
        'http_timeout'       => array('XMRPAY_HTTP_TIMEOUT', 'input', '20'),
        'network'            => array('XMRPAY_NETWORK', 'list', array('mainnet' => 'mainnet', 'stagenet' => 'stagenet', 'testnet' => 'testnet')),
        'min_confirmations'  => array('XMRPAY_MIN_CONFIRMATIONS', 'input', '10'),
        'index_offset'       => array('XMRPAY_INDEX_OFFSET', 'input', '0'),
        'rate_source'        => array('XMRPAY_RATE_SOURCE', 'list', array('coingecko' => 'CoinGecko (automatic)', 'fixed' => 'Fixed rate', 'custom' => 'Custom price URL')),
        'fixed_rate'         => array('XMRPAY_FIXED_RATE', 'input', ''),
        'rate_url'           => array('XMRPAY_RATE_URL', 'input', ''),
        'order_status'       => array('XMRPAY_PENDING_STATUS', 'orderstatus', 'created'),
        'paid_status'        => array('XMRPAY_PAID_STATUS', 'orderstatus', 'confirmed'),
        'status_notif_email' => array('ORDER_STATUS_NOTIFICATION', 'boolean', '1'),
        'return_url'         => array('XMRPAY_RETURN_URL', 'input', ''),
    );

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name        = 'Monero (XMR)';
        $element->payment_description = 'Pay privately and directly with Monero. No third party holds your funds.';
        $element->payment_params->address           = '';
        $element->payment_params->view_key          = '';
        $element->payment_params->nodes             = '';
        $element->payment_params->http_timeout      = '20';
        $element->payment_params->network           = 'mainnet';
        $element->payment_params->min_confirmations = '10';
        $element->payment_params->index_offset      = '0';
        $element->payment_params->rate_source       = 'coingecko';
        $element->payment_params->fixed_rate        = '';
        $element->payment_params->rate_url          = '';
        $element->payment_params->order_status      = 'created';
        $element->payment_params->paid_status       = 'confirmed';
        $element->payment_params->status_notif_email = '1';
        $element->payment_params->return_url        = '';
    }

    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);
        $doc  = \Joomla\CMS\Factory::getDocument();
        $base = \Joomla\CMS\Uri\Uri::root(true) . '/plugins/hikashoppayment/xmrpay/assets/';
        $doc->addStyleSheet($base . 'admin-nodes.css');
        $doc->addScript($base . 'admin-nodes.js');
    }

    public function onPaymentConfigurationSave(&$element)
    {
        $r = parent::onPaymentConfigurationSave($element);

        $p = isset($element->payment_params) ? $element->payment_params : null;
        if ($p && !empty($p->address) && !empty($p->view_key)) {
            try {
                $g  = new Gateway($this->cfgFromParams($p));
                if (!$g->cryptoReady()) {
                    $this->propagateMessage('XmrPay: this server is missing the gmp/bcmath PHP extensions; payments cannot be verified.', 'warning');
                } else {
                    $v = $g->verifyKeys();
                    if (empty($v['address_valid'])) {
                        $this->propagateMessage('XmrPay: the configured address does not look valid.', 'error');
                    } elseif (empty($v['key_match'])) {
                        $this->propagateMessage('XmrPay: the view key does not match the address.', 'error');
                    } elseif (empty(trim((string) $p->nodes))) {
                        $this->propagateMessage('XmrPay: no node is configured, so payments will never be detected. Add at least one Monero node (one per line).', 'warning');
                    } elseif (!\Joomla\CMS\Factory::getApplication()->isClient('administrator')) {

                    } else {
                        try {
                            $probeCfg = $this->cfgFromParams($p);
                            $results  = $this->probeNodes($probeCfg);
                        } catch (\Throwable $e) {
                            $results = null;
                        }
                        $this->reportNodeProbe($results);
                    }
                }
            } catch (\Throwable $e) {

            }
        }
        return $r;
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        $orderId = (int) $order->order_id;
        $cfg     = $this->cfgFromParams($this->payment_params);
        $store   = $this->store($cfg);

        try {
            $g    = new Gateway($cfg);
            $code = isset($this->currency->currency_code) ? $this->currency->currency_code : '';
            $sub  = $g->subaddressForOrder($orderId);
            $amt  = $g->xmrAmount((float) $order->order_full_price, $code, Gateway::rateFetcher($cfg));

            $store->saveProgress($orderId, array(
                'subaddress' => $sub,
                'xmr_amount' => $amt['xmr'],
                'rate'       => $amt['rate'],
            ));

            $tip = $g->scanner()->tip_height();
            $store->saveProgress($orderId, array('birthday_height' => (int) $tip));

            $this->xmr_subaddress = $sub;
            $this->xmr_amount     = $amt['xmr'];
            $this->xmr_uri        = $g->moneroUri($sub, $amt['xmr'], 'Order ' . $order->order_number);
        } catch (\Throwable $e) {

            $this->xmr_subaddress = isset($sub) ? $sub : '';
            $this->xmr_amount     = isset($amt['xmr']) ? $amt['xmr'] : '';
            $this->xmr_uri        = '';
            $this->xmr_error      = true;
        }

        if (!empty($this->payment_params->order_status) && $order->order_status != $this->payment_params->order_status) {
            $this->modifyOrder($orderId, $this->payment_params->order_status, false, false);
        }
        $this->removeCart = true;

        $currencyClass     = hikashop_get('class.currency');
        $this->amount      = $currencyClass->format($order->order_full_price, $order->order_currency_id);
        $this->order_id    = $orderId;
        $this->order_number = $order->order_number;
        $this->min_confirmations = (int) (isset($this->payment_params->min_confirmations) ? $this->payment_params->min_confirmations : 10);
        $this->return_url  = isset($this->payment_params->return_url) ? $this->payment_params->return_url : '';
        $this->poll_url    = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=xmrpay&order_id=' . $orderId . '&token=' . Gateway::orderToken($orderId, (string) $cfg['view_key']) . '&tmpl=component';

        return $this->showPage('end');
    }

    public function getThankYouContent(&$order)
    {
        if (!$this->loadPaymentParams($order)) {
            return '';
        }
        $cfg = $this->cfgFromParams($this->payment_params);
        $params = (array) $order->order_payment_params;
        if ($order->order_status !== $cfg['pending_status'] || empty($cfg['view_key'])
            || empty($params['xmrpay_subaddress']) || empty($params['xmrpay_amount'])
            || empty($params['xmrpay_birthday_height'])) {
            return '';
        }

        // Reopening an issued request must not recalculate its quote or scan birthday.
        $orderId = (int) $order->order_id;
        $this->xmr_subaddress = (string) $params['xmrpay_subaddress'];
        $this->xmr_amount = (string) $params['xmrpay_amount'];
        $this->xmr_uri = (new Gateway($cfg))->moneroUri($this->xmr_subaddress, $this->xmr_amount, 'Order ' . $order->order_number);
        $this->xmr_error = false;
        $this->amount = hikashop_get('class.currency')->format($order->order_full_price, $order->order_currency_id);
        $this->order_id = $orderId;
        $this->order_number = $order->order_number;
        $this->return_url = isset($this->payment_params->return_url) ? $this->payment_params->return_url : '';
        $this->poll_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=xmrpay&order_id=' . $orderId . '&token=' . Gateway::orderToken($orderId, (string) $cfg['view_key']) . '&tmpl=component';
        ob_start();
        $this->showPage('end');
        return array('html' => ob_get_clean(), 'awaiting_payment' => true);
    }

    public function onPaymentNotification(&$statuses)
    {
        $app     = \Joomla\CMS\Factory::getApplication();
        $orderId = (int) $app->input->getInt('order_id', 0);
        if ($orderId <= 0) {
            return $this->pollResponse($app, false, 'bad-request');
        }

        $orderClass = hikashop_get('class.order');
        $order      = $orderClass->get($orderId);
        if (!$order || $order->order_payment_method !== $this->name) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $cfg = $this->cfgFromParams($this->paymentParamsForOrder($order));

        if (empty($cfg['view_key'])) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $token = (string) $app->input->getString('token', '');
        if (!hash_equals(Gateway::orderToken($orderId, (string) $cfg['view_key']), $token)) {
            return $this->pollResponse($app, false, 'not-found');
        }

        if ($order->order_status === $cfg['paid_status']) {
            return $this->pollResponse($app, true, 'already-paid');
        }
        if (!$this->isPendingOrder($order, $cfg['pending_status'])) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $store = $this->store($cfg);
        $rows  = $store->loadOne($orderId);
        if ($rows === null) {
            return $this->pollResponse($app, ($order->order_status === $cfg['paid_status']), 'unknown');
        }

        $g       = new Gateway($cfg);
        $settler = new Settler($g, $store, array('min_confirmations' => (int) $cfg['min_confirmations']));
        $rep     = $settler->settleOrder($rows);

        $paid   = !empty($rep['paid']);
        $status = $rep['status'];
        $extra  = array();

        if (!$paid && !$this->isPendingOrder($orderClass->get($orderId), $cfg['pending_status'])) {
            return $this->pollResponse($app, false, 'not-found');
        }
        if (!$paid && $status === 'ok') {
            $after = $store->loadOne($orderId);
            $scanStatus = isset($rep['scan_status']) ? $rep['scan_status'] : 'incomplete';
            if (!in_array($scanStatus, array('complete', 'incomplete', 'waiting-birthday', 'reorg-detected'), true)) {
                $status = 'error';
            } elseif ($scanStatus !== 'complete' || !$after || ($after['scan_complete'] ?? false) !== true) {
                $status = 'processing';
            } else {
                $extra = Gateway::paymentFeedback((string) $rows['xmr_amount'], $after['received_pico'] ?? null, $after['seen_pico'] ?? null);
                $status = $extra['status'];
                unset($extra['status']);
            }
        }

        return $this->pollResponse($app, $paid, $status, $extra);
    }

    private function isPendingOrder($order, $pendingStatus)
    {
        return $order && $order->order_payment_method === $this->name && $order->order_status === $pendingStatus;
    }

    private function cfgFromParams($p)
    {
        return array(
            'address'           => isset($p->address) ? $p->address : '',
            'view_key'          => isset($p->view_key) ? $p->view_key : '',
            'nodes'             => isset($p->nodes) ? $p->nodes : '',
            'http_timeout'      => max(2, min(60, (int) (isset($p->http_timeout) ? $p->http_timeout : 20))),
            'network'           => !empty($p->network) ? $p->network : 'mainnet',
            'min_confirmations' => (int) (isset($p->min_confirmations) ? $p->min_confirmations : 10),
            'index_offset'      => (int) (isset($p->index_offset) ? $p->index_offset : 0),
            'rate_source'       => !empty($p->rate_source) ? $p->rate_source : 'coingecko',
            'fixed_rate'        => isset($p->fixed_rate) ? $p->fixed_rate : '',
            'rate_url'          => isset($p->rate_url) ? $p->rate_url : '',
            'pending_status'    => !empty($p->order_status) ? $p->order_status : 'created',
            'paid_status'       => !empty($p->paid_status) ? $p->paid_status : 'confirmed',
            'notify_email'      => (bool) (isset($p->status_notif_email) ? $p->status_notif_email : 1),
        );
    }

    private function store(array $cfg)
    {
        return new HikashopOrderStore(array(
            'method_name'    => $this->name,
            'pending_status' => $cfg['pending_status'],
            'paid_status'    => $cfg['paid_status'],
            'notify_email'   => $cfg['notify_email'],
        ));
    }

    private function paymentParamsForOrder($order)
    {
        $paymentClass = hikashop_get('class.payment');
        $method       = $paymentClass->get((int) $order->order_payment_id);
        return ($method && isset($method->payment_params)) ? $method->payment_params : new stdClass();
    }

    private function pollResponse($app, $paid, $status, $extra = array())
    {
        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode(array_merge(array('paid' => (bool) $paid, 'status' => $status), (array) $extra));
        $app->close();
    }

    private function propagateMessage($msg, $type = 'message')
    {
        \Joomla\CMS\Factory::getApplication()->enqueueMessage($msg, $type);
    }

    private function probeNodes(array $cfg)
    {
        $nodes   = \XmrPay\NodeConfig::normalizeList($cfg['nodes']);
        $public  = \XmrPay\NodeConfig::publicList($nodes);

        $cfg['http_timeout'] = 5;
        $results = array();
        foreach (array_slice($nodes, 0, 6, true) as $index => $node) {
            $one          = $cfg;
            $one['nodes'] = array($node);
            $scanner      = (new Gateway($one))->scanner();
            $tip          = $scanner->tip_height();
            $error        = method_exists($scanner, 'last_node_error') ? $scanner->last_node_error() : array();
            $results[]    = array(
                'number' => $index + 1,
                'url'    => isset($public[$index]['url']) ? $public[$index]['url'] : '',
                'tip'    => $tip,
                'error'  => isset($error['code']) ? $error['code'] : 'unavailable',
            );
        }
        return $results;
    }

    private function reportNodeProbe($results)
    {
        if (!is_array($results)) {
            $this->propagateMessage('XmrPay: node settings were saved, but could not be checked. Review the node rows and try again.', 'warning');
            return;
        }
        $failed = array_values(array_filter($results, function ($row) { return $row['tip'] === null; }));
        if (!$failed) {
            $this->propagateMessage('XmrPay: checked ' . count($results) . ' Monero node(s). All are connected.', 'message');
            return;
        }
        $details = array_map(function ($row) {
            return 'Node ' . $row['number'] . ' (' . $row['url'] . '): ' . $row['error'];
        }, $failed);
        $working = count($results) - count($failed);
        $suffix  = $working > 0
            ? ' Working nodes remain active. Review or replace the unavailable node(s).'
            : ' Settings were saved, but payment detection will wait until a node reconnects.';
        $this->propagateMessage('XmrPay: checked ' . count($results) . ' node(s). ' . implode('; ', $details) . '.' . $suffix, 'warning');
    }
}
