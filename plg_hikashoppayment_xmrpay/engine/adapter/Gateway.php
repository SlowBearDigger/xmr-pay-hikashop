<?php
// Create payment requests with per-order addresses and fiat quotes.

namespace XmrPay\Adapter;

use XmrPay\Scanner;
use XmrPay\NodeConfig;
use XmrPay\Util;

class Gateway
{
    private array $cfg;

    private $scanner = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function setScanner($scanner): self
    {
        $this->scanner = $scanner;
        return $this;
    }

    public function scanner()
    {
        if ($this->scanner === null) {
            $nodes = $this->cfg['nodes'] ?? '';
            $this->scanner = $this->createScanner($nodes, $this->cfg['network'] ?? 'mainnet', (int) ($this->cfg['http_timeout'] ?? 20));
        }
        return $this->scanner;
    }

    public function nodeConfig(): array
    {
        return NodeConfig::publicList(NodeConfig::normalizeList($this->cfg['nodes'] ?? ''));
    }

    protected function createScanner($nodes, string $network, int $timeout)
    {
        return new Scanner($nodes, $network, $timeout, $this->cfg['minimum_nodes'] ?? 1);
    }

    public function cryptoReady(): bool
    {
        return Util::crypto_ready();
    }

    public function verifyKeys(): array
    {
        return (array) $this->scanner()->verify_keys($this->cfg['address'] ?? '', $this->cfg['view_key'] ?? '');
    }

    public function indexForOrder(int $orderId): int
    {

        return max(1, $orderId + max(0, (int) ($this->cfg['index_offset'] ?? 0)));
    }

    public function subaddressForOrder(int $orderId): string
    {
        $r = $this->scanner()->subaddress(0, $this->indexForOrder($orderId), $this->cfg['view_key'], $this->cfg['address']);
        return is_array($r) ? ($r['address'] ?? '') : (string) $r;
    }

    public function moneroUri(string $address, string $xmr, ?string $label = null): string
    {
        $uri = 'monero:' . $address . '?tx_amount=' . $xmr;
        if ($label !== null && $label !== '') {
            $uri .= '&recipient_name=' . rawurlencode($label);
        }
        return $uri;
    }

