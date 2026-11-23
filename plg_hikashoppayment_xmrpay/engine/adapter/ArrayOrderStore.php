<?php
// Store payment orders in memory.

namespace XmrPay\Adapter;

class ArrayOrderStore implements OutputOrderStore
{

    private array $orders;

    private array $settledTxids = [];
    private array $legacyTxids = [];
    private array $settledOutputs = [];
    private array $settledKeys = [];

    public function __construct(array $orders = [])
    {
        $this->orders = [];
        foreach ($orders as $o) {
            $this->orders[(int) $o['id']] = $o + [
                'birthday_height' => 0,
                'scanned_to'      => 0,
                'matches'         => [],
                'status'          => 'pending',
            ];
        }
    }

    public function loadPending(): iterable
    {
        $out = [];
        foreach ($this->orders as $o) {
            if (($o['status'] ?? 'pending') === 'pending') {
                $out[] = $o;
            }
        }
        return $out;
    }

    public function saveProgress(int $orderId, array $patch): void
    {
        if (isset($this->orders[$orderId])) {
            $this->orders[$orderId] = InvoicePolicy::mergeProgress($this->orders[$orderId], $patch);
        }
    }

    public function isSettled(string $txid): bool
    {
        return !empty($this->settledTxids[$txid]);
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        if (isset($this->orders[$orderId])) {
            InvoicePolicy::assertPayable($this->orders[$orderId], $verdict);
        }
        $this->legacyTxids[$txid] = true;
        $this->settledTxids[$txid] = true;
        if (isset($this->orders[$orderId])) {
            $this->orders[$orderId]['status'] = 'paid';
            $this->orders[$orderId]['txid']   = $txid;
            $this->orders[$orderId]['invoice_status'] = 'settled';
        }
    }

    public function isOutputSettled(array $receipt): bool
    {
        $location = $receipt['txid'] . ':' . $receipt['output_index'];
        return isset($this->legacyTxids[$receipt['txid']])
            || isset($this->settledOutputs[$location])
            || isset($this->settledKeys[$receipt['out_key']]);
    }

    public function markPaidFromOutputs(int $orderId, array $receipts, array $verdict): void
    {
        if (!$receipts || !isset($this->orders[$orderId]) || $this->orders[$orderId]['status'] !== 'pending') {
            throw new \RuntimeException('Order is not pending or has no outputs');
        }
        $locations = [];
        InvoicePolicy::assertPayable($this->orders[$orderId], $verdict);
        $keys = [];
        foreach ($receipts as $receipt) {
            $location = $receipt['txid'] . ':' . $receipt['output_index'];
            if ($this->isOutputSettled($receipt) || isset($locations[$location]) || isset($keys[$receipt['out_key']])) {
                throw new \RuntimeException('Output is already claimed');
            }
            $locations[$location] = true;
            $keys[$receipt['out_key']] = true;
        }
        foreach ($receipts as $receipt) {
            $this->settledOutputs[$receipt['txid'] . ':' . $receipt['output_index']] = $orderId;
            $this->settledKeys[$receipt['out_key']] = $orderId;
            $this->settledTxids[$receipt['txid']] = true;
        }
        $this->orders[$orderId]['status'] = 'paid';
        $this->orders[$orderId]['invoice_status'] = 'settled';
        $this->orders[$orderId]['txid'] = $receipts[0]['txid'];
        $this->orders[$orderId]['receipts'] = $receipts;
        $this->orders[$orderId]['payment_verdict'] = $verdict;
    }

    public function get(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }
}
