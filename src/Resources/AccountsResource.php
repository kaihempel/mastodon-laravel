<?php

namespace KaiHempel\Mastodon\Resources;

use KaiHempel\Mastodon\Data\Account;
use KaiHempel\Mastodon\Http\Connector;

class AccountsResource
{
    public function __construct(private readonly Connector $connector) {}

    /** GET /api/v1/accounts/verify_credentials (scope "profile" or "read:accounts"). */
    public function verify(): Account
    {
        return Account::fromArray((array) $this->connector->get('/api/v1/accounts/verify_credentials')->json());
    }
}
