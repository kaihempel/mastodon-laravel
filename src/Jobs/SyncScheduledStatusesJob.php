<?php

namespace KaiHempel\Mastodon\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KaiHempel\Mastodon\Enums\PostState;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Models\MastodonPost;

/**
 * Compares local posts in state "scheduled" with the server's scheduled
 * statuses: changed dates are taken over; entries that disappeared are
 * marked "published" (due date passed) or "deleted" (cancelled elsewhere).
 */
class SyncScheduledStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int|string|null $accountId = null)
    {
        $this->onConnection(config('mastodon.queue.connection'));
        $this->onQueue(config('mastodon.queue.queue'));
    }

    public function handle(MastodonManager $manager): void
    {
        $accounts = MastodonAccount::query()->active()
            ->when($this->accountId !== null, fn ($q) => $q->whereKey($this->accountId))
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->syncAccount($manager, $account);
            } catch (AuthenticationException) {
                continue;
            } catch (RateLimitException $e) {
                $this->release($e->retryAfterSeconds());

                return;
            }
        }
    }

    private function syncAccount(MastodonManager $manager, MastodonAccount $account): void
    {
        $posts = MastodonPost::query()
            ->where('mastodon_account_id', $account->getKey())
            ->where('state', PostState::Scheduled)
            ->whereNotNull('remote_scheduled_id')
            ->get();

        if ($posts->isEmpty()) {
            return;
        }

        $remote = $manager->for($account)->scheduled()->list(['limit' => 40])->keyBy('id');

        foreach ($posts as $post) {
            $scheduled = $remote->get($post->remote_scheduled_id);

            if ($scheduled !== null) {
                if ($scheduled->scheduledAt !== null && ! $scheduled->scheduledAt->equalTo($post->scheduled_at)) {
                    $post->forceFill(['scheduled_at' => $scheduled->scheduledAt])->save();
                }

                continue;
            }

            if ($post->scheduled_at !== null && $post->scheduled_at->isPast()) {
                $post->forceFill(['state' => PostState::Published, 'published_at' => $post->scheduled_at])->save();
            } else {
                $post->forceFill(['state' => PostState::Deleted])->save();
            }
        }
    }
}
