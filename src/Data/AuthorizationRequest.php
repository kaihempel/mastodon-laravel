<?php

namespace KaiHempel\Mastodon\Data;

/**
 * Result of AuthorizationFlow::start(): redirect the user to $url.
 */
final class AuthorizationRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $url,
        public readonly string $state,
        public readonly string $instance,
        public readonly int $appId,
        public readonly array $scopes,
    ) {}
}
