<?php

namespace SmsTools2\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SmsTools2\Models\SmsMessage;

class SmsMessageReplied
{
    use Dispatchable;

    /** @param array<string, mixed> $reply */
    public function __construct(public SmsMessage $message, public array $reply) {}
}
