<?php

namespace SmsTools2\Webhooks;

use Illuminate\Http\Request;
use SmsTools2\Enums\MessageStatus;
use SmsTools2\Jobs\RunHook;
use SmsTools2\SmsTools;

/**
 * Transition support: accepts the old platform's v1 webhooks
 * ({type: int, payload: {message: {...}}, signature: md5}) for messages that
 * were sent before the switch, so the old sms-tools package can be removed.
 * Enabled only when sms-tools.legacy.key and .secret are set.
 */
class LegacyWebhookProcessor
{
    public static function enabled(): bool
    {
        return filled(config('sms-tools.legacy.key')) && filled(config('sms-tools.legacy.secret'));
    }

    public static function looksLegacy(Request $request): bool
    {
        return is_numeric($request->input('type')) && $request->has('signature') && $request->has('payload');
    }

    /** Same (weak) scheme the old platform uses: md5(key---trimmed json---secret). */
    public static function signatureIsValid(Request $request): bool
    {
        $expected = md5(config('sms-tools.legacy.key').'---'.trim((string) json_encode($request->input('payload')), '{}').'---'.config('sms-tools.legacy.secret'));

        return hash_equals($expected, (string) $request->input('signature'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, bool>
     */
    public function process(array $body): array
    {
        $type = (int) ($body['type'] ?? 0);
        $message = (array) ($body['payload']['message'] ?? []);

        return match ($type) {
            1 => ['tested' => true],
            2, 3, 4 => ['success' => $this->update($message, $type)],
            5 => ['success' => $this->inbound($message)],
            default => ['success' => false],
        };
    }

    /** @param array<string, mixed> $payload */
    protected function update(array $payload, int $type): bool
    {
        $class = SmsTools::messageModel();
        $message = $class::query()->where('gateway_id', (int) ($payload['id'] ?? 0))->first();
        if ($message === null) {
            return false;
        }

        $status = match (true) {
            (bool) ($payload['delivered'] ?? false) => 'delivered',
            (bool) ($payload['failed'] ?? false) => 'failed',
            (bool) ($payload['sent'] ?? false) => 'sent',
            (bool) ($payload['queued'] ?? false) => 'submitted',
            default => null,
        };

        $replies = (array) ($payload['replies'] ?? []);
        $message->forceFill([
            'sent' => (bool) ($payload['sent'] ?? $message->sent),
            'delivered' => (bool) ($payload['delivered'] ?? $message->delivered),
            'failed' => (bool) ($payload['failed'] ?? $message->failed),
            'replied' => (bool) ($payload['replied'] ?? $message->replied),
            'deliveries' => (array) ($payload['deliveries'] ?? $message->deliveries ?? []),
            'replies' => $replies,
            'details' => ['sender' => $payload['sender'] ?? null],
            'delivered_at' => $payload['delivered_at'] ?? $message->delivered_at,
        ]);
        if ($status !== null && $message->statusEnum()->canTransitionTo(MessageStatus::from($status))) {
            $message->status = $status;
        }
        $message->save();

        $hook = [2 => 'afterMessageQueued', 3 => 'afterMessageDelivered', 4 => 'afterMessageReplied'][$type];
        $arguments = $type === 4 && $replies !== [] ? [end($replies)] : [];
        if (method_exists($message, $hook)) {
            dispatch(SmsTools::onQueue(new RunHook($message::class, $message->getKey(), $hook, $arguments)));
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    protected function inbound(array $payload): bool
    {
        $class = SmsTools::inboundModel();
        $inbound = $class::createFromApi([
            'id' => $payload['id'] ?? null,
            'from' => $payload['sender'] ?? '',
            'to' => $payload['recipient'] ?? '',
            'content' => $payload['content'] ?? '',
            'received_at' => $payload['created_at'] ?? null,
        ] + $payload);

        if ($inbound !== null && method_exists($inbound, 'afterMessageReceived')) {
            dispatch(SmsTools::onQueue(new RunHook($inbound::class, $inbound->getKey(), 'afterMessageReceived')));
        }

        return $inbound !== null;
    }
}
