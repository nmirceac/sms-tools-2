<?php

namespace SmsTools2\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SmsTools2\Enums\MessageStatus;
use SmsTools2\Models\SmsMessage;

class SmsMessageStatusUpdated
{
    use Dispatchable;

    public function __construct(
        public SmsMessage $message,
        public MessageStatus $status,
        public MessageStatus $previous,
    ) {}
}
