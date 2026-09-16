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
echo "vendored node config OK\n";
