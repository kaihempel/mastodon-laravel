<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Publishing\PendingStatus;
use KaiHempel\Mastodon\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class FakeTest extends TestCase
{
    public function test_fake_records_published_and_scheduled_statuses(): void
    {
        $fake = Mastodon::fake();
        $account = $this->createAccount();

        $status = Mastodon::for($account)->status('Hallo Welt')->visibility('unlisted')->publish();
        $scheduled = $account->client()->status('Später')->scheduleAt(now()->addHour())->publish();

        $this->assertInstanceOf(Status::class, $status);
        $this->assertSame('unlisted', $status->visibility);
        $this->assertInstanceOf(ScheduledStatus::class, $scheduled);

        Mastodon::assertPublished(fn (PendingStatus $s) => $s->getStatus() === 'Hallo Welt');
        Mastodon::assertScheduled(fn (PendingStatus $s) => $s->getStatus() === 'Später');
        Mastodon::assertPublishedCount(2);
        $this->assertCount(1, $fake->published());
        Http::assertNothingSent();
    }

    public function test_fake_assertions_fail_when_nothing_matches(): void
    {
        Mastodon::fake();
        Mastodon::assertNothingPublished();

        $this->expectException(AssertionFailedError::class);
        Mastodon::assertPublished();
    }

    public function test_fake_validates_against_given_instance(): void
    {
        Mastodon::fake(InstanceInfo::fromArray(['configuration' => ['statuses' => ['max_characters' => 5]]]));

        $this->expectException(ValidationException::class);

        Mastodon::for($this->createAccount())->status('Zu lang')->publish();
    }
}
