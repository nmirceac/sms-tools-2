# sms-tools-2

Laravel client for the **WeAnswer SMS Toolkit** (API v2). It lets your app:
- Send SMS.
- Track delivery.
- Receive replies and inbound SMS through signed webhooks.

It keeps your app's own `sms_messages` / `sms_inbound` tables up to date and runs your `afterMessage*` hooks.

It replaces [`nmirceac/sms-tools`](https://packagist.org/packages/nmirceac/sms-tools) 0.x, which used API v1. Apps written for 0.x keep working after a small upgrade (see [Upgrading from sms-tools 0.x](#upgrading-from-sms-tools-0x)).

- PHP 8.1+, Laravel 10, 11, 12 and 13
- Idempotent sending: a retry never sends the same SMS twice
- Webhooks verified with HMAC-SHA256 and de-duplicated
- Unicode and emoji are sent as written; the platform picks GSM-7 or UCS-2 itself

## Installation

```bash
composer require nmirceac/sms-tools-2
php artisan vendor:publish --tag=sms-tools-2-models       # app/SmsMessage.php, app/SmsInbound.php
php artisan vendor:publish --tag=sms-tools-2-migrations   # new installs only
php artisan migrate
```

Add to `.env`. The token comes from the portal, under **Applications → your application → API tokens**:

```dotenv
SMS_TOOLS_API_URL=https://sms.weanswer.it/api/v2
SMS_TOOLS_API_TOKEN=1|sms_xxxxxxxxxxxxxxxxxxxxxxxx
```

Then register your webhook URL and save the signing secret:

```bash
php artisan smstools2:setup --write-env
```

This command:
1. Checks the token.
2. Points the platform at `https://your-app/sms-tools/webhook`.
3. Writes `SMS_TOOLS_WEBHOOK_SECRET` to `.env`.
4. Sends a signed test event.

The webhook URL must be reachable from the platform over HTTPS.

Sending and the hooks run as queued jobs, so you need a queue worker. With `QUEUE_CONNECTION=sync` they run inline.

## Sending

```php
use App\SmsMessage;

$message = SmsMessage::queue('0821234567', 'Your appointment is confirmed for 10:00.');
// Saved to sms_messages (status "pending") and sent by a queued job.

// Extra columns, if you added any:
SmsMessage::queue('+27821234567', 'Hi!', ['client_id' => 42]);
```

Numbers can be in national (`082…`) or international (`27…`, `+27…`) format. The platform normalises them, and the row is updated with the E.164 number.

**Statuses:**

| Status | Meaning |
|---|---|
| `pending` | Saved locally, not yet accepted by the platform |
| `submitted` | Accepted by the platform |
| `sent` | Accepted by the mobile operator |
| `delivered` | Delivered to the handset |
| `failed` | Not delivered |
| `rejected` | Refused by the operator |
| `expired` | Never sent: it stayed queued too long on the platform |

The 0.x boolean columns (`queued`, `sent`, `delivered`, `failed`, `replied`) are still kept in sync.

```php
$message->status;            // "delivered"
$message->statusEnum();      // SmsTools2\Enums\MessageStatus::Delivered
$message->isFinal();         // true
$message->delivered_at;      // Carbon
$message->replies;           // [['id' => .., 'content' => .., 'sender' => .., 'received_at' => ..], ...]
```

### Using the API directly

```php
use SmsTools2\Facades\Sms;

Sms::send('0821234567', 'Hello', reference: 'invoice-1001');   // idempotent per reference
Sms::message($ulid);                                            // includes status history and replies
Sms::messages(['status' => 'failed', 'from' => '2026-11-01']);  // ['data' => [...], 'next_cursor' => ...]
Sms::inbound(['type' => 'reply']);
Sms::webhook(); Sms::updateWebhook($url); Sms::testWebhook();
```

Errors throw `SmsTools2\Client\SmsApiException`, which carries `$status` and `$errors` (validation messages) and has `isRetryable()`.

## Hooks and events

Define any of these on `App\SmsMessage` / `App\SmsInbound`. They run on the queue after the change is saved:

```php
class SmsMessage extends \SmsTools2\Models\SmsMessage
{
    public function afterMessageQueued() {}          // accepted by the platform
    public function afterMessageDelivered() {}       // delivered
    public function afterMessageFailed() {}          // failed, rejected or expired (new in 2.x)
    public function afterMessageReplied($reply) {}   // ['content', 'sender', 'recipient', 'received_at', ...]
}

class SmsInbound extends \SmsTools2\Models\SmsInbound
{
    public function afterMessageReceived() {}        // an unsolicited SMS to one of your numbers
}
```

You can also listen for these Laravel events instead:
- `SmsTools2\Events\SmsMessageStatusUpdated` (`$message`, `$status`, `$previous`)
- `SmsMessageReplied` (`$message`, `$reply`)
- `SmsInboundReceived` (`$inbound`)

## Webhooks

The platform POSTs events to `/sms-tools/webhook`.

**How they're verified:**
- `X-Sms-Signature` must equal `hex(HMAC-SHA256(raw body, SMS_TOOLS_WEBHOOK_SECRET))`.
- `X-Sms-Timestamp` must be within 5 minutes.
- Each event id is processed only once.

**Responses:**
- Events for messages this app doesn't know get a 200 (and a log line).
- If processing throws, the platform retries with back-off.

**Route options:** you can change the path, name and middleware in `config/sms-tools.php` (publish it with `--tag=sms-tools-2-config`). The route deliberately sits outside the `web` group: no CSRF, no session.

## Missed webhooks

`php artisan smstools2:sync` re-checks recent messages that aren't final yet, and re-queues messages that never reached the platform. You can schedule it as a safety net:

```php
Schedule::command('smstools2:sync')->hourly();
```

## Testing your app

```php
use SmsTools2\Facades\Sms;

$fake = Sms::fake();
App\SmsMessage::queue('0821234567', 'Hello');
$fake->assertSent(fn (array $m) => $m['content'] === 'Hello');
```

## Upgrading from sms-tools 0.x

1. **Swap the package.** Run `composer require nmirceac/sms-tools-2`, then `composer remove nmirceac/sms-tools` once you've switched. Both can be installed side by side; they use different namespaces, config files and routes.
2. **Change your models' parent classes.** Existing hooks keep working unchanged.
   ```php
   class SmsMessage extends \SmsTools2\Models\SmsMessage   // was \SmsTools\SmsMessage
   class SmsInbound extends \SmsTools2\Models\SmsInbound   // was \SmsTools\SmsInbound
   ```
3. **Upgrade the table.** This only adds columns (`gateway_ulid`, `reference`, `status`, …) and derives `status` from the old flags. Existing rows are kept.
   ```bash
   php artisan vendor:publish --tag=sms-tools-2-upgrade
   php artisan migrate
   ```
4. **Replace the env variables.**

   | sms-tools 0.x | sms-tools-2 |
   |---|---|
   | `SMS_API_ENDPOINT` | `SMS_TOOLS_API_URL` |
   | `SMS_API_KEY`, `SMS_API_SECRET` | `SMS_TOOLS_API_TOKEN` (a new token from the portal) |
   | — | `SMS_TOOLS_WEBHOOK_SECRET` (written by `smstools2:setup --write-env`) |

5. **Register the new webhook:** `php artisan smstools2:setup --write-env`. The URL changes from `/smstools/webhook` to `/sms-tools/webhook`.
6. **Optional, during the switch-over:** messages sent through the old platform may still receive old-style (md5-signed) webhooks for a while. Set `SMS_TOOLS_LEGACY_API_KEY` / `SMS_TOOLS_LEGACY_API_SECRET` to your old key and secret to accept them on the new route. Remove them once the old platform is retired.

**What else changed:**
- **Old messages still match.** Rows sent under 0.x stay linked: the platform reports their old id as `legacy_id`, which is matched against your `gateway_id`.
- **`SmsInbound` table:** it no longer forces a hard-coded connection or table. If your inbound SMS live elsewhere, set `SMS_TOOLS_INBOUND_CONNECTION` / `SMS_TOOLS_INBOUND_TABLE`. The 0.x package used `sqlsrv3` / `Communications.dbo.sms_messages`.
- **No more ASCII stripping:** content is sent as written. `SmsMessage::cleanContent()` still exists (deprecated) if you relied on it.
- **`afterMessageQueued` timing:** it now fires when the platform accepts the message (`message.submitted`).

## Development

```bash
composer install
vendor/bin/phpunit                    # SQLite in memory
SMS_TOOLS_TEST_DB=mysql DB_DATABASE=testing DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
