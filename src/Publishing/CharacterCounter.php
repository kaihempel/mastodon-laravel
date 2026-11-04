<?php

namespace KaiHempel\Mastodon\Publishing;

/**
 * Counts characters the way Mastodon does: every URL counts as
 * characters_reserved_per_url characters, remote mentions only count with
 * their user name ("@alice@example.social" counts as "@alice").
 */
final class CharacterCounter
{
    private const URL_PATTERN = '~(?<![\w/@.])https?://[^\s<>"]+~iu';

    private const MENTION_PATTERN = '/(?<=^|[^\/\w])@([a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?)(?:@[\p{L}\p{N}.\-]+[\p{L}\p{N}]+)?/iu';

    public static function count(?string $text, int $charactersPerUrl = 23): int
    {
        if ($text === null || $text === '') {
            return 0;
        }

        $placeholder = str_repeat('x', max(0, $charactersPerUrl));

        $text = (string) preg_replace_callback(self::URL_PATTERN, function (array $match) use ($placeholder) {
            // Trailing punctuation is not part of the URL.
            $url = $match[0];
            $trailing = '';
            if (preg_match('/[.,;:!?\'")\]]+$/u', $url, $m) && strlen($m[0]) < strlen($url)) {
                $trailing = $m[0];
            }

            return $placeholder.$trailing;
        }, $text);

        $text = (string) preg_replace(self::MENTION_PATTERN, '@$1', $text);

        return mb_strlen($text, 'UTF-8');
    }
}
