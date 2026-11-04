<?php

namespace SmsTools2\Tests\Fixtures;

use SmsTools2\Models\SmsMessage;

/** Like an app's App\SmsMessage: untyped hooks, as written for sms-tools 0.x. */
class TestSmsMessage extends SmsMessage
{
    /** @var list<array{0: string, 1: int, 2?: mixed}> */
    public static array $hooks = [];

    public function afterMessageQueued()
    {
        static::$hooks[] = ['queued', $this->id];
    }

    public function afterMessageDelivered()
    {
        static::$hooks[] = ['delivered', $this->id];
    }

    public function afterMessageFailed()
    {
        static::$hooks[] = ['failed', $this->id];
    }

    public function afterMessageReplied($reply)
    {
        static::$hooks[] = ['replied', $this->id, $reply];
    }
}
