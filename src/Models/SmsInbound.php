<?php

namespace SmsTools2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An unsolicited inbound SMS (not a reply to one of your messages).
 * Extend it as App\SmsInbound and optionally define:
 *
 *   public function afterMessageReceived() {}
 *
 * Connection and table come from config (sms-tools.inbound.*); sms-tools 0.x
 * had these hard-coded.
 *
 * @property int $id
 * @property int|null $gateway_id
 * @property string $content
 * @property string $recipient
 * @property string $sender
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 *
 * @phpstan-consistent-constructor
 */
class SmsInbound extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'gateway_id' => 'integer',
        'recipient' => 'string',
        'sender' => 'string',
        'details' => 'array',
    ];

    public function getTable(): string
    {
        return (string) config('sms-tools.inbound.table', 'sms_inbound');
    }

    public function getConnectionName(): ?string
    {
        return config('sms-tools.inbound.connection') ?: parent::getConnectionName();
    }

    /**
     * Create from a platform inbound resource (v2 "inbound.received" data).
     * Returns null if it was already stored.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromApi(array $data): ?static
    {
        $gatewayId = isset($data['id']) ? (int) $data['id'] : null;

        if ($gatewayId !== null && static::query()->where('gateway_id', $gatewayId)->exists()) {
            return null;
        }

        $inbound = new static;
        $inbound->forceFill([
            'gateway_id' => $gatewayId,
            'recipient' => (string) ($data['to'] ?? ''),
            'sender' => (string) ($data['from'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'details' => $data,
        ]);
        if (! empty($data['received_at'])) {
            $inbound->created_at = Carbon::parse((string) $data['received_at']);
        }
        $inbound->save();

        return $inbound;
    }
}
