<?php

namespace KaiHempel\Mastodon\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Publishing\PendingStatus;
use KaiHempel\Mastodon\Publishing\Publisher;
use KaiHempel\Mastodon\Publishing\StatusValidator;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Created by Mastodon::fake(). Publishing is recorded and validated against
 * $instance (Mastodon defaults when omitted); no HTTP requests are sent.
 */
class MastodonFake extends MastodonManager
{
    private FakePublisher $fakePublisher;

    public function __construct(Container $container, ?InstanceInfo $instance = null)
    {
        parent::__construct($container);

        $this->fakePublisher = new FakePublisher(
            $container->make(StatusValidator::class),
            $instance ?? InstanceInfo::fromArray(['version' => '4.5.0', 'api_versions' => ['mastodon' => 7]]),
        );
    }

    public function publisher(): Publisher
    {
        return $this->fakePublisher;
    }

    /** @return list<PendingStatus> */
    public function published(): array
    {
        return array_values(array_filter($this->fakePublisher->published, fn (PendingStatus $s) => ! $s->isScheduled()));
    }

    /** @return list<PendingStatus> */
    public function scheduled(): array
    {
        return array_values(array_filter($this->fakePublisher->published, fn (PendingStatus $s) => $s->isScheduled()));
    }

    /** @param (Closure(PendingStatus): bool)|null $callback */
    public function assertPublished(?Closure $callback = null): void
    {
        $matches = array_filter($this->published(), fn (PendingStatus $s) => $callback === null || $callback($s));

        PHPUnit::assertNotEmpty($matches, 'The expected status was not published.');
    }

    /** @param (Closure(PendingStatus): bool)|null $callback */
    public function assertScheduled(?Closure $callback = null): void
    {
        $matches = array_filter($this->scheduled(), fn (PendingStatus $s) => $callback === null || $callback($s));

        PHPUnit::assertNotEmpty($matches, 'The expected status was not scheduled.');
    }

    public function assertNothingPublished(): void
    {
        PHPUnit::assertEmpty($this->fakePublisher->published, 'Unexpected statuses were published or scheduled.');
    }

    public function assertPublishedCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->fakePublisher->published);
    }
}
