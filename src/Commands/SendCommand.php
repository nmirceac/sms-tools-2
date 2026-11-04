<?php

namespace SmsTools2\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SmsTools2\Client\SmsApiException;
use SmsTools2\SmsTools;

class SendCommand extends Command
{
    protected $signature = 'smstools2:send {recipient} {message} {--now : Send immediately instead of via the queue}';

    protected $description = 'Send an SMS (stored in sms_messages like any other)';

    public function handle(): int
    {
        $class = SmsTools::messageModel();

        if (! $this->option('now')) {
            $message = $class::queue((string) $this->argument('recipient'), (string) $this->argument('message'));
            $this->components->info("Queued message #{$message->getKey()}");

            return self::SUCCESS;
        }

        $message = new $class;
        $message->forceFill([
            'recipient' => preg_replace('/\D/', '', (string) $this->argument('recipient')),
            'content' => (string) $this->argument('message'),
            'reference' => (string) Str::uuid(),
            'status' => 'pending',
            'deliveries' => [], 'replies' => [], 'details' => [],
        ])->save();

        try {
            $message->sendToGateway();
        } catch (SmsApiException $e) {
            $message->markFailed($e->getMessage());
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Sent #{$message->getKey()} (platform id {$message->gateway_ulid}, status {$message->status})");

        return self::SUCCESS;
    }
}
