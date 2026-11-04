<?php

namespace KaiHempel\Mastodon\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * $key is the rate limit key (account id or instance host), $scope is
 * "server" for HTTP 429 or the name of a local throttle ("media_uploads", "deletes").
 */
class MastodonRateLimited
{
    use Dispatchable;

    public function __construct(
        public readonly string $key,
        public readonly ?CarbonImmutable $resetAt,
        public readonly string $scope = 'server',
    ) {}
}
