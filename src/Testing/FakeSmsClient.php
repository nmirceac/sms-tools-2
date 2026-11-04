<?php

namespace SmsTools2\Testing;

use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use SmsTools2\Client\SmsClient;

/**
 * In-memory API client for tests: Sms::fake(); ... Sms::assertSent(fn ($m) => ...).
 * Sending the same reference twice returns the same message, like the platform.
 */
class FakeSmsClient extends SmsClient
{
    /** @var array<string, array<string, mixed>> sent messages keyed by id */
    public array $sent = [];

    public function __construct()
    {
        parent::__construct('https://sms.fake/api/v2', 'fake-token');
    }

    public function send(string $recipient, string $content, ?string $reference = null): array
    {
        foreach ($this->sent as $message) {
            if ($reference !== null && $message['reference'] === $reference) {
                return $message + ['created' => false];
            }
        }

        $id = strtolower((string) Str::ulid());
        $this->sent[$id] = [
            'id' => $id,
            'legacy_id' => null,
            'reference' => $reference,
            'recipient' => preg_replace('/\D/', '', $recipient),
            'content' => $content,
            'parts' => 1,
            'status' => 'pending',
            'status_description' => null,
            'created_at' => now()->toIso8601ZuluString(),
        ];

        return $this->sent[$id] + ['created' => true];
    }

    public function message(string $id): array
    {
        return $this->sent[$id] ?? [];
    }

    /** @param (Closure(array<string, mixed>): bool)|null $callback */
    public function assertSent(?Closure $callback = null, int $times = 1): void
    {
        $matching = array_filter($this->sent, $callback ?? fn () => true);
        Assert::assertCount($times, $matching, 'Unexpected number of SMS messages sent.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'SMS messages were sent unexpectedly.');
    }
}
