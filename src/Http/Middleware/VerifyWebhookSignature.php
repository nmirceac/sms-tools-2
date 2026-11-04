<?php

namespace SmsTools2\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SmsTools2\Webhooks\LegacyWebhookProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2: X-Sms-Signature = hex(HMAC-SHA256(raw body, webhook secret)) and
 *     X-Sms-Timestamp within the configured tolerance.
 * v1 (transition only): md5 signature in the body, when legacy credentials are configured.
 */
class VerifyWebhookSignature
{
    public const LEGACY_ATTRIBUTE = 'sms-tools-2.legacy';

    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->header('X-Sms-Signature', '');

        if ($signature !== '') {
            $secret = (string) config('sms-tools.webhook.secret');
            if ($secret === '') {
                Log::error('sms-tools-2: SMS_TOOLS_WEBHOOK_SECRET is not set; rejecting webhook.');

                return $this->reject('Webhook secret not configured');
            }

            if (! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
                return $this->reject('Invalid signature');
            }

            $timestamp = $request->header('X-Sms-Timestamp');
            $tolerance = (int) config('sms-tools.webhook.tolerance', 300);
            if ($timestamp !== null && $tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
                return $this->reject('Stale timestamp');
            }

            return $next($request);
        }

        if (LegacyWebhookProcessor::enabled() && LegacyWebhookProcessor::looksLegacy($request)) {
            if (! LegacyWebhookProcessor::signatureIsValid($request)) {
                return $this->reject('Invalid signature');
            }
            $request->attributes->set(self::LEGACY_ATTRIBUTE, true);

            return $next($request);
        }

        return $this->reject('Missing signature');
    }

    private function reject(string $message): Response
    {
        return response()->json(['error' => ['message' => $message, 'status' => 401]], 401);
    }
}
