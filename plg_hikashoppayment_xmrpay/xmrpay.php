<?php

/**
 * xmr-pay for HikaShop — a non-custodial Monero payment method.
 *
 * The order is placed pending at checkout; the buyer is shown a receiving subaddress + the exact XMR
 * amount + a live poll. A Joomla scheduled task (plg_task_xmrpaysettle) and this plugin's poll
 * endpoint settle the order once the engine confirms a real on-chain payment. View key only — no
 * wallet-rpc, no daemon, funds go straight to the merchant. The Monero work lives in the vendored
 * engine + adapter core; this file is the thin HikaShop layer, modelled on the bundled offline
 * plugins (bank transfer / check).
 */

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

    /**
     * The backend configuration form. order_status is the status an order sits in while it waits for
     * payment (kept at 'created', not the built-in 'pending', so HikaShop's accepted-payment side
     * effects do not fire early); paid_status is what a confirmed payment promotes it to.
     */
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

    /** Pre-fills the config form the first time the method is created. */
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

    /** Load the progressive node editor only on the HikaShop payment settings screen. */
    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);
        $doc  = \Joomla\CMS\Factory::getDocument();
        $base = \Joomla\CMS\Uri\Uri::root(true) . '/plugins/hikashoppayment/xmrpay/assets/';
        $doc->addStyleSheet($base . 'admin-nodes.css');
        $doc->addScript($base . 'admin-nodes.js');
    }

    /**
     * Validate the merchant's address + view key against each other when they save the config, so a
     * typo is caught at setup instead of silently failing every payment. Never logs the view key.
     */
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
                        // this hook can in principle be reached from a non-interactive path (a CLI
                        // provisioning script, a bulk config import); only probe the network from an
                        // actual admin-backend request, so a script updating params in bulk never
                        // inherits a slow/blocking network call it didn't ask for.
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
                // an unexpected local error (bad config shape, missing extension, etc.) -- not a
                // network message, since we only reach the network check inside the nested try above.
            }
        }
        return $r;
    }

    /**
     * Runs right after the order is placed. Leaves the order pending, locks the XMR amount and the
     * receiving subaddress onto it, records the chain height as the order's birthday, and renders the
     * instructions screen (QR + amount + live poll). The order is NOT marked paid here.
     */
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
            $amt  = $g->xmrAmount((float) $order->order_full_price, $code, Gateway::rateFetcher($cfg));   // locks the rate (merchant source/fixed)

            // persist the locked amount + subaddress NOW, before the tip call can throw — otherwise a
            // node that's down only for tip_height would lose a perfectly good locked amount and the
            // order would be unsettleable. the birthday is saved separately right after.
            $store->saveProgress($orderId, array(
                'subaddress' => $sub,
                'xmr_amount' => $amt['xmr'],
                'rate'       => $amt['rate'],
            ));

            $tip = $g->scanner()->tip_height();                              // birthday, null if node down
            $store->saveProgress($orderId, array('birthday_height' => (int) $tip));

            $this->xmr_subaddress = $sub;
            $this->xmr_amount     = $amt['xmr'];
            $this->xmr_uri        = $g->moneroUri($sub, $amt['xmr'], 'Order ' . $order->order_number);
        } catch (\Throwable $e) {
            // could not reach a node / price source at checkout: still show the page, the scheduled
            // task will set the birthday and lock-in happens on the next pass. surface nothing secret.
            $this->xmr_subaddress = isset($sub) ? $sub : '';
            $this->xmr_amount     = isset($amt['xmr']) ? $amt['xmr'] : '';
            $this->xmr_uri        = '';
            $this->xmr_error      = true;
        }

        // keep the order in its pending status (matches the offline plugins' pattern). don't email on
        // this transition — HikaShop already sends the "order created" mail; the customer notification
        // fires later, once the payment actually confirms (see HikashopOrderStore::markPaid).
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

    /**
     * The checkout "is it paid yet?" poll, served through HikaShop's notify route. It settles just
     * this one order on demand (so a buyer who has paid is confirmed without waiting for the next
     * scheduled sweep) and answers JSON {paid, status}.
     */
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

        // an unconfigured method has an empty view key, which makes the HMAC token guessable. refuse
        // to answer at all rather than expose a forgeable endpoint.
        if (empty($cfg['view_key'])) {
            return $this->pollResponse($app, false, 'not-found');
        }

        // validate the per-order poll token (HMAC keyed on the view key). without it a stranger could
        // enumerate order ids and force node scans; respond generically on mismatch, revealing nothing.
        $token = (string) $app->input->getString('token', '');
        if (!hash_equals(Gateway::orderToken($orderId, (string) $cfg['view_key']), $token)) {
            return $this->pollResponse($app, false, 'not-found');
        }

        // already settled: answer paid immediately, do not rescan
        if ($order->order_status === $cfg['paid_status']) {
            return $this->pollResponse($app, true, 'already-paid');
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
        // partial-payment feedback: settleOrder just persisted received_pico. if some funds arrived but
        // it is not yet paid (an underpayment, or the first of several txs), tell the buyer how much
        // more to send — to the same address, since the engine sums payments to the subaddress.
        if (!$paid && $status === 'ok') {
            $after = $store->loadOne($orderId);
            $pf    = Gateway::partialFeedback((string) $rows['xmr_amount'], $after ? (isset($after['received_pico']) ? $after['received_pico'] : '0') : '0');
            if ($pf !== null) {
                $status = 'partial';
                $extra  = $pf;
            }
        }

        return $this->pollResponse($app, $paid, $status, $extra);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** Build the engine config array from a HikaShop payment_params object. */
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

    /** Load the payment_params for the method attached to an order (used outside checkout). */
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

    /** Check every saved node independently so a broken secondary is never hidden by failover. */
    private function probeNodes(array $cfg)
    {
        $nodes   = \XmrPay\NodeConfig::normalizeList($cfg['nodes']);
        $public  = \XmrPay\NodeConfig::publicList($nodes);
        // a short dedicated timeout, and a cap on how many rows this save-time probe touches,
        // keep a save with several dead nodes from hanging the admin request past PHP's/the
        // webserver's execution limit; the real settlement scan (Settler/task) is unaffected,
        // it builds its own Gateway with the configured timeout.
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

    /** Node failures are warnings only. Saving remains non-blocking and explicit. */
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
