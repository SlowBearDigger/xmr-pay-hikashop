# xmr-pay for HikaShop

A non-custodial Monero payment method for HikaShop on Joomla 5.4+. The order is placed pending at
checkout and the buyer is shown a receiving subaddress + the exact XMR amount + a live poll; the
order settles once the engine confirms a real on-chain payment. View key only: no
`monero-wallet-rpc`, no daemon: funds go straight to the merchant's wallet.

Built on the shared [xmr-pay engine](https://github.com/SlowBearDigger/xmr-pay-php) + [adapter core](https://github.com/SlowBearDigger/xmr-pay-adapter-core),
vendored into the plugin (HikaShop ships a zip onto a host with no Composer). The Monero work lives
in the engine; this package is the thin HikaShop layer, modelled on HikaShop's bundled offline
plugins (bank transfer / check).

## Install

1. Download `pkg_xmrpay_hikashop.zip` from the [latest release](https://github.com/SlowBearDigger/xmr-pay-hikashop/releases).
2. In the Joomla admin, go to System, Install, Extensions, Upload Package File, and select the zip.
   One package installs both the payment plugin and the settlement scheduler task.
3. Enable both plugins. Joomla installs every third-party plugin disabled by default; this is a
   Joomla-wide behaviour, not specific to this one. Go to System, Manage, Plugins, search `xmr-pay`,
   and enable the HikaShop Monero (xmr-pay) Payment Plugin and the Task - xmr-pay Monero settlement
   plugin. Skipping the second one is the most common setup mistake: the payment method will still
   work at checkout, but nothing settles once the buyer closes the tab.
4. In HikaShop, create a new payment method of type Monero (XMR). Fill in your wallet's primary
   address, its private view key (never the spend key), and one or more Monero nodes, one per line. A
   public node is fine to start; run your own for real money. Saving now actually tries to reach your
   node(s) and tells you immediately if it can't.
   If you use a custom price feed, its URL must be public HTTPS with a public IPv4 address; cURL is required and private addresses and redirects are rejected.
5. Create the background sweep. Go to System, Scheduled Tasks, New Task, and pick "xmr-pay: settle
   pending Monero orders". Save it, then set up Joomla's Web Cron under Global Configuration, System
   tab, Scheduler (or the ad-hoc URL Joomla shows on the task page). HikaShop needs a real web request
   to run its own code, so the CLI `scheduler:run` console command does not work here, but Web Cron
   does. This sweep is the backstop that settles an order even if the buyer paid and closed the
   browser tab before the on-page poll caught it.

## Packages

```
plg_hikashoppayment_xmrpay/   # the payment method plugin (built, installs clean)
  xmrpay.php                  # plgHikashoppaymentXmrpay extends hikashopPaymentPlugin
  xmrpay_end.php              # checkout screen: subaddress + amount + monero: link + live poll
  HikashopOrderStore.php      # implements XmrPay\Adapter\OrderStore over HikaShop's order API
  xmrpay.xml                  # manifest (+ install SQL for the dedup table)
  sql/                        # #__xmrpay_txids (UNIQUE txid: the dedup guard)
  engine/                     # vendored engine + adapter core + load.php
plg_task_xmrpaysettle/        # Joomla 5 Scheduler task → Settler::run()
```

## How it maps onto HikaShop (verified against HikaShop 6.5.0 source)

| Adapter need | HikaShop |
|---|---|
| place order pending | `onAfterOrderConfirm` keeps `order_status = 'created'` (configurable), like bank transfer |
| receiving address / amount / currency | `$order->order_id`, `$order->order_full_price`, `$this->currency->currency_code` |
| per-order scan state | serialized `order_payment_params` under `xmrpay_*` keys (no schema change) |
| mark paid | `class.order::modifyOrder($id, $paid_status, 'xmrpay', $history, null, ['xmrpay_txid'=>…])`: txid in the order-history `data` |
| txid dedup | `#__xmrpay_txids` with a UNIQUE index (HikaShop has no native dedup) |
| checkout "is it paid?" poll | `onPaymentNotification` → `Settler::settleOrder($row)` → JSON `{paid,status}` |
| background sweep | a Joomla 5 `task` plugin → `Settler::run()` |

## Validation

The original integration was exercised on Joomla 5.4.6 with HikaShop 6.5.0.
Rebuilt packages still need a full staging checkout and background-settlement test
before deployment. Source tests and a prior platform run do not validate a new build.

### Scheduler note

HikaShop assumes a **web application** (it calls `setUserState()` etc.), so the settlement task runs
under Joomla's **Web Cron / lazy scheduler** (a web request), not the `cli/joomla.php scheduler:run`
console app: the CLI app lacks those methods (same class of limitation as VirtueMart's web-only
installer). The on-demand checkout poll already settles in web context; the scheduled task is the
backstop for orders whose buyer closed the tab. Document Web Cron as the supported trigger for merchants.

## Build and test

Place this repository alongside `xmr-pay-php`, `xmr-pay-adapter-core`,
`xmr-pay-virtuemart` and `xmr-pay-build`, then run `./build.sh` in the packaging
repository. Install the resulting package on a Joomla staging site with HikaShop.
Use matching stagenet keys and nodes; test checkout polling and Web Cron settlement.

Settlement requires InnoDB order, payment and history tables. The pending-state check, transaction claim and native status update share a row lock and transaction. Older installations using nontransactional tables must migrate those tables before enabling settlement.
