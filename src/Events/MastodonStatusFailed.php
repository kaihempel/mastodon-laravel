<?php

namespace KaiHempel\Mastodon\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KaiHempel\Mastodon\Models\MastodonPost;
use Throwable;

class MastodonStatusFailed
{
    use Dispatchable;

    public function __construct(public readonly MastodonPost $post, public readonly Throwable $exception) {}
}
