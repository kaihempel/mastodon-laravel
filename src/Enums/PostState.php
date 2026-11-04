<?php

namespace KaiHempel\Mastodon\Enums;

enum PostState: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Deleted = 'deleted';

    /** States in which the post exists (or will exist) on the server. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Scheduled, self::Published, self::Deleted], true);
    }
}
