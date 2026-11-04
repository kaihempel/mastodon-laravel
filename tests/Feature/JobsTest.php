<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use KaiHempel\Mastodon\Enums\PostState;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Jobs\PublishStatusJob;
use KaiHempel\Mastodon\Jobs\SyncScheduledStatusesJob;
use KaiHempel\Mastodon\Jobs\VerifyAccountJob;
use KaiHempel\Mastodon\Models\MastodonPost;
use KaiHempel\Mastodon\Tests\TestCase;

class JobsTest extends TestCase
{
    public function test_queue_dispatches_unique_publish_job(): void
    {
        Queue::fake();
        $account = $this->createAccount();

        $key = Mastodon::for($account)->status('Später')->attach(__DIR__.'/../Fixtures/pixel.png', 'Pixel')->queue();

        Queue::assertPushed(PublishStatusJob::class, function (PublishStatusJob $job) use ($key, $account) {
            return $job->uniqueId() === $key
                && $job->accountId === $account->id
                && $job->draft['status'] === 'Später'
                && $job->draft['media'][0]['file']['type'] === 'path'
                && $job->draft['media'][0]['description'] === 'Pixel';
        });
    }

    public function test_queue_with_time_delays_the_job(): void
    {
        Queue::fake();

        Mastodon::for($this->createAccount())->status('Lokal geplant')->queue(now()->addHours(3));

        Queue::assertPushed(PublishStatusJob::class, fn (PublishStatusJob $job) => $job->delay->equalTo(now()->addHours(3)));
    }

    public function test_publish_job_publishes_the_draft(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processed')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);
        $account = $this->createAccount();
        $draft = Mastodon::for($account)->status('Aus der Queue')->attach(__DIR__.'/../Fixtures/pixel.png')->visibility('unlisted')->idempotencyKey('job-1')->toArray();

        (new PublishStatusJob($account->id, $draft))->handle(app(\KaiHempel\Mastodon\MastodonManager::class));

        $post = MastodonPost::query()->sole();
        $this->assertSame(PostState::Published, $post->state);
        $this->assertSame('job-1', $post->idempotency_key);
        Http::assertSent(fn ($r) => $r->url() === self::INSTANCE.'/api/v1/statuses' && $r['visibility'] === 'unlisted' && $r['media_ids'] === ['22348641']);
    }

    public function test_publish_job_is_released_on_rate_limit(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response(['error' => 'Too many requests'], 429, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => now()->addMinutes(5)->toIso8601String(),
            ]),
        ]);
        $account = $this->createAccount();
        $draft = Mastodon::for($account)->status('Hallo')->idempotencyKey('job-2')->toArray();

        $job = (new PublishStatusJob($account->id, $draft))->withFakeQueueInteractions();
        $job->handle(app(\KaiHempel\Mastodon\MastodonManager::class));

        $job->assertReleased(300);
        $this->assertSame(PostState::Failed, MastodonPost::query()->sole()->state);
    }

    public function test_publish_job_fails_on_validation_errors(): void
    {
        $this->fakeInstance();
        $account = $this->createAccount();
        $draft = Mastodon::for($account)->status('')->idempotencyKey('job-3')->toArray();

        $job = (new PublishStatusJob($account->id, $draft))->withFakeQueueInteractions();
        $job->handle(app(\KaiHempel\Mastodon\MastodonManager::class));

        $job->assertFailed();
    }

    public function test_publish_job_fails_for_disabled_accounts(): void
    {
        $account = $this->createAccount(['disabled_at' => now()]);

        $job = (new PublishStatusJob($account->id, ['status' => 'x', 'idempotency_key' => 'k']))->withFakeQueueInteractions();
        $job->handle(app(\KaiHempel\Mastodon\MastodonManager::class));

        $job->assertFailed();
        Http::assertNothingSent();
    }

    public function test_verify_account_job_refreshes_profile(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response($this->fixture('account', ['display_name' => 'Neu']))]);
        $account = $this->createAccount();

        VerifyAccountJob::dispatchSync($account->id);

        $account->refresh();
        $this->assertSame('Neu', $account->display_name);
        $this->assertSame('2026-11-04 12:00:00', $account->last_verified_at->format('Y-m-d H:i:s'));
    }

    public function test_verify_account_job_disables_on_401(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response(['error' => 'The access token was revoked'], 401)]);
        $account = $this->createAccount();

        VerifyAccountJob::dispatchSync($account->id);

        $this->assertTrue($account->fresh()->isDisabled());
    }

    public function test_sync_scheduled_statuses(): void
    {
        $account = $this->createAccount();
        $base = ['mastodon_account_id' => $account->id, 'state' => PostState::Scheduled];
        $kept = MastodonPost::query()->create($base + ['idempotency_key' => 'a', 'remote_scheduled_id' => '1', 'scheduled_at' => now()->addHour()]);
        $published = MastodonPost::query()->create($base + ['idempotency_key' => 'b', 'remote_scheduled_id' => '2', 'scheduled_at' => now()->subMinute()]);
        $cancelled = MastodonPost::query()->create($base + ['idempotency_key' => 'c', 'remote_scheduled_id' => '3', 'scheduled_at' => now()->addDay()]);

        Http::fake([self::INSTANCE.'/api/v1/scheduled_statuses*' => Http::response([
            $this->fixture('scheduled_status', ['id' => '1', 'scheduled_at' => '2026-11-04T15:30:00.000Z']),
        ])]);

        $this->artisan('mastodon:scheduled:sync')->assertSuccessful();

        $this->assertSame('2026-11-04 15:30:00', $kept->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(PostState::Scheduled, $kept->fresh()->state);
        $this->assertSame(PostState::Published, $published->fresh()->state);
        $this->assertSame(PostState::Deleted, $cancelled->fresh()->state);
    }

    public function test_sync_command_can_queue(): void
    {
        Queue::fake();

        $this->artisan('mastodon:scheduled:sync', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(SyncScheduledStatusesJob::class);
    }
}
