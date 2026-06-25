<?php

/**
 * Loads the vendored xmr-pay engine + adapter core without Composer. HikaShop ships as a zip onto a
 * host that has no Composer, so the engine is vendored here and required in dependency order, the
 * same pattern the WooCommerce and WHMCS adapters use. Idempotent: safe to require more than once.
 */

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
        '/src/Scanner.php',
        '/adapter/Gateway.php',
        '/adapter/OrderStore.php',
        '/adapter/Settler.php',
        '/adapter/ArrayOrderStore.php',
    ] as $f) {
        require_once $base . $f;
    }
}
