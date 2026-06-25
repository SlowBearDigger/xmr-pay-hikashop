<?php

namespace XmrPay\Adapter;

/**
 * The settlement loop, lifted out of any one platform's cron. Monero has no payment webhook, so
 * detection is poll-based: on each run we scan every pending order's subaddress with the view-key
 * engine and mark it paid once a confirmed payment of the expected amount has arrived. No
 * wallet-rpc, no daemon.
 *
 * Each order keeps a scan checkpoint (scanned_to) and its accumulated matches, so a run resumes
 * where the last one stopped instead of rescanning from the start. A trailing margin is rescanned
 * every run so confirmations mature and short reorgs are picked up.
 *
 * A cart adapter wires this in two places, both ~5 lines:
 *   - the background sweep — a Joomla scheduled task, a WHMCS cron hook — calls run().
 *   - the checkout "is it paid yet?" poll calls settleOrder($row) for one order, so a buyer who has
 *     just paid is settled immediately instead of waiting for the next sweep.
 *
 *     $report = (new Settler($gateway, $store, ['min_confirmations' => 10]))->run();
 */
class Settler
{
    private Gateway $g;
    private OrderStore $store;
    private array $opts;

    public function __construct(Gateway $g, OrderStore $store, array $opts = [])
    {
        $this->g     = $g;
        $this->store = $store;
        $this->opts  = $opts;
    }

    /**
     * Run one settlement pass over every pending order. Returns a report
     * ['checked'=>int, 'settled'=>int, 'status'=>string]. status is 'ok', 'crypto-missing' (the
     * server lacks gmp/bcmath) or 'node-error' (no node answered this run, retry next time).
     */
    public function run(): array
    {
        $report = ['checked' => 0, 'settled' => 0, 'status' => 'ok'];

        if (!$this->g->cryptoReady()) {
            $report['status'] = 'crypto-missing';
            return $report;
        }
        $tip = $this->g->scanner()->tip_height();
        if ($tip === null) {
            $report['status'] = 'node-error';
            return $report;
        }

        $p = $this->params();
        foreach ($this->store->loadPending() as $o) {
            $report['checked']++;
            try {
                if ($this->step($o, $tip, $p)['settled']) {
                    $report['settled']++;
                }
            } catch (\Throwable $e) {
                // one order failing to settle (e.g. the cart's status update threw) must not abort the
                // whole sweep — markPaid releases its txid claim on failure, so this order retries next run.
                continue;
            }
        }
        return $report;
    }

    /**
     * Settle one order now, without waiting for the next sweep. The checkout poll passes the order's
     * pending row and learns whether it just settled. Returns
     * ['checked'=>int, 'settled'=>int, 'status'=>string, 'paid'=>bool]; status mirrors run().
     */
    public function settleOrder(array $order): array
    {
        $report = ['checked' => 1, 'settled' => 0, 'status' => 'ok', 'paid' => false];

        if (!$this->g->cryptoReady()) {
            $report['status'] = 'crypto-missing';
            $report['checked'] = 0;
            return $report;
        }
        $tip = $this->g->scanner()->tip_height();
        if ($tip === null) {
            $report['status'] = 'node-error';
            $report['checked'] = 0;
            return $report;
        }

        try {
            $r = $this->step($order, $tip, $this->params());
        } catch (\Throwable $e) {
            // markPaid released its claim; report not-paid so the poll keeps waiting and retries.
            $report['status'] = 'error';
            return $report;
        }
        $report['settled'] = $r['settled'] ? 1 : 0;
        $report['paid']    = $r['settled'];
        return $report;
    }

    /** Resolve the scan/confirmation tunables once per pass. */
    private function params(): array
    {
        // clamp to >= 1: a min_confirmations of 0 (a merchant who cleared or zeroed the field) would
        // settle on a still-in-mempool / reorg-able payment, releasing goods before funds are final.
        $minConf = max(1, (int) ($this->opts['min_confirmations'] ?? 10));
        return [
            'min_conf'    => $minConf,
            'reorg'       => max(15, $minConf + 5),   // rescan this many recent blocks each run
            'max_blocks'  => (int) ($this->opts['max_blocks'] ?? 1000),
            'time_budget' => (float) ($this->opts['time_budget'] ?? 20.0),
            // when the node was down at checkout the birthday is unset; on the first pass we reach
            // back this many blocks so a payment sent in the gap is still scanned (≈2 days on mainnet).
            'lookback'    => max(0, (int) ($this->opts['birthday_lookback'] ?? 1440)),
        ];
    }

    /**
     * Advance one order: resume from its checkpoint, scan, accumulate, summarize, persist, and credit
     * it if a confirmed payment of the full amount has arrived. Returns ['settled'=>bool]. Never
     * throws on a node hiccup — it leaves the checkpoint put and reports settled=false.
     */
    private function step(array $o, int $tip, array $p): array
    {
        $id = (int) $o['id'];

        // fail closed if the amount was never locked (rate/node down at checkout left it empty/0).
        // the engine already treats expected<=0 as 'invalid', but make it explicit here: never scan
        // or settle an order without a real expected amount, whatever the verdict shape.
        $expected = trim((string) ($o['xmr_amount'] ?? ''));
        if ($expected === '' || (float) $expected <= 0) {
            return ['settled' => false];
        }

        $birthday = (int) ($o['birthday_height'] ?? 0);
        if ($birthday <= 0) {
            // node was down at checkout, so the birthday was never set. set it now, but reach BACK by
            // the lookback so a payment sent in the gap before this first pass is still scanned —
            // jumping to the current tip would silently skip an on-time payment.
            $this->store->saveProgress($id, ['birthday_height' => max(1, $tip - $p['lookback'])]);
            return ['settled' => false];
        }

        $prevScanned = (int) ($o['scanned_to'] ?? 0);
        // resume just past the checkpoint, but never start later than tip minus the reorg margin so
        // the most recent blocks are always rescanned. always moves forward, never stalls.
        $from = $prevScanned > 0 ? max($birthday, min($prevScanned + 1, $tip - $p['reorg'])) : $birthday;

        try {
            $res = $this->g->scanRange($id, $from, $tip, ['max_blocks' => $p['max_blocks'], 'time_budget' => $p['time_budget']]);
        } catch (\Throwable $e) {
            return ['settled' => false];   // node hiccup, the checkpoint stays put, retry next run
        }

        $accumulated = isset($o['matches']) && is_array($o['matches']) ? $o['matches'] : [];
        $merged      = Gateway::mergeMatches($accumulated, $res['matches'] ?? [], $from);
        $scannedTo   = (int) ($res['scanned_to'] ?? $from);

        $verdict = $this->g->summarizeMatches($merged, (string) $o['xmr_amount'], $tip, $p['min_conf']);

        $this->store->saveProgress($id, [
            'matches'       => $merged,
            'scanned_to'    => $scannedTo,
            'received_pico' => $verdict['received_pico'] ?? '0',
        ]);

        if (empty($verdict['paid'])) {
            return ['settled' => false];
        }

        // require a real on-chain txid — never settle on a synthesised id. a 'paid' verdict with no
        // txid is a malformed/unexpected result; fail closed rather than credit an unauditable payment.
        if (empty($verdict['txids'])) {
            return ['settled' => false];
        }
        $txid = (string) $verdict['txids'][0];
        if (!$this->store->isSettled($txid)) {
            $this->store->markPaid($id, $txid, $verdict);
            return ['settled' => true];
        }
        return ['settled' => false];
    }
}
