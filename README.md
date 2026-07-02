# xmr-pay for HikaShop

A non-custodial Monero payment method for HikaShop on Joomla 5.4+. The order is placed pending at
checkout and the buyer is shown a receiving subaddress + the exact XMR amount + a live poll; the
order settles once the engine confirms a real on-chain payment. View key only — no
`monero-wallet-rpc`, no daemon — funds go straight to the merchant's wallet.

Built on the shared [xmr-pay engine](../xmr-pay-php) + [adapter core](../xmr-pay-adapter-core),
vendored into the plugin (HikaShop ships a zip onto a host with no Composer). The Monero work lives
in the engine; this package is the thin HikaShop layer, modelled on HikaShop's bundled offline
plugins (bank transfer / check).

## Install

1. Download `pkg_xmrpay_hikashop.zip` from the [latest release](../../releases/latest).
2. Joomla admin → **System → Install → Extensions → Upload Package File** → select the zip. One
   package installs both the payment plugin and the settlement scheduler task.
3. **Enable both plugins** — Joomla installs every third-party plugin disabled by default (this is a
   Joomla-wide behaviour, not specific to this one). Go to **System → Manage → Plugins**, search
   `xmr-pay`, and enable:
   - *HikaShop Monero (xmr-pay) Payment Plugin*
   - *Task - xmr-pay Monero settlement*

   Skipping the second one is the most common setup mistake — the payment method will still work at
   checkout, but nothing settles once the buyer closes the tab.
4. In HikaShop, create a new payment method of type **Monero (XMR)**: your wallet's primary address,
   its private **view key** (never the spend key), one or more Monero nodes (one per line — a public
   node is fine to start, run your own for real money). Saving now actually tries to reach your
   node(s) and tells you immediately if it can't.
5. Create the background sweep: **System → Scheduled Tasks → New Task**, pick **"xmr-pay: settle
   pending Monero orders"**, save it, and set up Joomla's **Web Cron** (Global Configuration → System
   tab → Scheduler, or the ad-hoc URL Joomla shows on the task) — HikaShop needs a real web request to
   run its own code, so the CLI `scheduler:run` console command does not work here; Web Cron does. This
   sweep is the backstop that settles an order even if the buyer paid and closed the browser tab before
   the on-page poll caught it.

## Packages

```
plg_hikashoppayment_xmrpay/   # the payment method plugin (built, installs clean)
  xmrpay.php                  # plgHikashoppaymentXmrpay extends hikashopPaymentPlugin
  xmrpay_end.php              # checkout screen: subaddress + amount + monero: link + live poll
  HikashopOrderStore.php      # implements XmrPay\Adapter\OrderStore over HikaShop's order API
  xmrpay.xml                  # manifest (+ install SQL for the dedup table)
  sql/                        # #__xmrpay_txids (UNIQUE txid — the dedup guard)
  engine/                     # vendored engine + adapter core + load.php
plg_task_xmrpaysettle/        # Joomla 5 Scheduler task → Settler::run()
```

## How it maps onto HikaShop (verified against HikaShop 6.5.0 source)

| Adapter need | HikaShop |
|---|---|
| place order pending | `onAfterOrderConfirm` keeps `order_status = 'created'` (configurable), like bank transfer |
| receiving address / amount / currency | `$order->order_id`, `$order->order_full_price`, `$this->currency->currency_code` |
| per-order scan state | serialized `order_payment_params` under `xmrpay_*` keys (no schema change) |
| mark paid | `class.order::modifyOrder($id, $paid_status, 'xmrpay', $history, null, ['xmrpay_txid'=>…])` — txid in the order-history `data` |
| txid dedup | `#__xmrpay_txids` with a UNIQUE index (HikaShop has no native dedup) |
| checkout "is it paid?" poll | `onPaymentNotification` → `Settler::settleOrder($row)` → JSON `{paid,status}` |
| background sweep | a Joomla 5 `task` plugin → `Settler::run()` |

## Status

- ✅ Payment plugin builds, installs (`extension:install`), and enables on Joomla 5.4.6 + HikaShop 6.5.0.
- ✅ Vendored engine loads in the Joomla runtime; `Gateway`/`moneroUri` work; dedup table created on install.
- ✅ Scheduler task plugin (`plg_task_xmrpaysettle`) builds, installs, and enables.
- ✅ **End-to-end settlement proven against real stagenet** — both paths: the checkout poll
  (`onPaymentNotification` → `Settler::settleOrder`) and the scheduled **Web Cron** sweep
  (`plg_task_xmrpaysettle` → `Settler::run`). Both scanned the live node, found the payment, flipped
  the order `created → confirmed`, and deduped the txid; a re-poll returns `already-paid` with no
  double credit.
- ✅ **Full checkout walkthrough** in a browser (product → cart → checkout → place order) renders the
  Monero payment screen: locked XMR amount (fiat→XMR via the rate), the real derived subaddress, a
  `monero:` deep link, a **client-side QR** (vendored `qrcode.min.js`, drawn in the browser — the
  address never leaves the page), and the live poll.

### Fixes found during the checkout test

- **`Gateway::httpGet` now uses curl first** (falls back to the stream wrapper). The https stream
  wrapper returns `false` on the official Joomla/PHP image even with `allow_url_fopen=1` (CA bundle),
  which broke the fiat→XMR rate fetch. Fixed in `xmr-pay-adapter-core` and re-vendored.
- **QR/poll scripts emitted inline in the body**, not via `addScriptDeclaration` (which runs them in
  the head before `#xmrpay-qr` exists, so the QR never drew).
- View strings hardcoded (no language file yet) so they don't render as raw keys.

### Scheduler note

HikaShop assumes a **web application** (it calls `setUserState()` etc.), so the settlement task runs
under Joomla's **Web Cron / lazy scheduler** (a web request), not the `cli/joomla.php scheduler:run`
console app — the CLI app lacks those methods (same class of limitation as VirtueMart's web-only
installer). The on-demand checkout poll already settles in web context; the scheduled task is the
backstop for orders whose buyer closed the tab. Document Web Cron as the supported trigger for merchants.

## Dev / test

Install into the local stack (see [../xmr-pay-joomla-dev](../xmr-pay-joomla-dev)):

```bash
cd plg_hikashoppayment_xmrpay && zip -rq ../xmrpay-hikashop.zip . -x '.*'
cd ../../xmr-pay-joomla-dev
docker compose cp ../xmr-pay-hikashop/xmrpay-hikashop.zip joomla:/tmp/x.zip
docker compose exec -T joomla sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/x.zip'
```

Then enable it and create a HikaShop payment method of type *Monero (XMR)* with the merchant's
address + view key + node(s). Use a stagenet address + node first.
