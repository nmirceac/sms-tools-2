<?php

namespace SmsTools2\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SmsTools2\SmsToolsServiceProvider;
use SmsTools2\Tests\Fixtures\TestSmsInbound;
use SmsTools2\Tests\Fixtures\TestSmsMessage;

abstract class TestCase extends BaseTestCase
{
    protected const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        TestSmsMessage::$hooks = [];
        TestSmsInbound::$hooks = [];
    }

    protected function getPackageProviders($app): array
    {
        return [SmsToolsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory by default; set SMS_TOOLS_TEST_DB=mysql (+ DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD)
        // to run against MySQL. Tables get a "smst_" prefix so a shared test database is never touched.
        if (getenv('SMS_TOOLS_TEST_DB') === 'mysql') {
            $app['config']->set('database.connections.package', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => 'smst_',
            ]);
            $app['config']->set('database.default', 'package');
        } else {
            $app['config']->set('database.default', 'testing');
        }
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('sms-tools.api.url', 'https://sms.test/api/v2');
        $app['config']->set('sms-tools.api.token', 'test-token');
        $app['config']->set('sms-tools.api.retries', 1);
        $app['config']->set('sms-tools.webhook.secret', self::SECRET);
        $app['config']->set('sms-tools.models.message', TestSmsMessage::class);
        $app['config']->set('sms-tools.models.inbound', TestSmsInbound::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->dropTables();
        (include __DIR__.'/../database/migrations/create_sms_tools_tables.php.stub')->up();
    }

    protected function dropTables(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_inbound');
    }

    /** @param array<string, mixed> $event */
    protected function postWebhook(array $event, ?string $secret = self::SECRET, ?int $timestamp = null): TestResponse
    {
        $event += ['id' => strtolower((string) Str::ulid()), 'created_at' => now()->toIso8601ZuluString()];
        $body = (string) json_encode($event);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SMS_TIMESTAMP' => (string) ($timestamp ?? time())];
        if ($secret !== null) {
            $headers['HTTP_X_SMS_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/sms-tools/webhook', [], [], [], $headers, $body);
    }

    /** @return array<string, mixed> a platform message resource */
    protected function apiMessage(array $overrides = []): array
    {
        return $overrides + [
            'id' => '01kabc0000000000000000000a',
            'legacy_id' => null,
            'reference' => null,
            'recipient' => '27821234567',
            'content' => 'Hello',
            'encoding' => 'gsm7',
            'parts' => 1,
            'status' => 'pending',
            'status_description' => null,
            'created_at' => '2026-11-04T10:00:00Z',
            'delivered_at' => null,
            'failed_at' => null,
        ];
    }
}
