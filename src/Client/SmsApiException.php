<?php

namespace SmsTools2\Client;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/** An error response (or no response) from the SMS Toolkit API. */
class SmsApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  validation errors, keyed by field
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = (array) ($response->json('error') ?? []);

        return new self(
            (string) ($error['message'] ?? ('HTTP '.$response->status())),
            $response->status(),
            (array) ($error['errors'] ?? []),
        );
    }

    public static function connection(Throwable $e): self
    {
        return new self('Could not reach the SMS platform: '.$e->getMessage(), 0, [], $e);
    }

    /** Worth retrying later (network problem, rate limit, server error). */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
