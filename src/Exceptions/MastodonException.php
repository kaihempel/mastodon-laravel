<?php

namespace KaiHempel\Mastodon\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Base class of all exceptions thrown by this package.
 */
class MastodonException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $httpStatus = null,
        public readonly ?string $serverMessage = null,
        public readonly ?string $errorDescription = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public static function fromResponse(Response $response, ?string $fallback = null): static
    {
        $error = $response->json('error');
        $description = $response->json('error_description');

        $error = is_string($error) ? $error : null;
        $description = is_string($description) ? $description : null;

        $message = $description ?? $error ?? $fallback ?? 'Mastodon API request failed';

        return new static(
            sprintf('[%d] %s', $response->status(), $message),
            $response->status(),
            $error,
            $description,
        );
    }

    /** Whether the request may be repeated without changes. */
    public function isRetryable(): bool
    {
        return false;
    }
}
