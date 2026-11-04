<?php

namespace KaiHempel\Mastodon\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Models\MastodonAccount;

/**
 * Calls verify_credentials and refreshes the stored profile data. A 401
 * disables the account (see MastodonManager::connectorFor()).
 */
class VerifyAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int|string $accountId)
    {
        $this->onConnection(config('mastodon.queue.connection'));
        $this->onQueue(config('mastodon.queue.queue'));
    }

    public function handle(MastodonManager $manager): void
    {
        $account = MastodonAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $remote = $manager->for($account)->accounts()->verify();
        } catch (AuthenticationException) {
            return; // account has been disabled
        } catch (RateLimitException $e) {
            $this->release($e->retryAfterSeconds());

            return;
        }

        $account->forceFill([
            'acct' => $remote->acct,
            'username' => $remote->username,
            'display_name' => $remote->displayName,
            'url' => $remote->url,
            'last_verified_at' => now(),
            'disabled_at' => null,
        ])->save();
    }
}
