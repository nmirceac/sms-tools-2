<?php

namespace SmsTools2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SmsTools2\Client\SmsApiException;
use SmsTools2\Client\SmsClient;
use SmsTools2\Enums\MessageStatus;
use SmsTools2\Jobs\SendSmsMessage;
use SmsTools2\SmsTools;

/**
 * An outbound SMS, stored in your app's sms_messages table.
 *
 * Extend it as App\SmsMessage and optionally define any of these hooks
 * (they run on the queue, after the change has been saved):
 *
 *   public function afterMessageQueued() {}            accepted by the platform
 *   public function afterMessageDelivered() {}         delivered to the handset
 *   public function afterMessageFailed() {}            failed, rejected or expired
 *   public function afterMessageReplied($reply) {}     the recipient replied (array)
 *
 * @property int $id
 * @property string|null $gateway_ulid
 * @property int|null $gateway_id
 * @property string|null $reference
 * @property string $recipient
 * @property string $content
 * @property string|null $status
 * @property string|null $status_description
 * @property int|null $parts
 * @property bool $queued
 * @property bool $sent
 * @property bool $delivered
 * @property bool $failed
 * @property bool $replied
 * @property array<int, array<string, mixed>>|null $deliveries
 * @property array<int, array<string, mixed>>|null $replies
 * @property array<string, mixed>|null $details
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $replied_at
 * @property Carbon|null $created_at
 *
 * @phpstan-consistent-constructor
 */
class SmsMessage extends Model
{
    /** @deprecated v1 webhook types, kept for apps that referenced them. */
    public const WEBHOOK_TYPE_WEBHOOK_TEST = 1;

    public const WEBHOOK_TYPE_MESSAGE_QUEUED = 2;

    public const WEBHOOK_TYPE_DELIVERY_REPORT = 3;

    public const WEBHOOK_TYPE_REPLY = 4;

    public const WEBHOOK_TYPE_INBOUND = 5;

    protected $guarded = ['id'];

    protected $casts = [
        'recipient' => 'string',
        'gateway_id' => 'integer',
        'parts' => 'integer',
        'queued' => 'boolean',
        'sent' => 'boolean',
        'delivered' => 'boolean',
        'failed' => 'boolean',
        'replied' => 'boolean',
        'deliveries' => 'array',
        'replies' => 'array',
        'details' => 'array',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('sms-tools.messages.table', 'sms_messages');
    }

    public function getConnectionName(): ?string
    {
        return config('sms-tools.messages.connection') ?: parent::getConnectionName();
    }

    /**
     * Save a message and queue it for sending.
     *
     * @param  array<string, mixed>  $attributes  extra columns to set on the row
     */
    public static function queue(string|int $recipient, string $content, array $attributes = []): static
    {
        $message = new static;
        $message->forceFill([
            'recipient' => static::recipientForStorage($recipient),
            'content' => trim($content),
            'reference' => (string) Str::uuid(),
            'status' => MessageStatus::Pending->value,
            'queued' => false,
            'sent' => false,
            'delivered' => false,
            'failed' => false,
            'replied' => false,
            'deliveries' => [],
            'replies' => [],
            'details' => [],
        ] + $attributes);
        $message->save();

        dispatch(SmsTools::onQueue(new SendSmsMessage(static::class, $message->getKey())));

        return $message;
    }

    /**
     * Hand the message to the platform. Safe to call repeatedly: the local
     * reference makes the platform return the same message instead of sending twice.
     *
     * @throws SmsApiException
     */
    public function sendToGateway(?SmsClient $client = null): bool
    {
        if ($this->gateway_ulid !== null) {
            return true;
        }

        if ($this->reference === null) {
            $this->reference = (string) Str::uuid();
            $this->save();
        }

        $client ??= app(SmsClient::class);
        $data = $client->send((string) $this->getAttribute('recipient'), $this->content, $this->reference);

        $this->applyApiMessage($data);

        return true;
    }

    public function statusEnum(): MessageStatus
    {
        return MessageStatus::tryFrom((string) $this->status) ?? MessageStatus::Pending;
    }

    public function isFinal(): bool
    {
        return $this->statusEnum()->isFinal();
    }

