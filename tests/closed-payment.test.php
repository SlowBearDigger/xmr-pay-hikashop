<?php
// Use the valid signed callback URL retained from a new, unfunded, natively cancelled order.
$url = getenv('XMRPAY_CLOSED_PAYMENT_POLL_URL');
if (!$url) {
    fwrite(STDERR, "Set XMRPAY_CLOSED_PAYMENT_POLL_URL for an unfunded native cancellation fixture.\n");
    exit(2);
}
$context = stream_context_create(['http' => ['header' => "Accept: application/json\r\n", 'timeout' => 90]]);
$response = @file_get_contents($url, false, $context);
if ($response === false) {
    throw new RuntimeException('The actual native payment callback was unavailable.');
}
$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
echo 'Native callback: ' . json_encode(array_intersect_key($result, array_flip(['paid', 'status', 'received', 'shortfall']))) . "\n";
if (($result['paid'] ?? null) !== false || ($result['status'] ?? null) !== 'not-found'
    || array_key_exists('received', $result) || array_key_exists('shortfall', $result)) {
    throw new RuntimeException('A cancelled native order must not offer payment or a top-up.');
}
echo "PASS: the authenticated native callback refuses the cancelled unpaid order\n";
