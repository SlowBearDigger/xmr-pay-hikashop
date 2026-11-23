<?php
// Render an existing native invoice without reissuing its quote or changing payment state.
$bootstrap = getenv('XMRPAY_JOOMLA_BOOTSTRAP');
$orderId = (int) getenv('XMRPAY_TEST_ORDER_ID');
if (!$bootstrap || !is_file($bootstrap) || $orderId <= 0) {
    fwrite(STDERR, "Set XMRPAY_JOOMLA_BOOTSTRAP and XMRPAY_TEST_ORDER_ID for a disposable native fixture.\n");
    exit(2);
}
require $bootstrap;
$plugin = hikashop_import('hikashoppayment', 'xmrpay');
$db = Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
$db->setQuery('SELECT * FROM #__hikashop_order WHERE order_id=' . $orderId);
$before = $db->loadAssoc();
$order = hikashop_get('class.order')->get($orderId);
if (!$order || empty($order->order_payment_params->xmrpay_subaddress)) {
    throw new RuntimeException('A native pending invoice with an issued quote is required.');
}
foreach ([1, 2] as $attempt) {
    $result = $plugin->getThankYouContent($order);
    $html = is_array($result) ? $result['html'] : $result;
    if (strpos($html, 'id="xmrpay-pay"') === false
        || strpos($html, htmlspecialchars($order->order_payment_params->xmrpay_subaddress)) === false
        || strpos($html, 'tx_amount=' . $order->order_payment_params->xmrpay_amount) === false) {
        throw new RuntimeException('Returning to the native thank-you page must render the stored payment request.');
    }
}
$other = clone $order;
$other->order_payment_method = 'unrelated';
if ($plugin->getThankYouContent($other) !== '') {
    throw new RuntimeException('The payment hook must ignore orders using another method.');
}
$other = clone $order;
$other->order_status = 'cancelled';
if ($plugin->getThankYouContent($other) !== '') {
    throw new RuntimeException('Closed native orders must not show payment instructions.');
}
$db->setQuery('SELECT * FROM #__hikashop_order WHERE order_id=' . $orderId);
if ($db->loadAssoc() !== $before) {
    throw new RuntimeException('Payment-page reentry must preserve the complete native order row.');
}
echo "PASS: native thank-you reentry, repeat rendering, method/status guards and unchanged order\n";
