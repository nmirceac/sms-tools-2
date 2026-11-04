<?php

namespace SmsTools2\Facades;

use Illuminate\Support\Facades\Facade;
use SmsTools2\Client\SmsClient;
use SmsTools2\Testing\FakeSmsClient;

/**
 * @method static array<string, mixed> send(string $recipient, string $content, ?string $reference = null)
 * @method static array<int, array<string, mixed>> sendBatch(array<int, array<string, mixed>> $messages)
 * @method static array<string, mixed> message(string $id)
 * @method static array{data: list<array<string, mixed>>, next_cursor: string|null} messages(array<string, mixed> $filters = [])
 * @method static array{data: list<array<string, mixed>>, next_cursor: string|null} inbound(array<string, mixed> $filters = [])
 * @method static array{url: string|null, secret: string, events: list<string>} webhook()
 * @method static array{url: string|null, secret: string, events: list<string>} updateWebhook(?string $url, bool $rotateSecret = false)
 * @method static array{ok: bool, status: int|null, error: string|null} testWebhook()
 *
 * @see SmsClient
 */
class Sms extends Facade
{
    /** Replace the API client with an in-memory fake (for your app's tests). */
    public static function fake(): FakeSmsClient
    {
        $fake = new FakeSmsClient;
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return SmsClient::class;
    }
}
