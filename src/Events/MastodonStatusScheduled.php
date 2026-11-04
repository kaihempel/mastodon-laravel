<?php

namespace KaiHempel\Mastodon\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Models\MastodonPost;

class MastodonStatusScheduled
{
    use Dispatchable;

    public function __construct(public readonly MastodonPost $post, public readonly ScheduledStatus $scheduledStatus) {}
}
