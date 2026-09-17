<?php
define('_JEXEC', 1);
require __DIR__ . '/../plg_hikashoppayment_xmrpay/engine/load.php';

$nodes = json_encode([["url" => "http://127.0.0.1:38093", "auth" => "digest", "username" => "user", "password" => "secret", "allow_insecure_http" => true]]);
$gateway = new XmrPay\Adapter\Gateway(['nodes' => $nodes]);
$scanner = $gateway->scanner();
$property = new ReflectionProperty(XmrPay\Scanner::class, 'nodes');
if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
$stored = $property->getValue($scanner);
if (count($stored) !== 1 || $stored[0]['url'] !== 'http://127.0.0.1:38093') throw new RuntimeException('node URL was not loaded');
if ($stored[0]['auth'] !== 'digest' || $stored[0]['password'] !== 'secret') throw new RuntimeException('node auth was not loaded');
$public = json_encode($gateway->nodeConfig());
if (strpos($public, 'secret') !== false || strpos($public, 'digest') === false) throw new RuntimeException('public node metadata exposed credentials');
$rateTarget = new ReflectionMethod(XmrPay\Adapter\Gateway::class, 'publicRateTarget');
if (PHP_VERSION_ID < 80100) $rateTarget->setAccessible(true);
foreach (['http://8.8.8.8/rate', 'https://127.0.0.1/rate', 'https://169.254.169.254/rate',
    'https://100.64.0.1/rate', 'https://198.18.0.1/rate', 'https://user@8.8.8.8/rate'] as $url) {
    if ($rateTarget->invoke(null, $url) !== null) throw new RuntimeException('unsafe rate URL accepted');
}
if ($rateTarget->invoke(null, 'https://8.8.8.8/rate') !== ['8.8.8.8', 443, '8.8.8.8']) {
    throw new RuntimeException('public rate address was not pinned');
}
echo "vendored node config OK\n";
