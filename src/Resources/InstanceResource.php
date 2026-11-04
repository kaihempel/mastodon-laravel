<?php

namespace KaiHempel\Mastodon\Resources;

use Illuminate\Contracts\Cache\Repository;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Data\OAuthMetadata;
use KaiHempel\Mastodon\Http\Connector;

class InstanceResource
{
    public function __construct(
        private readonly Connector $connector,
        private readonly Repository $cache,
        private readonly int $ttl = 3600,
    ) {}

    /** GET /api/v2/instance (cached for "instance_cache_ttl" seconds). */
    public function info(bool $fresh = false): InstanceInfo
    {
        $key = 'mastodon:instance:'.sha1($this->connector->baseUrl());

        if ($fresh) {
            $this->cache->forget($key);
        }

        $data = $this->cache->remember($key, $this->ttl, fn () => (array) $this->connector->get('/api/v2/instance')->json());

        return InstanceInfo::fromArray($data);
    }

    public function forget(): void
    {
        $this->cache->forget('mastodon:instance:'.sha1($this->connector->baseUrl()));
    }

    /**
     * GET /.well-known/oauth-authorization-server. Returns null for servers
     * older than 4.3 (HTTP 404).
     */
    public function oauthMetadata(): ?OAuthMetadata
    {
        $response = $this->connector->get('/.well-known/oauth-authorization-server', [], ['allow' => [404]]);

        if ($response->status() === 404 || ! is_array($response->json())) {
            return null;
        }

        return OAuthMetadata::fromArray($response->json());
    }
}
