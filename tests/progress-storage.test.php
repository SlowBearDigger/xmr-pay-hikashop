<?php
// Verify scan progress roundtrips through an installed plugin and native disposable database.
$bootstrap = getenv('XMRPAY_JOOMLA_BOOTSTRAP');
$orderId = (int) getenv('XMRPAY_TEST_ORDER_ID');
if (!$bootstrap || !is_file($bootstrap) || $orderId <= 0) {
    fwrite(STDERR, "Set XMRPAY_JOOMLA_BOOTSTRAP and XMRPAY_TEST_ORDER_ID for a disposable native fixture.\n");
    exit(2);
}
require $bootstrap;
require_once JPATH_PLUGINS . '/hikashoppayment/xmrpay/HikashopOrderStore.php';
$store = new HikashopOrderStore();
$db = Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
$before = $store->loadOne($orderId);
if ($before === null) {
    throw new RuntimeException('The native fixture order is missing.');
}
$checkpoint = ['height' => 1009, 'hash' => hash('sha256', '1009')];
$partial = ['height' => 1010, 'next_transaction' => 5];
$db->transactionStart();
try {
    $store->saveProgress($orderId, ['scan_checkpoint' => $checkpoint, 'partial_block' => $partial, 'scan_complete' => false]);
    $loaded = $store->loadOne($orderId);
    if (($loaded['scan_checkpoint'] ?? null) !== $checkpoint
        || ($loaded['partial_block'] ?? null) !== $partial
        || ($loaded['scan_complete'] ?? null) !== false) {
        throw new RuntimeException('Authenticated and partial scan progress must survive native storage.');
    }
    $policy = ['seen_pico' => '25000000', 'invoice_status' => 'processing',
        'funded_at' => 1700000000, 'funded_amount_atomic' => '25000000', 'closed_reason' => null];
    $store->saveProgress($orderId, $policy);
    $loaded = $store->loadOne($orderId);
    foreach ($policy as $field => $value) {
        if (!array_key_exists($field, $loaded) || $loaded[$field] !== $value) {
            throw new RuntimeException('Seen receipts and invoice policy must survive native storage: ' . $field);
        }
    }
    $store->saveProgress($orderId, ['matches' => []]);
    $loaded = $store->loadOne($orderId);
    if (($loaded['scan_checkpoint'] ?? null) !== $checkpoint || ($loaded['partial_block'] ?? null) !== $partial) {
        throw new RuntimeException('Updating matches must preserve scan metadata.');
    }
    foreach ($policy as $field => $value) {
        if (!array_key_exists($field, $loaded) || $loaded[$field] !== $value) {
            throw new RuntimeException('Updating matches must preserve invoice progress: ' . $field);
        }
    }
    $cleared = ['seen_pico' => null, 'funded_at' => null, 'funded_amount_atomic' => null, 'closed_reason' => null];
    $store->saveProgress($orderId, $cleared);
    $loaded = $store->loadOne($orderId);
    foreach ($cleared as $field => $value) {
        if (!array_key_exists($field, $loaded) || $loaded[$field] !== null) {
            throw new RuntimeException('Clearing invoice progress must preserve explicit NULL values: ' . $field);
        }
    }
    $store->saveProgress($orderId, ['partial_block' => null, 'scan_complete' => true]);
    $loaded = $store->loadOne($orderId);
    if (($loaded['partial_block'] ?? null) !== null || ($loaded['scan_complete'] ?? null) !== true
        || $loaded['xmr_amount'] !== $before['xmr_amount'] || $loaded['birthday_height'] !== $before['birthday_height']) {
        throw new RuntimeException('Completion must clear its cursor without changing the issued quote.');
    }
} finally {
    $db->transactionRollback();
}
if ($store->loadOne($orderId) !== $before) {
    throw new RuntimeException('Native progress regression must leave its fixture unchanged.');
}
echo "PASS: native scan checkpoint, invoice policy, seen receipts, NULL clears and completion storage\n";
