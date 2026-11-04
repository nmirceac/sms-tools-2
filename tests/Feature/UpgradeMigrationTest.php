<?php

namespace SmsTools2\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SmsTools2\Tests\Fixtures\TestSmsMessage;
use SmsTools2\Tests\TestCase;

class UpgradeMigrationTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->dropTables();

        // The sms_messages table exactly as sms-tools 0.x created it.
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('gateway_id')->unsigned()->index()->nullable();
            $table->text('content');
            $table->bigInteger('recipient')->unsigned()->index();
            $table->boolean('queued')->default(false)->index();
            $table->boolean('sent')->default(false)->index();
            $table->boolean('delivered')->default(false)->index();
            $table->boolean('failed')->default(false)->index();
            $table->boolean('replied')->default(false)->index();
            $table->text('deliveries');
            $table->text('replies');
            $table->text('details');
            $table->timestamps();
            $table->dateTime('delivered_at')->nullable();
        });
    }

    public function test_upgrades_an_existing_table_and_keeps_rows(): void
    {
        DB::table('sms_messages')->insert([
            ['gateway_id' => 1001, 'content' => 'a', 'recipient' => 27821234567, 'queued' => 1, 'sent' => 1, 'delivered' => 1, 'failed' => 0, 'replied' => 0, 'deliveries' => '[]', 'replies' => '[]', 'details' => '[]'],
            ['gateway_id' => 1002, 'content' => 'b', 'recipient' => 27821234568, 'queued' => 1, 'sent' => 1, 'delivered' => 0, 'failed' => 1, 'replied' => 0, 'deliveries' => '[]', 'replies' => '[]', 'details' => '[]'],
            ['gateway_id' => null, 'content' => 'c', 'recipient' => 27821234569, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'replied' => 0, 'deliveries' => '[]', 'replies' => '[]', 'details' => '[]'],
        ]);

        $migration = include __DIR__.'/../../database/migrations/upgrade_sms_tools_tables_v2.php.stub';
        $migration->up();
        $migration->up(); // idempotent

        foreach (['gateway_ulid', 'reference', 'status', 'status_description', 'parts', 'failed_at', 'replied_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('sms_messages', $column), $column);
        }

        $this->assertSame(['delivered', 'failed', 'pending'], TestSmsMessage::query()->orderBy('id')->pluck('status')->all());
        $first = TestSmsMessage::query()->find(1);
        $this->assertSame('27821234567', $first->recipient);
        $this->assertSame(1001, $first->gateway_id);
        $this->assertSame([], $first->deliveries);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('sms_messages', 'gateway_ulid'));
        $this->assertSame(3, DB::table('sms_messages')->count());
    }
}
