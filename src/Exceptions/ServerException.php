<?php

namespace KaiHempel\Mastodon\Exceptions;

/**
 * Server error (HTTP 5xx), retryable.
 */
class ServerException extends MastodonException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
