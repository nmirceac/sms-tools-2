<?php

namespace SmsTools2\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SmsTools2\Tests\Fixtures\TestSmsMessage;
use SmsTools2\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_setup_registers_the_webhook_and_tests_it(): void
    {
        Http::fake([
            'sms.test/api/v2/webhook/test' => Http::response(['data' => ['ok' => true, 'status' => 200, 'error' => null]]),
            'sms.test/api/v2/webhook' => fn (Request $r) => Http::response(['data' => [
                'url' => $r->method() === 'PUT' ? $r['url'] : null, 'secret' => self::SECRET, 'events' => ['message.delivered'],
            ]]),
        ]);

        $this->artisan('smstools2:setup')
            ->expectsOutputToContain('API token OK')
            ->expectsOutputToContain('Webhook URL set to http://localhost/sms-tools/webhook')
            ->expectsOutputToContain('secret matches')
            ->expectsOutputToContain('Test event delivered')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['url'] === 'http://localhost/sms-tools/webhook');
    }

    public function test_setup_shows_a_different_secret_and_reports_a_failing_test(): void
    {
        Http::fake([
            'sms.test/api/v2/webhook/test' => Http::response(['data' => ['ok' => false, 'status' => 500, 'error' => 'HTTP 500']]),
            'sms.test/api/v2/webhook' => Http::response(['data' => ['url' => 'http://localhost/sms-tools/webhook', 'secret' => 'whsec_other', 'events' => []]]),
        ]);

        $this->artisan('smstools2:setup')
            ->expectsOutputToContain('SMS_TOOLS_WEBHOOK_SECRET=whsec_other')
            ->expectsOutputToContain('Test event failed')
            ->assertFailed();
    }

    public function test_setup_reports_a_bad_token(): void
    {
        Http::fake(['sms.test/*' => Http::response(['error' => ['message' => 'Unauthenticated. Send a valid bearer token.', 'status' => 401]], 401)]);

        $this->artisan('smstools2:setup')->expectsOutputToContain('Unauthenticated')->assertFailed();
    }

    public function test_sync_refreshes_unfinished_messages(): void
    {
        $message = TestSmsMessage::query()->create(['recipient' => '27821234567', 'content' => 'Hi', 'gateway_ulid' => '01kabc0000000000000000000a',
            'status' => 'submitted', 'deliveries' => [], 'replies' => [], 'details' => []]);
        Http::fake(['sms.test/api/v2/messages/*' => Http::response(['data' => $this->apiMessage(['status' => 'delivered', 'delivered_at' => '2026-11-04T10:00:03Z'])])]);

        $this->artisan('smstools2:sync')->expectsOutputToContain('Updated 1 message(s)')->assertSuccessful();

        $this->assertSame('delivered', $message->refresh()->status);
        $this->assertSame([['delivered', $message->id]], TestSmsMessage::$hooks);
    }

    public function test_send_now(): void
    {
        Http::fake(['sms.test/*' => Http::response(['data' => $this->apiMessage(), 'created' => true], 202)]);

        $this->artisan('smstools2:send', ['recipient' => '0821234567', 'message' => 'Hi', '--now' => true])
            ->expectsOutputToContain('platform id 01kabc0000000000000000000a')
            ->assertSuccessful();
    }
}
