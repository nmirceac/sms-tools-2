<?php

namespace SmsTools2;

use SmsTools2\Models\SmsInbound;
use SmsTools2\Models\SmsMessage;

/** Resolves the app's configured model classes (falls back to the package models). */
final class SmsTools
{
    /** @return class-string<SmsMessage> */
    public static function messageModel(): string
    {
        $class = (string) config('sms-tools.models.message', SmsMessage::class);

        return class_exists($class) && is_a($class, SmsMessage::class, true) ? $class : SmsMessage::class;
    }

    /** @return class-string<SmsInbound> */
    public static function inboundModel(): string
    {
        $class = (string) config('sms-tools.models.inbound', SmsInbound::class);

        return class_exists($class) && is_a($class, SmsInbound::class, true) ? $class : SmsInbound::class;
    }

    /** Apply the configured queue connection/name to a pending job. */
    public static function onQueue(object $job): object
    {
        if (method_exists($job, 'onConnection') && config('sms-tools.queue.connection')) {
            $job->onConnection(config('sms-tools.queue.connection'));
        }
        if (method_exists($job, 'onQueue') && config('sms-tools.queue.name')) {
            $job->onQueue(config('sms-tools.queue.name'));
        }

        return $job;
    }
}
