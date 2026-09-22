# Changelog

## [1.1.0] - Unreleased

- Recheck saved scan overlap after outages before crediting cached matches.
- Lock the pending order while claiming its transaction and updating native status, preserving concurrent cancellations and rolling back failed updates.

- Bundle the reviewed PHP engine and shared adapter core at the 0.2.0 release line.
- Include complete-node evidence checks, bounded responses and hardened rate fetching.
- Align payment and scheduler plugin versions. Enable both plugins and validate checkout and Web Cron on staging before upgrading production.
