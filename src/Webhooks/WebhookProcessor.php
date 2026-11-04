<?php

namespace SmsTools2\Webhooks;

use Illuminate\Support\Facades\Log;
use SmsTools2\Enums\MessageStatus;
use SmsTools2\Events\SmsInboundReceived;
use SmsTools2\Events\SmsMessageReplied;
use SmsTools2\Events\SmsMessageStatusUpdated;
use SmsTools2\Jobs\RunHook;
use SmsTools2\Models\SmsMessage;
use SmsTools2\SmsTools;

/**
 * Applies a (verified) v2 webhook event to the app's models, then queues the
 * afterMessage* hook and fires the matching Laravel event.
 *
 * Event: { id, type, created_at, application: {id, name}, data: {...} }
 */
class WebhookProcessor
{
    /** @param array<string, mixed> $event */
    public function process(array $event): string
    {
        $data = (array) ($event['data'] ?? []);

        return match ((string) ($event['type'] ?? '')) {
            'webhook.test' => 'tested',
            'inbound.received' => $this->inbound($data),
            'message.replied' => $this->reply($data),
            'message.submitted', 'message.sent', 'message.delivered',
            'message.failed', 'message.rejected', 'message.expired' => $this->status($data),
            default => 'ignored',
        };
    }

    /** @param array<string, mixed> $data platform message resource */
    public function status(array $data): string
    {
        $message = $this->findMessage($data);
        if ($message === null) {
            Log::info('sms-tools-2: webhook for an unknown message', ['id' => $data['id'] ?? null, 'legacy_id' => $data['legacy_id'] ?? null]);

            return 'unknown';
        }

        $previous = $message->statusEnum();
        $status = $message->applyApiMessage($data);
        if ($status === null) {
            return 'unchanged';
        }

        $hook = match (true) {
            $status === MessageStatus::Submitted => 'afterMessageQueued',
            $status === MessageStatus::Delivered => 'afterMessageDelivered',
            $status->isFailure() => 'afterMessageFailed',
            default => null,
        };

        if ($hook !== null) {
            $this->hook($message, $hook);
        }

        event(new SmsMessageStatusUpdated($message, $status, $previous));

        return 'updated';
    }

    /** @param array<string, mixed> $data platform inbound resource, with "message" */
    protected function reply(array $data): string
    {
        $message = $this->findMessage((array) ($data['message'] ?? []));
        if ($message === null) {
            return 'unknown';
        }

        // Both the v2 field names and the sms-tools 0.x ones (content/sender/recipient/created_at).
        $reply = [
            'id' => $data['id'] ?? null,
            'content' => (string) ($data['content'] ?? ''),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'sender' => $data['from'] ?? null,
            'recipient' => $data['to'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'created_at' => $data['received_at'] ?? null,
        ];

        if (! $message->addReply($reply)) {
            return 'duplicate';
        }

        $this->hook($message, 'afterMessageReplied', [$reply]);
        event(new SmsMessageReplied($message, $reply));

        return 'replied';
    }

    /** @param array<string, mixed> $data platform inbound resource */
    protected function inbound(array $data): string
    {
        $class = SmsTools::inboundModel();
        $inbound = $class::createFromApi($data);
        if ($inbound === null) {
            return 'duplicate';
        }

        $this->hook($inbound, 'afterMessageReceived');
        event(new SmsInboundReceived($inbound));

        return 'received';
    }

    /**
     * Match by platform id, then by our reference, then by the legacy (v1) id
     * that sms-tools 0.x stored in gateway_id.
     *
     * @param  array<string, mixed>  $data
     */
    public function findMessage(array $data): ?SmsMessage
    {
        $class = SmsTools::messageModel();
        $query = fn () => $class::query();

        if (! empty($data['id']) && ($m = $query()->where('gateway_ulid', (string) $data['id'])->first())) {
            return $m;
        }
        if (! empty($data['reference']) && ($m = $query()->where('reference', (string) $data['reference'])->first())) {
            return $m;
        }
        if (! empty($data['legacy_id']) && ($m = $query()->where('gateway_id', (int) $data['legacy_id'])->first())) {
            return $m;
        }

        return null;
    }

    /** @param list<mixed> $arguments */
    protected function hook(object $model, string $hook, array $arguments = []): void
    {
        if (method_exists($model, $hook) && method_exists($model, 'getKey')) {
            dispatch(SmsTools::onQueue(new RunHook($model::class, $model->getKey(), $hook, $arguments)));
        }
    }
}
