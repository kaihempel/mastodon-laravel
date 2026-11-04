<?php

namespace KaiHempel\Mastodon\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Models\MastodonPost;

class MastodonStatusPublished
{
    use Dispatchable;

    public function __construct(public readonly MastodonPost $post, public readonly Status $status) {}
}
