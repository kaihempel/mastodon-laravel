<?php

namespace KaiHempel\Mastodon\Enums;

enum Visibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
    case Direct = 'direct';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
