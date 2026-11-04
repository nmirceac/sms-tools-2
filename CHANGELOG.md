# Changelog

## 1.0.0 (unreleased)

First release, successor of `nmirceac/sms-tools` 0.x:
- Talks to API v2 (bearer token), not v1.
- Idempotent sending: each row's `reference` is the platform's idempotency key.
- Webhooks: HMAC-SHA256 signatures, timestamp tolerance, de-duplication by event id.
- Status column plus the 0.x boolean flags; `gateway_ulid`; matching by legacy id.
- New hook `afterMessageFailed()`; Laravel events; `Sms` facade and `Sms::fake()`.
- `smstools2:setup`, `smstools2:send`, `smstools2:sync`.
- Accepts the old v1 webhook format during the switch-over (optional).
- Fixes: inbound connection/table no longer hard-coded; content no longer stripped to ASCII.
