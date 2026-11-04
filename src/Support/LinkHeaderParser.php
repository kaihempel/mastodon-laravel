<?php

namespace KaiHempel\Mastodon\Support;

/**
 * Parses RFC 8288 Link headers, e.g.
 * <https://example.social/api/v1/x?max_id=7>; rel="next", <...?min_id=9>; rel="prev"
 */
final class LinkHeaderParser
{
    /**
     * @return array<string, string> rel => URL
     */
    public static function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $links = [];

        preg_match_all('/<([^>]*)>\s*((?:;\s*[^;,]+(?:=(?:"[^"]*"|[^;,]*))?\s*)*)/', $header, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = trim($match[1]);

            if (! preg_match('/;\s*rel\s*=\s*(?:"([^"]*)"|([^;,\s]+))/i', $match[2], $rel)) {
                continue;
            }

            $relations = preg_split('/\s+/', strtolower(trim($rel[1] !== '' ? $rel[1] : ($rel[2] ?? '')))) ?: [];

            foreach ($relations as $relation) {
                if ($relation !== '' && ! isset($links[$relation])) {
                    $links[$relation] = $url;
                }
            }
        }

        return $links;
    }

    public static function next(?string $header): ?string
    {
        return self::parse($header)['next'] ?? null;
    }

    public static function prev(?string $header): ?string
    {
        return self::parse($header)['prev'] ?? null;
    }
}
