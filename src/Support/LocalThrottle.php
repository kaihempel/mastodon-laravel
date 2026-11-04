<?php

namespace KaiHempel\Mastodon\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use KaiHempel\Mastodon\Events\MastodonRateLimited;
use KaiHempel\Mastodon\Exceptions\RateLimitException;

/**
 * Local brakes per account so the stricter server limits (media uploads and
 * deletions: 30 per 30 minutes) are not reached in the first place.
 */
class LocalThrottle
{
    /** @param array<string, mixed> $config the "mastodon.throttle" config */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Dispatcher $events,
        private readonly array $config,
        private readonly string $key,
    ) {}

    /**
     * @throws RateLimitException when the local limit is reached
     */
    public function hit(string $bucket): void
    {
        if (! ($this->config['enabled'] ?? true) || ! isset($this->config[$bucket])) {
            return;
        }

        $max = (int) ($this->config[$bucket]['max'] ?? 30);
        $decay = (int) ($this->config[$bucket]['per_seconds'] ?? 1800);
        $key = "mastodon:throttle:{$bucket}:{$this->key}";

        if ($this->limiter->tooManyAttempts($key, $max)) {
            $resetAt = CarbonImmutable::now()->addSeconds($this->limiter->availableIn($key));
            $this->events->dispatch(new MastodonRateLimited($this->key, $resetAt, $bucket));

            throw RateLimitException::until($resetAt, "Local {$bucket} limit of {$max} per {$decay}s reached.");
        }

        $this->limiter->hit($key, $decay);
    }
}
