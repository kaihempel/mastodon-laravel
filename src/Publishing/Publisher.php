<?php

namespace KaiHempel\Mastodon\Publishing;

use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;

interface Publisher
{
    public function publish(PendingStatus $status): Status|ScheduledStatus;
}
