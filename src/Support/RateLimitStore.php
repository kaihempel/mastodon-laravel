<?php

namespace KaiHempel\Mastodon\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps the last known X-RateLimit-* state per account (or instance) in the cache.
 */
final class RateLimitStore
{
    public function __construct(private readonly Repository $cache) {}

    public function put(string $key, RateLimitState $state): void
    {
        $ttl = $state->resetAt !== null
            ? max(60, (int) now()->diffInSeconds($state->resetAt, false) + 60)
            : 600;

        $this->cache->put($this->cacheKey($key), $state->toArray(), $ttl);
    }

    public function get(string $key): ?RateLimitState
    {
        $data = $this->cache->get($this->cacheKey($key));

        return is_array($data) ? RateLimitState::fromArray($data) : null;
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return 'mastodon:rate-limit:'.$key;
    }
}
