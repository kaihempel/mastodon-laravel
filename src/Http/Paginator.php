<?php

namespace KaiHempel\Mastodon\Http;

use Closure;
use Illuminate\Support\LazyCollection;
use KaiHempel\Mastodon\Support\InstanceUrl;
use KaiHempel\Mastodon\Support\LinkHeaderParser;

/**
 * Follows rel="next" Link headers and yields mapped items page by page.
 * Links pointing to another host are ignored (SSRF protection).
 */
final class Paginator
{
    /**
     * @template T
     *
     * @param  array<string, mixed>  $query
     * @param  Closure(array<string, mixed>): T  $map
     * @return LazyCollection<int, T>
     */
    public static function lazy(Connector $connector, string $path, array $query, Closure $map, ?int $maxPages = null): LazyCollection
    {
        return LazyCollection::make(function () use ($connector, $path, $query, $map, $maxPages) {
            $instance = InstanceUrl::parse($connector->baseUrl(), allowInsecure: true);
            $url = $path;
            $pages = 0;

            while ($url !== null) {
                $response = $connector->get($url, $query);
                $query = [];
                $pages++;

                foreach ((array) $response->json() as $item) {
                    if (is_array($item)) {
                        yield $map($item);
                    }
                }

                $next = LinkHeaderParser::next($response->header('Link'));
                $url = ($next !== null && $instance->owns($next) && ($maxPages === null || $pages < $maxPages)) ? $next : null;
            }
        });
    }
}
