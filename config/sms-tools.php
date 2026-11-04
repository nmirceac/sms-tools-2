<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform API (v2)
    |--------------------------------------------------------------------------
    | Base URL of the SMS Toolkit API and the bearer token issued in the portal
    | (Applications → your application → API tokens). The token needs the
    | abilities messages:send, messages:read, inbound:read and webhook:manage.
    */
    'api' => [
        'url' => env('SMS_TOOLS_API_URL', 'https://sms.weanswer.it/api/v2'),
        'token' => env('SMS_TOOLS_API_TOKEN'),
        'timeout' => (int) env('SMS_TOOLS_API_TIMEOUT', 15),
        // Retries for connection errors, 429 and 5xx (sending is idempotent, so retries are safe).
        'retries' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming webhooks
    |--------------------------------------------------------------------------
    | The platform POSTs delivery reports, replies and inbound SMS here, signed
    | with HMAC-SHA256 using your application's webhook signing secret.
    | `php artisan smstools2:setup` registers the URL and shows the secret.
    */
    'webhook' => [
        'secret' => env('SMS_TOOLS_WEBHOOK_SECRET'),
        'route' => [
            'enabled' => true,
            'path' => 'sms-tools/webhook',
            'name' => 'sms-tools-2.webhook',
            // No "web" group (no CSRF/session) and no throttling: the platform may send bursts.
            'middleware' => [],
        ],
        // Reject events whose X-Sms-Timestamp is older/newer than this (seconds).
        'tolerance' => 300,
        // Events are de-duplicated by id for this long (seconds), using the default cache store.
        'dedupe_ttl' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and tables
    |--------------------------------------------------------------------------
    | Your app's models (published by vendor:publish --tag=sms-tools-2-models,
    | or your existing App\SmsMessage / App\SmsInbound from sms-tools 0.x).
    */
    'models' => [
        'message' => env('SMS_TOOLS_MESSAGE_MODEL', 'App\\SmsMessage'),
        'inbound' => env('SMS_TOOLS_INBOUND_MODEL', 'App\\SmsInbound'),
    ],

    'messages' => [
        'connection' => env('SMS_TOOLS_MESSAGES_CONNECTION'),
        'table' => env('SMS_TOOLS_MESSAGES_TABLE', 'sms_messages'),
    ],

    'inbound' => [
        'connection' => env('SMS_TOOLS_INBOUND_CONNECTION'),
        'table' => env('SMS_TOOLS_INBOUND_TABLE', 'sms_inbound'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Sending and the afterMessage* hooks run as queued jobs.
    */
    'queue' => [
        'connection' => env('SMS_TOOLS_QUEUE_CONNECTION'),
        'name' => env('SMS_TOOLS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy (transition from sms-tools 0.x / API v1)
    |--------------------------------------------------------------------------
    | While messages sent through the old platform are still in flight, it keeps
    | sending old-style (md5-signed) webhooks. Set the old API key and secret to
    | accept them; leave empty once the old platform is retired.
    */
    'legacy' => [
        'key' => env('SMS_TOOLS_LEGACY_API_KEY'),
        'secret' => env('SMS_TOOLS_LEGACY_API_SECRET'),
    ],

];
