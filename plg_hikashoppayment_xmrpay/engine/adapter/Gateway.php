<?php

namespace XmrPay\Adapter;

use XmrPay\Scanner;
use XmrPay\Util;

/**
 * Platform-agnostic core for a Monero payment adapter. It wraps the xmr-pay-php engine (view-key
 * only, no wallet-rpc, no daemon) with the pieces every gateway needs: a per-order receiving
 * subaddress, a fiat to XMR amount, a monero: URI for the QR, and the scan plus summarize
 * primitives the settlement loop drives.
 *
 * There are no platform calls in here, so it runs under plain php and is unit tested that way. A
 * cart adapter (HikaShop, VirtueMart, J2Store) is a thin layer that maps the cart's order flow onto
 * these calls and implements an {@see OrderStore}; {@see Settler} reuses the settlement loop.
 *
 * Config keys: address, view_key, nodes (string or array), network, min_confirmations,
 * index_offset, http_timeout.
 */
class Gateway
{
    private array $cfg;

    /** Lazily built engine scanner, or a test double injected via setScanner(). */
    private $scanner = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** Inject a scanner (the live engine, or a fake in tests). Returns $this for chaining. */
    public function setScanner($scanner): self
    {
        $this->scanner = $scanner;
        return $this;
    }

    public function scanner()
    {
        if ($this->scanner === null) {
            $nodes = $this->cfg['nodes'] ?? '';
            $node  = is_array($nodes) ? implode(',', $nodes) : (string) $nodes;
            $this->scanner = new Scanner($node, $this->cfg['network'] ?? 'mainnet', (int) ($this->cfg['http_timeout'] ?? 20));
        }
        return $this->scanner;
    }

    /** True only if the php crypto extensions the engine needs (gmp, bcmath) are present. */
    public function cryptoReady(): bool
    {
        return Util::crypto_ready();
    }

    /** Checks the configured address and view key match. Returns ['address_valid'=>bool, 'key_match'=>bool]. */
    public function verifyKeys(): array
    {
        return (array) $this->scanner()->verify_keys($this->cfg['address'] ?? '', $this->cfg['view_key'] ?? '');
    }

    /**
     * The Monero subaddress index for an order. Index 0 is the wallet's primary address and is
     * reserved, so order ids map to index 1 and up. index_offset lets one wallet serve several
     * installs without subaddress collisions; the offset must be wider than the other install's
     * highest order id.
     */
    public function indexForOrder(int $orderId): int
    {
        // clamp the offset to >= 0 so a negative value can't collapse several orders onto index 1
        return max(1, $orderId + max(0, (int) ($this->cfg['index_offset'] ?? 0)));
    }

    /** The receiving subaddress for an order. Derived from the primary address and view key alone. */
    public function subaddressForOrder(int $orderId): string
    {
        $r = $this->scanner()->subaddress(0, $this->indexForOrder($orderId), $this->cfg['view_key'], $this->cfg['address']);
        return is_array($r) ? ($r['address'] ?? '') : (string) $r;
    }

    /**
     * Build a monero: payment URI for a QR / a wallet deep link. tx_amount is the standard field a
     * wallet reads to prefill the amount; recipient_name is shown by some wallets. The same string
     * every adapter used to hand-roll.
     */
    public function moneroUri(string $address, string $xmr, ?string $label = null): string
    {
        $uri = 'monero:' . $address . '?tx_amount=' . $xmr;
        if ($label !== null && $label !== '') {
            $uri .= '&recipient_name=' . rawurlencode($label);
        }
        return $uri;
    }

