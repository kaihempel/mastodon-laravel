<?php

namespace KaiHempel\Mastodon\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KaiHempel\Mastodon\Models\MastodonAccount;

class MastodonAccountConnected
{
    use Dispatchable;

    public function __construct(public readonly MastodonAccount $account) {}
}
