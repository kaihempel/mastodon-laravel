<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Enums\PostState;
use KaiHempel\Mastodon\Events\MastodonStatusFailed;
use KaiHempel\Mastodon\Events\MastodonStatusPublished;
use KaiHempel\Mastodon\Events\MastodonStatusScheduled;
use KaiHempel\Mastodon\Exceptions\InvalidMediaException;
use KaiHempel\Mastodon\Exceptions\MediaProcessingException;
use KaiHempel\Mastodon\Exceptions\MediaProcessingTimeout;
use KaiHempel\Mastodon\Exceptions\ServerException;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Models\MastodonPost;
use KaiHempel\Mastodon\Tests\TestCase;

class PublishingTest extends TestCase
{
    private const PIXEL = __DIR__.'/../Fixtures/pixel.png';

    public function test_publish_text_status(): void
    {
        Event::fake([MastodonStatusPublished::class]);
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);
        $account = $this->createAccount();

        $status = Mastodon::for($account)
            ->status('Neue Version ist da #release')
            ->visibility('public')
            ->language('de')
            ->spoilerText(null)
            ->sensitive(false)
            ->idempotencyKey('key-1')
            ->publish();

        $this->assertInstanceOf(Status::class, $status);
        $this->assertSame('103270115826048975', $status->id);

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses'
            && $r->method() === 'POST'
            && $r->hasHeader('Idempotency-Key', 'key-1')
            && $r->hasHeader('Authorization', 'Bearer user-token')
            && $r->data() === ['status' => 'Neue Version ist da #release', 'sensitive' => false, 'visibility' => 'public', 'language' => 'de']);

        $post = MastodonPost::query()->sole();
        $this->assertSame(PostState::Published, $post->state);
        $this->assertSame('103270115826048975', $post->remote_status_id);
        $this->assertSame('https://example.social/@trwnh/103270115826048975', $post->url);
        $this->assertSame('Neue Version ist da #release', $post->payload['status']);
        $this->assertSame($account->id, $post->mastodon_account_id);

        Event::assertDispatched(MastodonStatusPublished::class, fn ($e) => $e->post->is($post) && $e->status->id === $status->id);
    }

    public function test_publish_generates_idempotency_key(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);

        Mastodon::for($this->createAccount())->status('Hallo')->publish();

        $key = MastodonPost::query()->sole()->idempotency_key;
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $key));
    }

    public function test_schedule_status_server_side(): void
    {
        Event::fake([MastodonStatusScheduled::class]);
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('scheduled_status')),
        ]);

        $result = Mastodon::for($this->createAccount())
            ->status('Neue Version ist da #release')
            ->scheduleAt(now()->addHours(2))
            ->publish();

        $this->assertInstanceOf(ScheduledStatus::class, $result);
        $this->assertSame('3221', $result->id);

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses'
            && $r['scheduled_at'] === '2026-11-04T14:00:00.000Z');

        $post = MastodonPost::query()->sole();
        $this->assertSame(PostState::Scheduled, $post->state);
        $this->assertSame('3221', $post->remote_scheduled_id);
        $this->assertSame('2026-11-04 14:00:00', $post->scheduled_at->format('Y-m-d H:i:s'));
        Event::assertDispatched(MastodonStatusScheduled::class);
    }

    public function test_image_upload_with_description_and_focus(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processed')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);

        Mastodon::for($this->createAccount())
            ->status('Mit Bild')
            ->attach(self::PIXEL, description: 'Screenshot der Startseite', focus: [0.0, 0.3])
            ->publish();

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::INSTANCE.'/api/v2/media') {
                return false;
            }

            $parts = collect($r->data())->keyBy('name');

            return $r->isMultipart()
                && $parts['description']['contents'] === 'Screenshot der Startseite'
                && $parts['focus']['contents'] === '0,0.3'
                && $parts['file']['filename'] === 'pixel.png'
                && $parts['file']['headers']['Content-Type'] === 'image/png';
        });
        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses' && $r['media_ids'] === ['22348641']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/v1/media/'));
        $this->assertSame(['22348641'], MastodonPost::query()->sole()->payload['media_ids']);
    }

    public function test_video_upload_polls_until_processed(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processing'), 202),
            self::INSTANCE.'/api/v1/media/22348642' => Http::sequence()
                ->push($this->fixture('media_processing'), 206)
                ->push($this->fixture('media_processing'), 206)
                ->push($this->fixture('media_processing', ['url' => 'https://files.example.social/video.mp4'])),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);

        Mastodon::for($this->createAccount())->status('')->attach(self::PIXEL)->publish();

        $this->assertCount(3, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/media/22348642'));
        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses' && $r['media_ids'] === ['22348642'] && $r['status'] === '');
    }

    public function test_media_processing_error(): void
    {
        Event::fake([MastodonStatusFailed::class]);
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processing'), 202),
            self::INSTANCE.'/api/v1/media/22348642' => Http::response(['error' => 'Processing failed'], 422),
        ]);

        try {
            Mastodon::for($this->createAccount())->status('Video')->attach(self::PIXEL)->publish();
            $this->fail('MediaProcessingException expected');
        } catch (MediaProcessingException $e) {
            $this->assertStringContainsString('Processing failed', $e->getMessage());
        }

        $post = MastodonPost::query()->sole();
        $this->assertSame(PostState::Failed, $post->state);
        $this->assertStringContainsString('MediaProcessingException', $post->error);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses');
        Event::assertDispatched(MastodonStatusFailed::class);
    }

    public function test_media_processing_timeout(): void
    {
        config(['mastodon.media.poll_interval_ms' => 1000, 'mastodon.media.poll_timeout_s' => 3]);
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processing'), 202),
            self::INSTANCE.'/api/v1/media/22348642' => Http::response($this->fixture('media_processing'), 206),
        ]);

        $this->expectException(MediaProcessingTimeout::class);

        try {
            Mastodon::for($this->createAccount())->status('Video')->attach(self::PIXEL)->publish();
        } finally {
            $this->assertCount(3, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/media/22348642'));
        }
    }

    public function test_validation_happens_before_any_write_request(): void
    {
        Http::fake([self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance'))]);

        try {
            Mastodon::for($this->createAccount())->status(str_repeat('a', 501))->attach(self::PIXEL)->publish();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors);
        }

        Http::assertSentCount(1);
        $this->assertSame(0, MastodonPost::query()->count());
    }

    public function test_server_validation_error_marks_post_failed(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response(['error' => 'Validation failed: Text character limit of 500 exceeded'], 422),
        ]);

        $this->expectException(ValidationException::class);

        try {
            Mastodon::for($this->createAccount())->status('Hallo')->publish();
        } finally {
            $this->assertSame(PostState::Failed, MastodonPost::query()->sole()->state);
        }
    }

    public function test_retry_with_same_key_does_not_post_twice(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
            self::INSTANCE.'/api/v1/statuses/103270115826048975' => Http::response($this->fixture('status')),
        ]);
        $client = Mastodon::for($this->createAccount());

        $first = $client->status('Hallo')->idempotencyKey('same')->publish();
        $second = $client->status('Hallo')->idempotencyKey('same')->publish();

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses' && $r->method() === 'POST'));
        $this->assertSame(1, MastodonPost::query()->count());
    }

    public function test_retry_after_failure_reuses_uploaded_media_and_key(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processed')),
            self::INSTANCE.'/api/v1/statuses' => Http::sequence()
                ->push(['error' => 'down'], 503)
                ->push(['error' => 'down'], 503)
                ->push(['error' => 'down'], 503)
                ->push($this->fixture('status')),
        ]);
        $client = Mastodon::for($this->createAccount());

        try {
            $client->status('Bild')->attach(self::PIXEL)->idempotencyKey('retry')->publish();
            $this->fail('ServerException expected');
        } catch (ServerException) {
        }

        $this->assertSame(PostState::Failed, MastodonPost::query()->sole()->state);

        $client->status('Bild')->attach(self::PIXEL)->idempotencyKey('retry')->publish();

        $this->assertSame(PostState::Published, MastodonPost::query()->sole()->state);
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v2/media'));
        $posts = Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses');
        $this->assertCount(4, $posts);
        foreach ($posts as [$request]) {
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'retry'));
        }
    }

    public function test_poll_is_sent(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);

        Mastodon::for($this->createAccount())->status('Frage?')->poll(['Ja', 'Nein'], 3600, multiple: true)->publish();

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/statuses' && $r['poll'] === ['options' => ['Ja', 'Nein'], 'expires_in' => 3600, 'multiple' => true, 'hide_totals' => false]);
    }

    public function test_files_outside_allowed_paths_are_rejected(): void
    {
        $this->expectException(InvalidMediaException::class);

        Mastodon::for($this->createAccount())->status('x')->attach('/etc/hostname');
    }

    public function test_files_from_disallowed_disks_are_rejected(): void
    {
        config(['mastodon.media.allowed_disks' => ['public']]);

        $this->expectException(InvalidMediaException::class);

        Mastodon::for($this->createAccount())->status('x')->attachFromDisk('local', 'pixel.png');
    }

    public function test_owner_is_stored(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/statuses' => Http::response($this->fixture('status')),
        ]);
        $account = $this->createAccount();

        Mastodon::for($account)->status('Hallo')->owner($account)->publish();

        $post = MastodonPost::query()->sole();
        $this->assertTrue($post->owner->is($account));
    }
}