    /**
     * Update the row from a platform message resource (API response or webhook).
     *
     * @param  array<string, mixed>  $data
     * @return MessageStatus|null the new status if it changed
     */
    public function applyApiMessage(array $data): ?MessageStatus
    {
        if (! empty($data['id'])) {
            $this->gateway_ulid = (string) $data['id'];
        }
        if (! empty($data['legacy_id']) && $this->gateway_id === null) {
            $this->gateway_id = (int) $data['legacy_id'];
        }
        if (isset($data['parts'])) {
            $this->parts = (int) $data['parts'];
        }
        if (! empty($data['recipient']) && ctype_digit((string) $data['recipient'])) {
            $this->setAttribute('recipient', (string) $data['recipient']);
        }

        $previous = $this->statusEnum();
        $next = MessageStatus::tryFrom((string) ($data['status'] ?? ''));
        $changed = $next !== null && $previous->canTransitionTo($next);

        if ($changed) {
            $this->status = $next->value;
            $this->status_description = isset($data['status_description']) ? (string) $data['status_description'] : null;
            $this->syncLegacyFlags($next);

            if ($next === MessageStatus::Delivered) {
                $this->delivered_at = $this->date($data['delivered_at'] ?? null) ?? now();
            }
            if ($next->isFailure()) {
                $this->failed_at = $this->date($data['failed_at'] ?? null) ?? now();
            }

            $deliveries = $this->deliveries ?? [];
            $deliveries[] = [
                'status' => $next->value,
                'description' => $this->status_description,
                'at' => ($this->date($data['delivered_at'] ?? $data['failed_at'] ?? null) ?? now())->toIso8601ZuluString(),
            ];
            $this->deliveries = $deliveries;
        }

        $this->save();

        return $changed ? $next : null;
    }

    /**
     * Record a reply (from a message.replied webhook). Returns false if it was already recorded.
     *
     * @param  array<string, mixed>  $reply
     */
    public function addReply(array $reply): bool
    {
        $replies = $this->replies ?? [];
        foreach ($replies as $existing) {
            if (isset($existing['id'], $reply['id']) && $existing['id'] === $reply['id']) {
                return false;
            }
        }

        $replies[] = $reply;
        $this->replies = $replies;
        $this->replied = true;
        $this->replied_at ??= $this->date($reply['received_at'] ?? null) ?? now();
        $this->save();

        return true;
    }

    /** Mark as failed locally (the platform rejected the request). */
    public function markFailed(string $reason): void
    {
        $this->status = MessageStatus::Failed->value;
        $this->status_description = mb_substr($reason, 0, 255);
        $this->failed_at = now();
        $this->syncLegacyFlags(MessageStatus::Failed);
        $this->save();
    }

    /** Keep the sms-tools 0.x boolean columns meaningful. */
    protected function syncLegacyFlags(MessageStatus $status): void
    {
        $this->queued = ! in_array($status, [MessageStatus::Pending, MessageStatus::Expired], true);
        $this->sent = in_array($status, [MessageStatus::Sent, MessageStatus::Delivered], true) || ($this->sent && $status !== MessageStatus::Expired);
        $this->delivered = $status === MessageStatus::Delivered;
        $this->failed = $status->isFailure();
    }

    /**
     * Parse a platform time (UTC, "…Z") into the app's timezone. Eloquent stores a date's
     * clock time as-is, so without this a non-UTC app would store UTC times.
     */
    protected function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? SmsTools::localTime($value) : null;
    }

    /** Digits only (the platform normalises the number and returns it in E.164). */
    protected static function recipientForStorage(string|int $recipient): string
    {
        $value = trim((string) $recipient);

        return str_starts_with($value, '+') ? substr((string) preg_replace('/[^\d+]/', '', $value), 1) : (string) preg_replace('/\D/', '', $value);
    }

    /**
     * Register the webhook URL with the platform (kept for sms-tools 0.x compatibility;
     * prefer `php artisan smstools2:setup`).
     *
     * @return array{test: bool, url: string|null}
     */
    public static function setWebhook(?string $url = null): array
    {
        $client = app(SmsClient::class);
        $settings = $client->updateWebhook($url ?? route((string) config('sms-tools.webhook.route.name')));

        return ['test' => $client->testWebhook()['ok'], 'url' => $settings['url']];
    }

    /**
     * @deprecated The platform now handles Unicode and GSM-7 encoding itself; content is
     *             no longer stripped to ASCII when sending. Kept for apps that call it directly.
     */
    public static function cleanContent(string $content): string
    {
        return trim((string) iconv((string) mb_detect_encoding($content), 'ASCII//IGNORE//TRANSLIT', trim($content)));
    }
}
