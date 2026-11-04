<?php

namespace KaiHempel\Mastodon\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use KaiHempel\Mastodon\Support\RateLimitState;

/**
 * Too many requests (HTTP 429) or a local throttle was hit.
 */
class RateLimitException extends MastodonException
{
    public ?CarbonImmutable $resetAt = null;

    public static function fromResponse(Response $response, ?string $fallback = null): static
    {
        $exception = parent::fromResponse($response, $fallback ?? 'Too many requests');
        $exception->resetAt = RateLimitState::fromResponse($response)?->resetAt;

        return $exception;
    }

    public static function until(?CarbonImmutable $resetAt, string $message): static
    {
        $exception = new static($message, 429);
        $exception->resetAt = $resetAt;

        return $exception;
    }

    /** Seconds until the limit resets (at least 1). */
    public function retryAfterSeconds(int $default = 60): int
    {
        if ($this->resetAt === null) {
            return $default;
        }

        return max(1, (int) ceil(CarbonImmutable::now()->diffInSeconds($this->resetAt, false)));
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
