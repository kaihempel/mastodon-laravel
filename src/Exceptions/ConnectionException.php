<?php

namespace KaiHempel\Mastodon\Exceptions;

/**
 * Network error (timeout, DNS, TLS), retryable.
 */
class ConnectionException extends MastodonException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
