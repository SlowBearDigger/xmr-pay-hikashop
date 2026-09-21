<?php
// Reconcile stored payment requests against daemon evidence.

namespace XmrPay\Adapter;

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

                continue;
            }
        }
        return $report;
    }

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

            $report['status'] = 'error';
            return $report;
        }
        $report['settled'] = $r['settled'] ? 1 : 0;
        $report['paid']    = $r['settled'];
        return $report;
    }

    private function params(): array
    {

        $minConf = max(1, (int) ($this->opts['min_confirmations'] ?? 10));
        return [
            'min_conf'    => $minConf,
            'reorg'       => max(15, $minConf + 5),
            'max_blocks'  => (int) ($this->opts['max_blocks'] ?? 1000),
            'time_budget' => (float) ($this->opts['time_budget'] ?? 20.0),

            'lookback'    => max(0, (int) ($this->opts['birthday_lookback'] ?? 1440)),
        ];
    }

    private function step(array $o, int $tip, array $p): array
    {
        $id = (int) $o['id'];

        $expected = trim((string) ($o['xmr_amount'] ?? ''));
        if ($expected === '' || (float) $expected <= 0) {
            return ['settled' => false];
        }

        $birthday = (int) ($o['birthday_height'] ?? 0);
        if ($birthday <= 0) {

            $this->store->saveProgress($id, ['birthday_height' => max(1, $tip - $p['lookback'])]);
            return ['settled' => false];
        }

        $prevScanned = (int) ($o['scanned_to'] ?? 0);

        $from = $prevScanned > 0 ? max($birthday, min($prevScanned + 1, $tip - $p['reorg'])) : $birthday;

        try {
            $res = $this->g->scanRange($id, $from, $tip, ['max_blocks' => $p['max_blocks'], 'time_budget' => $p['time_budget']]);
        } catch (\Throwable $e) {
            return ['settled' => false];
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
