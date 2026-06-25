<?php

namespace XmrPay\Adapter;

/**
 * A minimal in-memory {@see OrderStore}. It is the reference implementation an adapter author copies
 * the shape of, and the fixture the Settler tests drive. A real cart store does the same four things
 * against the cart's own order table instead of an array.
 */
class ArrayOrderStore implements OrderStore
{
    /** @var array<int,array> id => order row */
    private array $orders;

    /** @var array<string,bool> txid => credited */
    private array $settledTxids = [];

    /** @param array<int,array> $orders id => row (id, birthday_height, scanned_to, matches, xmr_amount, status) */
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

    /** Test/inspection helper: the current row for an order. */
    public function get(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }
}
