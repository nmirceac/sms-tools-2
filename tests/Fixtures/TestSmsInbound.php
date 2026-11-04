<?php

namespace SmsTools2\Tests\Fixtures;

use SmsTools2\Models\SmsInbound;

class TestSmsInbound extends SmsInbound
{
    /** @var list<array{0: string, 1: int}> */
    public static array $hooks = [];

    public function afterMessageReceived()
    {
        static::$hooks[] = ['received', $this->id];
    }
}
