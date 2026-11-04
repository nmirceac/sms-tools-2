<?php

namespace SmsTools2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SmsTools2\Client\SmsApiException;
use SmsTools2\Client\SmsClient;
use SmsTools2\Models\SmsMessage;
use Throwable;

/**
 * Sends one queued SmsMessage. Retries are safe: the message's reference is the
 * platform's idempotency key, so a retry after a timeout never sends twice.
 */
class SendSmsMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @param class-string<SmsMessage> $modelClass */
    public function __construct(public string $modelClass, public int|string $messageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(SmsClient $client): void
    {
        $message = $this->modelClass::query()->find($this->messageId);

        if (! $message instanceof SmsMessage || $message->gateway_ulid !== null) {
            return;
        }

        try {
            $message->sendToGateway($client);
        } catch (SmsApiException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            // Rejected by the platform (invalid number, suspended application, bad token...).
            $message->markFailed($e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->modelClass::query()->find($this->messageId);

        if ($message instanceof SmsMessage && $message->gateway_ulid === null) {
            $message->markFailed('Not sent: '.($exception?->getMessage() ?? 'unknown error'));
        }
    }
}
