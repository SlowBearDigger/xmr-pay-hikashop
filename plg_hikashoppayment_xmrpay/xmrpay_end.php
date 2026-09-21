<?php
// Render the HikaShop payment response.


defined('_JEXEC') or die('Restricted access');

$uri       = isset($this->xmr_uri) ? $this->xmr_uri : '';
$sub       = isset($this->xmr_subaddress) ? $this->xmr_subaddress : '';
$xmr       = isset($this->xmr_amount) ? $this->xmr_amount : '';
$fiat      = isset($this->amount) ? $this->amount : '';
$err       = !empty($this->xmr_error);
$qrLibUrl  = \Joomla\CMS\Uri\Uri::root(true) . '/plugins/hikashoppayment/xmrpay/qrcode.min.js';
$pollUrl   = isset($this->poll_url) ? $this->poll_url : '';
$returnUrl = isset($this->return_url) ? $this->return_url : '';

require __DIR__ . '/views/pay_card.php';
