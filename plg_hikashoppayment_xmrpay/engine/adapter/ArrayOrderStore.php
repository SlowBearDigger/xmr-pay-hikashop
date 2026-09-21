<?php
// Store payment orders in memory.

namespace XmrPay\Adapter;

class ArrayOrderStore implements OrderStore
{

    private array $orders;

    private array $settledTxids = [];

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
            $this->orders[$orderId] = array_merge($this->orders[$orderId], $patch);
        }
    }

    public function isSettled(string $txid): bool
    {
        return !empty($this->settledTxids[$txid]);
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        $this->settledTxids[$txid] = true;
        if (isset($this->orders[$orderId])) {
            $this->orders[$orderId]['status'] = 'paid';
            $this->orders[$orderId]['txid']   = $txid;
        }
    }

    public function get(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }
}