    /**
     * Convert an order's fiat total to the XMR amount to request. If the order is already priced in
     * XMR there is no conversion and no third party. Otherwise a rate is fetched once; the caller
     * locks the returned amount on the order so a later rate move does not change what the buyer
     * owes. $rateFetch(currency) returns XMR per 1 fiat unit and is injectable, which the tests use
     * and which lets a merchant swap the price source. Returns ['xmr'=>string, 'rate'=>string|null].
     */
    public function xmrAmount(float $amount, string $currency, ?callable $rateFetch = null): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'XMR') {
            // locale-safe + precision-safe: number_format with an explicit '.' (a low `precision` ini
            // or a comma locale would otherwise corrupt the cast for an XMR-priced order).
            return ['xmr' => Util::pico_to_string(Util::xmr_to_pico(number_format($amount, 12, '.', ''))), 'rate' => null];
        }
        $fetch      = $rateFetch ?: [self::class, 'fetchRate'];
        $xmrPerUnit = (float) call_user_func($fetch, $currency);   // xmr per 1 fiat unit
        if ($xmrPerUnit <= 0) {
            throw new \RuntimeException("could not get an XMR/$currency rate");
        }
        $xmr = $amount * $xmrPerUnit;
        // number_format with explicit separators is locale-independent; sprintf('%.12f') is NOT — on
        // a host whose LC_NUMERIC uses a comma decimal (de/es/fr/…) it would yield "0,066", which
        // xmr_to_pico then reads as 0. That silently broke every order on comma-locale servers.
        return ['xmr' => Util::pico_to_string(Util::xmr_to_pico(number_format($xmr, 12, '.', ''))), 'rate' => (string) $xmrPerUnit];
    }

    /**
     * An unguessable, storage-free token authorising the checkout poll for one order. Derived by HMAC
     * from the order id keyed on the merchant's view key (a secret the buyer/an attacker never has),
     * so a stranger cannot enumerate order ids or trigger scans on orders that aren't theirs — and it
     * needs no column. Validate with hash_equals(orderToken(...), $requestToken).
     */
    public static function orderToken(int $orderId, string $secret): string
    {
        return substr(hash_hmac('sha256', $orderId . '|xmrpay-poll', (string) $secret), 0, 24);
    }

    /** Default rate source: XMR per 1 unit of $currency from a public price api. Makes a network call. */
    public static function fetchRate(string $currency): float
    {
        $vs    = strtolower($currency);
        $url   = "https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=$vs";
        $raw   = self::httpGet($url);
        $j     = $raw ? json_decode($raw, true) : null;
        $price = isset($j['monero'][$vs]) ? (float) $j['monero'][$vs] : 0.0;   // fiat per 1 xmr
        return $price > 0 ? 1.0 / $price : 0.0;                                // xmr per 1 fiat
    }

    /**
     * Build the rate fetcher a merchant configured, to hand to xmrAmount(). Lets a store price in a
     * currency the default source doesn't list, run its own price feed, or pin a fixed rate. cfg:
     *   rate_source: 'coingecko' (default) | 'fixed' | 'custom'
     *   fixed_rate:  the price of 1 XMR in the store currency (fiat per XMR), for 'fixed'
     *   rate_url:    a URL returning the price of 1 XMR in the store currency, for 'custom'
     *                ({currency} / {CURRENCY} are substituted with the store currency code)
     * Returns null to use the built-in CoinGecko default. A fixed/custom fetcher returns 0 when
     * misconfigured, which makes xmrAmount() throw "could not get a rate" — fail closed, never wrong.
     */
    public static function rateFetcher(array $cfg): ?callable
    {
        $src = isset($cfg['rate_source']) ? strtolower(trim((string) $cfg['rate_source'])) : '';

        if ($src === 'fixed') {
            $fiatPerXmr = (float) ($cfg['fixed_rate'] ?? 0);
            $xmrPerFiat = $fiatPerXmr > 0 ? 1.0 / $fiatPerXmr : 0.0;
            return function ($currency) use ($xmrPerFiat) {
                return $xmrPerFiat;   // a fixed rate ignores the currency; the merchant set it per their currency
            };
        }
        if ($src === 'custom') {
            $url = (string) ($cfg['rate_url'] ?? '');
            return function ($currency) use ($url) {
                return $url === '' ? 0.0 : self::fetchRateFromUrl($url, $currency);
            };
        }
        return null;   // CoinGecko default
    }

    /** Fetch the price of 1 XMR in $currency from a merchant-supplied URL; return XMR per 1 fiat unit. */
    public static function fetchRateFromUrl(string $url, string $currency): float
    {
        $url   = str_replace(['{currency}', '{CURRENCY}'], [strtolower($currency), strtoupper($currency)], $url);
        $price = self::parsePrice(self::httpGet($url));   // fiat per 1 xmr
        return $price > 0 ? 1.0 / $price : 0.0;           // xmr per 1 fiat
    }

    /** Pull a positive price (1 XMR in fiat) from a bare-number body or simple JSON ({price|rate|...}). */
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
        // no recognised price key: return 0 (xmrAmount then throws, fail-closed). Do NOT grab the
        // first number found — that could be a timestamp / status code, locking the order at a
        // dust amount a buyer could satisfy with 1 piconero.
        return 0.0;
    }

    /**
     * Scan an order's subaddress over [from, to] and return the raw engine result
     * ['matches'=>array, 'scanned_to'=>int]. The scan is bounded by max_blocks and time_budget so a
     * single call stays quick; the settler advances a checkpoint across runs to cover the whole range.
     */
    public function scanRange(int $orderId, int $from, int $to, array $opts = []): array
    {
        $sub = $this->subaddressForOrder($orderId);
        return $this->scanner()->scan_all($sub, $this->cfg['view_key'], $from, $to, array_merge([
            'tip'         => $to,
            'max_blocks'  => 500,
            'time_budget' => 15.0,
        ], $opts));
    }

    /**
     * Summarize accumulated match rows into a settlement verdict. Confirmations are recomputed from
     * each match's block height against the current tip, so rows persisted by an earlier run age
     * correctly without being rescanned. Returns the engine's summarize_payments verdict
     * (paid, status, received_pico, txids, and so on).
     */
    public function summarizeMatches(array $matches, string $expectedXmr, int $tip, int $minConf): array
    {
        foreach ($matches as &$m) {
            if (isset($m['block_height'])) {
                $m['confirmations'] = max(0, $tip - (int) $m['block_height']);
            }
        }
        unset($m);
        return (array) Util::summarize_payments($matches, (string) Util::xmr_to_pico($expectedXmr), '0', $minConf);
    }

    /**
     * Keep the accumulated matches that sit below the rescanned window, then append the fresh rows
     * for the rest. Matches at or after $from are expected to be present in $fresh, so this both
     * preserves deep history and replaces the recently rescanned range without duplicating rows.
     */
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

    /**
     * Single-shot check used by the unit tests: scan once from the birthday height to the tip and
     * summarize. The settler does not use this; it keeps a checkpoint and accumulates instead.
     */
    public function checkOrder(int $orderId, string $expectedXmr, int $birthdayHeight, ?int $minConf = null): array
    {
        $tip = $this->scanner()->tip_height();
        if (null === $tip) {
            return ['paid' => false, 'status' => 'node-error', 'received_pico' => '0', 'tip' => null];
        }
        $minConf = max(1, $minConf ?? (int) ($this->cfg['min_confirmations'] ?? 10));   // never settle at 0-conf
        $res     = $this->scanRange($orderId, (int) $birthdayHeight, $tip);
        $verdict = $this->summarizeMatches($res['matches'] ?? [], $expectedXmr, $tip, $minConf);
        return array_merge($verdict, [
            'subaddress' => $this->subaddressForOrder($orderId),
            'scanned_to' => $res['scanned_to'] ?? $tip,
            'tip'        => $tip,
        ]);
    }

    private static function httpGet(string $url): ?string
    {
        // curl first: the https stream wrapper fails on many hosts (CA bundle / allow_url_fopen),
        // while curl ships its own CA store and is the reliable path. fall back to the wrapper only
        // where curl is unavailable.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'xmr-pay-adapter',
                // the rate URL can be a merchant-set custom endpoint: keep a redirect from
                // reaching file:// / gopher:// etc.
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $r    = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($r !== false && $code >= 200 && $code < 300) {
                return $r;
            }
        }

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
        // file_get_contents follows the request even on a 5xx/4xx; reject a non-2xx body so an error
        // page can't be parsed as a live price. $http_response_header is set in the local scope.
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)
            && ((int) $m[1] < 200 || (int) $m[1] >= 300)) {
            return null;
        }
        return $r;
    }
}
