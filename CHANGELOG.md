# Changelog

## 0.0.5 (2026-11-05), pre-release

- Fix: `delivered_at`, `failed_at`, `replied_at` and inbound `created_at` were stored in UTC clock time. The platform sends UTC; they are now converted to the app's timezone first, like Eloquent's own timestamps. Apps on UTC are unaffected; on SAST, rows written by 0.0.3/0.0.4 are 2 hours early.

## 0.0.4 (2026-11-05), pre-release

- Require `guzzlehttp/guzzle` (^7.5 or ^8). Laravel 10's HTTP client only suggests it, so a Laravel 10 app without Guzzle could not send.
- The dist archive no longer includes tests and tooling files.

## 0.0.3 (2026-11-05), pre-release

First release, successor of `nmirceac/sms-tools` 0.x:
- Talks to API v2 (bearer token), not v1.
- Idempotent sending: each row's `reference` is the platform's idempotency key.
- Webhooks: HMAC-SHA256 signatures, timestamp tolerance, de-duplication by event id.
- Status column plus the 0.x boolean flags; `gateway_ulid`; matching by legacy id.
- New hook `afterMessageFailed()`; Laravel events; `Sms` facade and `Sms::fake()`.
- `smstools2:setup`, `smstools2:send`, `smstools2:sync`.
- Accepts the old v1 webhook format during the switch-over (optional).
- Fixes: inbound connection/table no longer hard-coded; content no longer stripped to ASCII.
