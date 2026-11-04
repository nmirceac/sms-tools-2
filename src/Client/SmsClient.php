<?php

namespace SmsTools2\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Typed client for the SMS Toolkit API v2.
 *
 * Message arrays follow the API's message resource:
 *   id (ulid), legacy_id, reference, recipient, sender_id, content, encoding, parts,
 *   status, status_code, status_description, operator, created_at, submitted_at,
 *   delivered_at, failed_at, replied_at[, events][, replies]
 */
class SmsClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $token,
        protected int $timeout = 15,
        protected int $retries = 2,
    ) {}

    /**
     * Send one SMS. Re-sending the same $reference returns the original message
     * (created = false) instead of sending again.
     *
     * @return array<string, mixed> message, plus "created" => bool
     */
    public function send(string $recipient, string $content, ?string $reference = null): array
    {
        $response = $this->request('post', 'messages', array_filter([
            'recipient' => $recipient,
            'content' => $content,
            'reference' => $reference,
        ], fn ($v) => $v !== null));

        return (array) $response->json('data') + ['created' => (bool) $response->json('created', true)];
    }

    /**
     * @param  list<array{recipient: string, content: string, reference?: string|null}>  $messages
     * @return list<array{index: int, created: bool|null, message: array<string, mixed>|null, errors: array<string, list<string>>|null}>
     */
    public function sendBatch(array $messages): array
    {
        return (array) $this->request('post', 'messages/batch', ['messages' => $messages])->json('data');
    }

    /** @return array<string, mixed> */
    public function message(string $id): array
    {
        return (array) $this->request('get', 'messages/'.rawurlencode($id))->json('data');
    }

    /**
     * @param  array{status?: string, from?: string, to?: string, reference?: string, recipient?: string, per_page?: int, cursor?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function messages(array $filters = []): array
    {
        return $this->paginated('messages', $filters);
    }

    /**
     * @param  array{type?: 'reply'|'inbound', from?: string, to?: string, per_page?: int, cursor?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function inbound(array $filters = []): array
    {
        return $this->paginated('inbound', $filters);
    }

    /** @return array{url: string|null, secret: string, events: list<string>} */
    public function webhook(): array
    {
        return $this->webhookSettings((array) $this->request('get', 'webhook')->json('data'));
    }

    /** @return array{url: string|null, secret: string, events: list<string>} */
    public function updateWebhook(?string $url, bool $rotateSecret = false): array
    {
        return $this->webhookSettings((array) $this->request('put', 'webhook', ['url' => $url, 'rotate_secret' => $rotateSecret])->json('data'));
    }

    /** @return array{ok: bool, status: int|null, error: string|null} */
    public function testWebhook(): array
    {
        $data = (array) $this->request('post', 'webhook/test')->json('data');

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'status' => isset($data['status']) ? (int) $data['status'] : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{url: string|null, secret: string, events: list<string>}
     */
    protected function webhookSettings(array $data): array
    {
        return [
            'url' => isset($data['url']) ? (string) $data['url'] : null,
            'secret' => (string) ($data['secret'] ?? ''),
            'events' => array_values(array_map('strval', (array) ($data['events'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    protected function paginated(string $path, array $filters): array
    {
        $response = $this->request('get', $path, $filters);

        return [
            'data' => array_values((array) $response->json('data')),
            'next_cursor' => $response->json('meta.next_cursor'),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function request(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $this->http()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw SmsApiException::connection($e);
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (Throwable $e) {
            throw SmsApiException::connection($e);
        }

        if (! $response->successful()) {
            throw SmsApiException::fromResponse($response);
        }

        return $response;
    }

    protected function http(): PendingRequest
    {
        if (blank($this->token)) {
            throw new SmsApiException('SMS_TOOLS_API_TOKEN is not configured.', 401);
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('sms-tools-2 (Laravel)')
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout))
            ->retry(
                $this->retries + 1,
                fn (int $attempt) => 250 * 2 ** ($attempt - 1),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }
}
