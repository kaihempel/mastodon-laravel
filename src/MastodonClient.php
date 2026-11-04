<?php

namespace KaiHempel\Mastodon;

use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Publishing\PendingStatus;
use KaiHempel\Mastodon\Resources\AccountsResource;
use KaiHempel\Mastodon\Resources\InstanceResource;
use KaiHempel\Mastodon\Resources\MediaResource;
use KaiHempel\Mastodon\Resources\ScheduledStatusesResource;
use KaiHempel\Mastodon\Resources\StatusesResource;
use KaiHempel\Mastodon\Support\LocalThrottle;

/**
 * API client bound to one connected account.
 */
class MastodonClient
{
    private ?Connector $connector = null;

    private ?LocalThrottle $throttle = null;

    public function __construct(
        private readonly MastodonManager $manager,
        private readonly MastodonAccount $account,
    ) {}

    public function account(): MastodonAccount
    {
        return $this->account;
    }

    public function connector(): Connector
    {
        return $this->connector ??= $this->manager->connectorFor($this->account);
    }

    public function instance(): InstanceResource
    {
        return $this->manager->instanceResource($this->connector());
    }

    public function accounts(): AccountsResource
    {
        return new AccountsResource($this->connector());
    }

    public function media(): MediaResource
    {
        return new MediaResource(
            $this->connector(),
            fn () => $this->instance()->info(),
            $this->throttle(),
            (int) $this->manager->config('media.poll_interval_ms', 1500),
            (int) $this->manager->config('media.poll_timeout_s', 180),
        );
    }

    public function statuses(): StatusesResource
    {
        return new StatusesResource($this->connector(), $this->throttle());
    }

    public function scheduled(): ScheduledStatusesResource
    {
        return new ScheduledStatusesResource($this->connector());
    }

    /** Start building a new status. */
    public function status(string $text = ''): PendingStatus
    {
        return new PendingStatus($this, $this->manager->publisher(), $text);
    }

    private function throttle(): LocalThrottle
    {
        return $this->throttle ??= $this->manager->throttleFor($this->account);
    }
}
