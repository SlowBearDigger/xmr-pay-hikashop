# Changelog

## [1.1.0] - 2026-11-23

- Reopen a pending payment from the thank-you page with its original address and quote.
- Show the remaining amount in the QR code and wallet link after a confirmed partial payment. Hide payment instructions while confirmations are pending or the order is cancelled.
- Save scan progress between checks and wait for each status request to finish before starting another.
- Recheck saved scan overlap after outages before crediting cached matches.
- Lock the pending order while claiming its transaction and updating native status, preserving concurrent cancellations and rolling back failed updates.

- Bundle PHP engine 0.2.0 and adapter core 0.2.1.
- Include complete-node evidence checks, bounded responses and hardened rate fetching.
- Align payment and scheduler plugin versions. Enable both plugins and validate checkout and Web Cron on staging before upgrading production.
