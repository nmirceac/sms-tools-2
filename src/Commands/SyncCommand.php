<?php

namespace SmsTools2\Commands;

use Illuminate\Console\Command;
use SmsTools2\Client\SmsApiException;
use SmsTools2\Client\SmsClient;
use SmsTools2\Enums\MessageStatus;
use SmsTools2\Jobs\SendSmsMessage;
use SmsTools2\SmsTools;
use SmsTools2\Webhooks\WebhookProcessor;

/**
 * Safety net for missed webhooks: refreshes recent messages that are not final
 * yet, and re-queues messages that never reached the platform. Can be scheduled
 * (e.g. hourly).
 */
class SyncCommand extends Command
{
    protected $signature = 'smstools2:sync {--days=3 : Only messages created in the last N days} {--limit=500}';

    protected $description = 'Refresh the status of recent, unfinished SMS messages from the platform';

    public function handle(SmsClient $client, WebhookProcessor $processor): int
    {
        $class = SmsTools::messageModel();
        $since = now()->subDays((int) $this->option('days'));
        $final = array_map(fn (MessageStatus $s) => $s->value, array_filter(MessageStatus::cases(), fn (MessageStatus $s) => $s->isFinal()));

        $requeued = 0;
        $class::query()->whereNull('gateway_ulid')->whereNull('gateway_id')
            ->where('status', MessageStatus::Pending->value)
            ->where('created_at', '>=', $since)->where('created_at', '<', now()->subMinutes(10))
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function ($message) use (&$requeued) {
                dispatch(SmsTools::onQueue(new SendSmsMessage($message::class, $message->getKey())));
                $requeued++;
            });

        $updated = 0;
        $class::query()->whereNotNull('gateway_ulid')->whereNotIn('status', $final)
            ->where('created_at', '>=', $since)
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function ($message) use ($client, $processor, &$updated) {
                try {
                    if ($processor->status($client->message((string) $message->gateway_ulid)) === 'updated') {
                        $updated++;
                    }
                } catch (SmsApiException $e) {
                    $this->components->warn("#{$message->getKey()}: {$e->getMessage()}");
                }
            });

        $this->components->info("Updated {$updated} message(s), re-queued {$requeued}.");

        return self::SUCCESS;
    }
}
