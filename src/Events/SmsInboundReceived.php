<?php

namespace SmsTools2\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SmsTools2\Models\SmsInbound;

class SmsInboundReceived
{
    use Dispatchable;

    public function __construct(public SmsInbound $inbound) {}
}
