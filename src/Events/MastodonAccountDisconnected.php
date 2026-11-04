<?php

namespace KaiHempel\Mastodon\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KaiHempel\Mastodon\Models\MastodonAccount;

/**
 * Fired when an account was disconnected by the user (revoked) or the server
 * rejected its token (HTTP 401, $reason = "unauthorized").
 */
class MastodonAccountDisconnected
{
    use Dispatchable;

    public function __construct(
        public readonly MastodonAccount $account,
        public readonly string $reason = 'revoked',
    ) {}
}