    public function xmrAmount(float $amount, string $currency, ?callable $rateFetch = null): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'XMR') {

            return ['xmr' => Util::pico_to_string(Util::xmr_to_pico(number_format($amount, 12, '.', ''))), 'rate' => null];
        }
        $fetch      = $rateFetch ?: [self::class, 'fetchRate'];
        $xmrPerUnit = (float) call_user_func($fetch, $currency);
        if ($xmrPerUnit <= 0) {
            throw new \RuntimeException("could not get an XMR/$currency rate");
        }
        $xmr = $amount * $xmrPerUnit;

        return ['xmr' => Util::pico_to_string(Util::xmr_to_pico(number_format($xmr, 12, '.', ''))), 'rate' => (string) $xmrPerUnit];
    }

    public static function orderToken(int $orderId, string $secret): string
    {
        return substr(hash_hmac('sha256', $orderId . '|xmrpay-poll', (string) $secret), 0, 24);
    }

    public static function partialFeedback(string $expectedXmr, ?string $receivedPico): ?array
    {
        $receivedPico = (string) $receivedPico;
        if ($receivedPico === '' || $receivedPico === '0') {
            return null;
        }
        $expectedPico = Util::xmr_to_pico_exact($expectedXmr);

        if (gmp_cmp($receivedPico, $expectedPico) >= 0) {
            return null;
        }
        $shortfallPico = gmp_strval(gmp_sub($expectedPico, $receivedPico));
        return [
            'received'  => Util::pico_to_string($receivedPico),
            'shortfall' => Util::pico_to_string($shortfallPico),
        ];
    }

    /** Offer a top-up only when every observed contribution is confirmed. */
    public static function paymentFeedback(string $expectedXmr, ?string $receivedPico, ?string $seenPico): array
    {
        foreach ([$receivedPico, $seenPico] as $amount) {
            if ($amount === null || !preg_match('/^(0|[1-9][0-9]*)$/D', $amount)) {
                return ['status' => 'processing'];
            }
        }
        if ($receivedPico !== $seenPico) {
            return ['status' => 'processing'];
        }
        if ($seenPico === '0') {
            return ['status' => 'pending'];
        }
        $partial = self::partialFeedback($expectedXmr, $receivedPico);
        return $partial === null ? ['status' => 'processing'] : array_merge(['status' => 'partial'], $partial);
    }

    public static function fetchRate(string $currency): float
    {
        $vs    = strtolower($currency);
        $url   = "https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=$vs";
        $raw   = self::httpGet($url);
        $j     = $raw ? json_decode($raw, true) : null;
        $price = isset($j['monero'][$vs]) ? (float) $j['monero'][$vs] : 0.0;
        return $price > 0 ? 1.0 / $price : 0.0;
    }

    public static function rateFetcher(array $cfg): ?callable
    {
        $src = isset($cfg['rate_source']) ? strtolower(trim((string) $cfg['rate_source'])) : '';

        if ($src === 'fixed') {
            $fiatPerXmr = (float) ($cfg['fixed_rate'] ?? 0);
            $xmrPerFiat = $fiatPerXmr > 0 ? 1.0 / $fiatPerXmr : 0.0;
            return function ($currency) use ($xmrPerFiat) {
                return $xmrPerFiat;
            };
        }
        if ($src === 'custom') {
            $url = (string) ($cfg['rate_url'] ?? '');
            return function ($currency) use ($url) {
                return $url === '' ? 0.0 : self::fetchRateFromUrl($url, $currency);
            };
        }
        return null;
    }

    public static function fetchRateFromUrl(string $url, string $currency): float
    {
        $url   = str_replace(['{currency}', '{CURRENCY}'], [strtolower($currency), strtoupper($currency)], $url);
        $price = self::parsePrice(self::httpGet($url, true));
        return $price > 0 ? 1.0 / $price : 0.0;
    }

    private static function parsePrice(?string $raw): float
    {
        if ($raw === null) {
            return 0.0;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            foreach (['price', 'rate', 'result', 'value', 'last', 'xmr_price'] as $k) {
                if (isset($j[$k]) && is_numeric($j[$k])) {
                    return (float) $j[$k];
                }
            }
        }

        return 0.0;
    }

    public function scanRange(int $orderId, int $from, int $to, array $opts = []): array
    {
        $sub = $this->subaddressForOrder($orderId);
        return $this->scanner()->scan_all($sub, $this->cfg['view_key'], $from, $to, array_merge([
            'tip'         => $to,
            'max_blocks'  => 500,
            'time_budget' => 15.0,
        ], $opts));
    }

    /** Addresses must be issued requests belonging to this configured wallet. */
    public function scanBatchRange(array $addresses, int $from, int $to, array $opts = []): array
    {
        return $this->scanner()->scan_many($addresses, $this->cfg['view_key'], $from, $to, $opts);
    }

    public function summarizeMatches(array $matches, string $expectedXmr, int $tip, int $minConf): array
    {
        $matches = $this->refreshMatches($matches, $tip);
        return (array) Util::summarize_payments($matches, Util::xmr_to_pico_exact($expectedXmr), '0', $minConf);
    }

    private function refreshMatches(array $matches, int $tip): array
    {
        foreach ($matches as &$m) {
            if (isset($m['block_height'])) {
                $m['confirmations'] = max(0, $tip - (int) $m['block_height']);
            }
            if (isset($m['unlock_time'])) {
                $m['locked'] = Scanner::is_locked($m['unlock_time'], $tip);
            }
        }
        unset($m);
        return $matches;
    }

    public function confirmedOutputs(array $matches, int $tip, int $minConf): array
    {
        $receipts = [];
        foreach (Util::dedup_outputs($this->refreshMatches($matches, $tip)) as $match) {
            $eligible = Util::summarize_payments([$match], '1', '0', $minConf);
            if (empty($eligible['paid'])) {
                continue;
            }
            if (!isset($match['txid'], $match['output_index'], $match['out_key'])
                || !is_string($match['txid']) || preg_match('/^[0-9a-f]{64}$/D', $match['txid']) !== 1
                || !is_int($match['output_index']) || $match['output_index'] < 0
                || !is_string($match['out_key']) || preg_match('/^[0-9a-f]{64}$/D', $match['out_key']) !== 1) {
                throw new \UnexpectedValueException('Confirmed output identity is incomplete');
            }
            $receipts[] = [
                'txid' => $match['txid'],
                'output_index' => $match['output_index'],
                'out_key' => $match['out_key'],
                'amount_atomic' => $match['amount_atomic'],
            ];
        }
        return $receipts;
    }

    public static function mergeMatches(array $accumulated, array $fresh, int $from): array
    {
        $kept = [];
        foreach ($accumulated as $m) {
            if ((int) ($m['block_height'] ?? 0) < $from) {
                $kept[] = $m;
            }
        }
        foreach ($fresh as $m) {
            $kept[] = $m;
        }
        return $kept;
    }

    public static function scanComplete(array $result, int $tip): bool
    {
        $checkpoint = $result['checkpoint'] ?? null;
        return is_array($checkpoint)
            && isset($checkpoint['height'], $checkpoint['hash'])
            && is_int($checkpoint['height'])
            && $checkpoint['height'] === ($result['scanned_to'] ?? null)
            && is_string($checkpoint['hash'])
            && preg_match('/^[0-9a-f]{64}$/D', $checkpoint['hash']) === 1
            && $checkpoint['height'] >= $tip - 1
            && empty($result['reorg_detected'])
            && empty($result['checkpoint_error'])
            && empty($result['budget_exhausted'])
            && empty($result['partial_block'])
            && (!array_key_exists('scan_complete', $result) || $result['scan_complete'] === true)
            && empty($result['unsupported_outputs']);
    }

    public function checkOrder(int $orderId, string $expectedXmr, int $birthdayHeight, ?int $minConf = null): array
    {
        $tip = $this->scanner()->tip_height();
        $nodeError = method_exists($this->scanner(), 'last_node_error')
            ? $this->scanner()->last_node_error() : ['code' => 'none', 'node_index' => null, 'url' => ''];
        if (null === $tip) {
            $scanStatus = method_exists($this->scanner(), 'budget_exhausted') && $this->scanner()->budget_exhausted()
                ? 'budget-exhausted' : 'unavailable';
            return ['paid' => false, 'status' => 'node-error', 'received_pico' => '0', 'tip' => null,
                'scan_status' => $scanStatus, 'scan_complete' => false, 'node_error' => $nodeError];
        }
        $minConf = max(1, $minConf ?? (int) ($this->cfg['min_confirmations'] ?? 10));
        $res     = $this->scanRange($orderId, (int) $birthdayHeight, $tip - 1, ['tip' => $tip]);
        $verdict = $this->summarizeMatches($res['matches'] ?? [], $expectedXmr, $tip, $minConf);
        if (!self::scanComplete($res, $tip)) {
            $verdict['paid'] = false;
            $verdict['status'] = 'scan_incomplete';
        }
        return array_merge($verdict, [
            'subaddress' => $this->subaddressForOrder($orderId),
            'scanned_to' => $res['scanned_to'] ?? $tip,
            'tip'        => $tip,
            'scan_status' => $res['scan_status'] ?? (self::scanComplete($res, $tip) ? 'complete' : 'incomplete'),
            'scan_complete' => self::scanComplete($res, $tip),
            'node_error' => $res['node_error'] ?? $nodeError,
        ]);
    }

    private static function publicRateTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535 || !preg_match('/^[a-z0-9.-]+$/iD', $host)) { return null; }
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : gethostbynamel($host);
        if (!$addresses) { return null; }
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return null; }
            $octets = array_map('intval', explode('.', $ip));
            if (($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127)
                || ($octets[0] === 198 && ($octets[1] === 18 || $octets[1] === 19))) { return null; }
        }
        return [$host, $port, $addresses[0]];
    }

    private static function httpGet(string $url, bool $custom = false): ?string
    {
        $target = $custom ? self::publicRateTarget($url) : null;
        if ($custom && (!$target || !function_exists('curl_init') || !defined('CURLOPT_RESOLVE'))) { return null; }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { return null; }
            if (!curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_FOLLOWLOCATION => !$custom,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'xmr-pay-adapter',

                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ])) { return null; }
            if ($custom) {
                if (!curl_setopt($ch, CURLOPT_PROXY, '')
                    || !curl_setopt($ch, CURLOPT_RESOLVE, [$target[0] . ':' . $target[1] . ':' . $target[2]])) { return null; }
            }
            $r    = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($r !== false && $code >= 200 && $code < 300) {
                return $r;
            }
        }

        if ($custom) { return null; }

        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 12,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\n",
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) {
            return null;
        }

        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)
            && ((int) $m[1] < 200 || (int) $m[1] >= 300)) {
            return null;
        }
        return $r;
    }
}
