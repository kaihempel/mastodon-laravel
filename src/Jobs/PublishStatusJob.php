<?php

namespace KaiHempel\Mastodon\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\AuthorizationException;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Models\MastodonAccount;

/**
 * Uploads media, waits for processing and creates the status in the
 * background. Unique by idempotency key; on rate limits the job is released
 * until the reset time. Retries reuse the same key, so no duplicates occur.
 */
class PublishStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** @param array<string, mixed> $draft PendingStatus::toArray() incl. idempotency_key */
    public function __construct(
        public readonly int|string $accountId,
        public readonly array $draft,
    ) {
        $this->onConnection(config('mastodon.queue.connection'));
        $this->onQueue(config('mastodon.queue.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->draft['idempotency_key'];
    }

    public function handle(MastodonManager $manager): void
    {
        $account = MastodonAccount::query()->find($this->accountId);

        if ($account === null || $account->isDisabled()) {
            $this->fail(new AuthenticationException('The Mastodon account is missing or disabled.'));

            return;
        }

        try {
            $manager->for($account)->status()->fill($this->draft)->publish();
        } catch (RateLimitException $e) {
            $this->release($e->retryAfterSeconds());
        } catch (ValidationException|AuthenticationException|AuthorizationException $e) {
            // Not retryable.
            $this->fail($e);
        }
    }
}
