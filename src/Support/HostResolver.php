<?php

namespace KaiHempel\Mastodon\Support;

interface HostResolver
{
    /**
     * Resolve a host name to all of its IPv4 and IPv6 addresses.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
