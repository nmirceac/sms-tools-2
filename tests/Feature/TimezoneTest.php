<?php

namespace SmsTools2\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SmsTools2\Tests\Fixtures\TestSmsInbound;
use SmsTools2\Tests\Fixtures\TestSmsMessage;
use SmsTools2\Tests\TestCase;

/**
 * The platform sends UTC times ("…Z"). Apps whose timezone isn't UTC must store them in
 * their own timezone, the same way Eloquent stores created_at/updated_at.
 */
class TimezoneTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Africa/Johannesburg']);
        date_default_timezone_set('Africa/Johannesburg');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    private function stored(string $table, string $column): string
    {
        return (string) DB::table($table)->value($column);
    }

    public function test_status_and_reply_times_are_stored_in_the_app_timezone(): void
    {
        $message = TestSmsMessage::query()->create([
            'recipient' => '27821234567', 'content' => 'Hello', 'reference' => 'ref-tz',
            'gateway_ulid' => '01kabc0000000000000000000a', 'status' => 'submitted',
            'deliveries' => [], 'replies' => [], 'details' => [],
        ]);
        $table = $message->getTable();

        $this->postWebhook(['type' => 'message.delivered', 'data' => $this->apiMessage(['status' => 'delivered', 'delivered_at' => '2026-11-05T17:15:41Z'])])
            ->assertJson(['result' => 'updated']);
        $this->assertSame('2026-11-05 19:15:41', $this->stored($table, 'delivered_at'));

        $this->postWebhook(['type' => 'message.replied', 'data' => ['id' => 5, 'type' => 'reply', 'message_id' => $message->gateway_ulid,
            'from' => '27821234567', 'to' => '27600000000', 'content' => 'Thanks', 'received_at' => '2026-11-05T17:20:00Z',
            'message' => $this->apiMessage(['status' => 'delivered'])]])->assertJson(['result' => 'replied']);
        $this->assertSame('2026-11-05 19:20:00', $this->stored($table, 'replied_at'));

        // The delivery history keeps explicit UTC ("Z") times.
        $this->assertSame('2026-11-05T17:15:41Z', $message->refresh()->deliveries[0]['at']);
    }

    public function test_failure_time_is_stored_in_the_app_timezone(): void
    {
        $message = TestSmsMessage::query()->create([
            'recipient' => '27821234567', 'content' => 'Hello', 'reference' => 'ref-tz2',
            'gateway_ulid' => '01kabc0000000000000000000a', 'status' => 'submitted',
            'deliveries' => [], 'replies' => [], 'details' => [],
        ]);

        $this->postWebhook(['type' => 'message.failed', 'data' => $this->apiMessage(['status' => 'failed', 'failed_at' => '2026-11-05T22:30:00Z'])])
            ->assertJson(['result' => 'updated']);

        $this->assertSame('2026-11-06 00:30:00', $this->stored($message->getTable(), 'failed_at'));
    }

    public function test_inbound_received_time_is_stored_in_the_app_timezone(): void
    {
        $this->postWebhook(['type' => 'inbound.received', 'data' => ['id' => 9000124, 'type' => 'inbound', 'message_id' => null,
            'from' => '27829999999', 'to' => '27600000000', 'content' => 'STOP', 'received_at' => '2026-11-05T17:15:41Z']])
            ->assertJson(['result' => 'received']);

        $this->assertSame('2026-11-05 19:15:41', $this->stored((new TestSmsInbound)->getTable(), 'created_at'));
    }
}
