<?php

namespace SmsTools2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SmsTools2\Http\Middleware\VerifyWebhookSignature;
use SmsTools2\Webhooks\LegacyWebhookProcessor;
use SmsTools2\Webhooks\WebhookProcessor;
use Throwable;

class WebhookController
{
    public function __invoke(Request $request, WebhookProcessor $processor, LegacyWebhookProcessor $legacy): JsonResponse
    {
        if ($request->attributes->get(VerifyWebhookSignature::LEGACY_ATTRIBUTE)) {
            return response()->json($legacy->process($request->all()));
        }

        $event = (array) $request->json()->all();
        $key = 'sms-tools-2:event:'.($event['id'] ?? sha1($request->getContent()));

        // Each event is processed once, even if the platform retries it.
        if (! Cache::add($key, true, (int) config('sms-tools.webhook.dedupe_ttl', 86400))) {
            return response()->json(['ok' => true, 'result' => 'duplicate']);
        }

        try {
            $result = $processor->process($event);
        } catch (Throwable $e) {
            Cache::forget($key); // let the platform's retry try again

            throw $e;
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
