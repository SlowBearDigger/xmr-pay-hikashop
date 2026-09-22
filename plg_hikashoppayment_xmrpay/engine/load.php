<?php
// Load the bundled payment engine and adapter.

defined('_JEXEC') or die('Restricted access');

if (!class_exists('XmrPay\\Scanner', false)) {
    $base = __DIR__;
    foreach ([
        '/third-party/monero/base58.php',
        '/third-party/monero/Varint.php',
        '/third-party/monero/Keccak.php',
        '/third-party/monero/ed25519.php',
        '/third-party/monero/Cryptonote.php',
        '/src/Util.php',
        '/src/NodeConfig.php',
        '/src/Scanner.php',
        '/adapter/Gateway.php',
        '/adapter/OrderStore.php',
        '/adapter/Settler.php',
        '/adapter/ArrayOrderStore.php',
    ] as $f) {
        require_once $base . $f;
    }
}
