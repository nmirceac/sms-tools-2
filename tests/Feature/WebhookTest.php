<?php

namespace SmsTools2\Tests\Feature;

use Illuminate\Support\Facades\Event;
use SmsTools2\Events\SmsInboundReceived;
use SmsTools2\Events\SmsMessageReplied;
use SmsTools2\Events\SmsMessageStatusUpdated;
use SmsTools2\Tests\Fixtures\TestSmsInbound;
use SmsTools2\Tests\Fixtures\TestSmsMessage;
use SmsTools2\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function sentMessage(array $attributes = []): TestSmsMessage
    {
        return TestSmsMessage::query()->create($attributes + [
            'recipient' => '27821234567', 'content' => 'Hello', 'reference' => 'ref-1',
            'gateway_ulid' => '01kabc0000000000000000000a', 'status' => 'pending',
            'deliveries' => [], 'replies' => [], 'details' => [],
        ]);
    }

    public function test_rejects_unsigned_badly_signed_and_stale_events(): void
    {
        $event = ['type' => 'webhook.test', 'data' => ['testing' => true]];

        $this->postWebhook($event, secret: null)->assertUnauthorized();
        $this->postWebhook($event, secret: 'wrong')->assertUnauthorized();
        $this->postWebhook($event, timestamp: time() - 3600)->assertUnauthorized();
        $this->postWebhook($event)->assertOk()->assertJson(['ok' => true, 'result' => 'tested']);
    }

    public function test_rejects_everything_when_no_secret_is_configured(): void
    {
        config(['sms-tools.webhook.secret' => null]);

        $this->postWebhook(['type' => 'webhook.test'], secret: 'anything')->assertUnauthorized();
    }

    public function test_status_updates_the_message_runs_hooks_and_fires_events(): void
    {
        Event::fake([SmsMessageStatusUpdated::class]);
        $message = $this->sentMessage();

        $this->postWebhook(['type' => 'message.submitted', 'data' => $this->apiMessage(['status' => 'submitted'])])->assertJson(['result' => 'updated']);
        $this->postWebhook(['type' => 'message.delivered', 'data' => $this->apiMessage(['status' => 'delivered', 'delivered_at' => '2026-11-04T10:00:05Z'])])
            ->assertJson(['result' => 'updated']);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertTrue($message->queued && $message->sent && $message->delivered && ! $message->failed);
        $this->assertSame('2026-11-04 10:00:05', $message->delivered_at->utc()->format('Y-m-d H:i:s'));
        $this->assertCount(2, $message->deliveries);
        $this->assertSame([['queued', $message->id], ['delivered', $message->id]], TestSmsMessage::$hooks);
        Event::assertDispatched(SmsMessageStatusUpdated::class, 2);
    }

    public function test_status_never_moves_backwards(): void
    {
        $message = $this->sentMessage(['status' => 'delivered', 'delivered' => true]);

        $this->postWebhook(['type' => 'message.sent', 'data' => $this->apiMessage(['status' => 'sent'])])->assertJson(['result' => 'unchanged']);

        $this->assertSame('delivered', $message->refresh()->status);
    }

    public function test_failure_runs_the_failed_hook(): void
    {
        $message = $this->sentMessage();

        $this->postWebhook(['type' => 'message.failed', 'data' => $this->apiMessage(['status' => 'failed', 'status_description' => 'Message expired within the operator'])]);

        $message->refresh();
        $this->assertTrue($message->failed);
        $this->assertSame('Message expired within the operator', $message->status_description);
        $this->assertSame([['failed', $message->id]], TestSmsMessage::$hooks);
    }

    public function test_duplicate_events_are_processed_once(): void
    {
        $message = $this->sentMessage();
        $event = ['id' => '01kevent000000000000000001', 'type' => 'message.delivered', 'data' => $this->apiMessage(['status' => 'delivered'])];

        $this->postWebhook($event)->assertJson(['result' => 'updated']);
        $this->postWebhook($event)->assertJson(['result' => 'duplicate']);

        $this->assertCount(1, $message->refresh()->deliveries);
    }

    public function test_messages_sent_under_v1_are_matched_by_legacy_id(): void
    {
        $legacy = TestSmsMessage::query()->create([
            'recipient' => '27821234567', 'content' => 'old', 'gateway_id' => 973317, 'status' => 'submitted', 'queued' => true,
            'deliveries' => [], 'replies' => [], 'details' => [],
        ]);

        $this->postWebhook(['type' => 'message.delivered', 'data' => $this->apiMessage(['id' => '01kold00000000000000000000', 'legacy_id' => 973317, 'status' => 'delivered'])])
            ->assertJson(['result' => 'updated']);

        $legacy->refresh();
        $this->assertSame('delivered', $legacy->status);
        $this->assertSame('01kold00000000000000000000', $legacy->gateway_ulid);
    }

    public function test_unknown_messages_are_acknowledged(): void
    {
        $this->postWebhook(['type' => 'message.delivered', 'data' => $this->apiMessage(['id' => 'nope', 'status' => 'delivered'])])
            ->assertOk()->assertJson(['result' => 'unknown']);
    }

    public function test_replies_are_recorded_once_and_passed_to_the_hook(): void
    {
        Event::fake([SmsMessageReplied::class]);
        $message = $this->sentMessage(['status' => 'delivered']);
        $reply = ['id' => 77, 'type' => 'reply', 'message_id' => $message->gateway_ulid, 'from' => '27821234567', 'to' => '27600000000',
            'content' => 'Yes please', 'received_at' => '2026-11-04T10:05:00Z', 'message' => $this->apiMessage(['status' => 'delivered'])];

        $this->postWebhook(['type' => 'message.replied', 'data' => $reply])->assertJson(['result' => 'replied']);
        $this->postWebhook(['type' => 'message.replied', 'data' => $reply])->assertJson(['result' => 'duplicate']);

        $message->refresh();
        $this->assertTrue($message->replied);
        $this->assertCount(1, $message->replies);
        [$hook, $id, $passed] = TestSmsMessage::$hooks[0];
        $this->assertSame('replied', $hook);
        $this->assertSame('Yes please', $passed['content']);
        $this->assertSame('27821234567', $passed['sender']); // sms-tools 0.x field name still present
        Event::assertDispatched(SmsMessageReplied::class, 1);
    }

    public function test_inbound_creates_a_row_and_runs_the_hook(): void
    {
        Event::fake([SmsInboundReceived::class]);

        $this->postWebhook(['type' => 'inbound.received', 'data' => ['id' => 9000123, 'type' => 'inbound', 'message_id' => null,
            'from' => '27829999999', 'to' => '27600000000', 'content' => 'STOP', 'received_at' => '2026-11-04T11:00:00Z']])
            ->assertJson(['result' => 'received']);

        $inbound = TestSmsInbound::query()->sole();
        $this->assertSame('STOP', $inbound->content);
        $this->assertSame('27829999999', $inbound->sender);
        $this->assertSame([['received', $inbound->id]], TestSmsInbound::$hooks);
        Event::assertDispatched(SmsInboundReceived::class);
    }

    public function test_legacy_v1_webhooks_are_accepted_when_configured(): void
    {
        config(['sms-tools.legacy.key' => 'oldkey', 'sms-tools.legacy.secret' => 'oldsecret']);
        $message = TestSmsMessage::query()->create(['recipient' => '27821234567', 'content' => 'old', 'gateway_id' => 555, 'status' => 'submitted',
            'deliveries' => [], 'replies' => [], 'details' => []]);

        $payload = ['message' => ['id' => 555, 'sent' => 1, 'delivered' => 1, 'failed' => 0, 'replied' => 0, 'sender' => '65501',
            'deliveries' => [['delivered' => 1]], 'replies' => [], 'delivered_at' => '2026-05-01 10:00:00']];
        $signature = md5('oldkey---'.trim((string) json_encode($payload), '{}').'---oldsecret');

        $this->postJson('/sms-tools/webhook', ['type' => 3, 'payload' => $payload, 'signature' => $signature])->assertOk()->assertJson(['success' => true]);
        $this->postJson('/sms-tools/webhook', ['type' => 3, 'payload' => $payload, 'signature' => 'forged'])->assertUnauthorized();

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertTrue($message->delivered);
        $this->assertSame([['delivered', $message->id]], TestSmsMessage::$hooks);
    }

    public function test_legacy_webhooks_are_rejected_when_not_configured(): void
    {
        $this->postJson('/sms-tools/webhook', ['type' => 1, 'payload' => [], 'signature' => 'x'])->assertUnauthorized();
    }
}
