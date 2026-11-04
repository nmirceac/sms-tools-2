<?php

namespace SmsTools2\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SmsTools2\Client\SmsApiException;
use SmsTools2\Client\SmsClient;
use SmsTools2\Facades\Sms;
use SmsTools2\Tests\Fixtures\TestSmsMessage;
use SmsTools2\Tests\TestCase;

class SendingTest extends TestCase
{
    public function test_queue_sends_with_a_reference_and_stores_the_platform_id(): void
    {
        Http::fake(['sms.test/*' => fn (Request $r) => Http::response([
            'data' => $this->apiMessage(['reference' => $r['reference'], 'recipient' => '27821234567', 'parts' => 2]),
            'created' => true,
        ], 202)]);

        $message = TestSmsMessage::queue('082 123 4567', 'Héllo 😀 — unicode is kept');

        $message->refresh();
        $this->assertSame('01kabc0000000000000000000a', $message->gateway_ulid);
        $this->assertSame('pending', $message->status);
        $this->assertSame('27821234567', $message->recipient);
        $this->assertSame(2, $message->parts);
        $this->assertSame('Héllo 😀 — unicode is kept', $message->content);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.test/api/v2/messages'
            && $r->hasHeader('Authorization', 'Bearer test-token')
            && $r['reference'] === $message->reference
            && $r['recipient'] === '0821234567');
    }

    public function test_a_retry_after_a_server_error_reuses_the_same_reference(): void
    {
        $references = [];
        Http::fake(['sms.test/*' => Http::sequence()
            ->push(['error' => ['message' => 'Server error.', 'status' => 500]], 500)
            ->push(['error' => ['message' => 'Server error.', 'status' => 500]], 500)
            ->push(['data' => $this->apiMessage(), 'created' => true], 202)]);
        Http::globalRequestMiddleware(function ($request) use (&$references) {
            $references[] = json_decode((string) $request->getBody(), true)['reference'] ?? null;

            return $request;
        });

        $message = TestSmsMessage::query()->create(['recipient' => '27821234567', 'content' => 'Hi', 'reference' => 'ref-1', 'status' => 'pending', 'deliveries' => [], 'replies' => [], 'details' => []]);

        try {
            $message->sendToGateway();
            $this->fail('Expected the first attempt to fail');
        } catch (SmsApiException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $message->sendToGateway();

        $this->assertSame(['ref-1', 'ref-1', 'ref-1'], $references);
        $this->assertNotNull($message->refresh()->gateway_ulid);
    }

    public function test_messages_already_on_the_platform_are_not_sent_again(): void
    {
        Http::fake();
        $message = TestSmsMessage::query()->create(['recipient' => '27821234567', 'content' => 'Hi', 'gateway_ulid' => 'x', 'status' => 'submitted', 'deliveries' => [], 'replies' => [], 'details' => []]);

        $this->assertTrue($message->sendToGateway());
        Http::assertNothingSent();
    }

    public function test_validation_errors_fail_the_message_without_retrying(): void
    {
        Http::fake(['sms.test/*' => Http::response(['error' => ['message' => 'The given data was invalid.', 'status' => 422,
            'errors' => ['recipient' => ['The recipient is not a valid phone number.']]]], 422)]);

        $message = TestSmsMessage::queue('123', 'Hi')->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertTrue($message->failed);
        $this->assertSame('The given data was invalid.', $message->status_description);
        Http::assertSentCount(1);
    }

    public function test_api_errors_are_mapped(): void
    {
        Http::fake(['sms.test/*' => Http::response(['error' => ['message' => 'Unauthenticated. Send a valid bearer token.', 'status' => 401]], 401)]);

        try {
            app(SmsClient::class)->message('abc');
            $this->fail('Expected an exception');
        } catch (SmsApiException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('Unauthenticated', $e->getMessage());
        }
    }

    public function test_sms_fake_records_messages(): void
    {
        $fake = Sms::fake();

        $message = TestSmsMessage::queue('27821234567', 'Hello from a test');

        $fake->assertSent(fn ($m) => $m['content'] === 'Hello from a test' && $m['reference'] === $message->reference);
        $this->assertSame('pending', $message->refresh()->status);
        $this->assertNotNull($message->gateway_ulid);
    }
}
