<?php

namespace KaiHempel\Mastodon\Testing;

use Carbon\CarbonImmutable;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Publishing\PendingStatus;
use KaiHempel\Mastodon\Publishing\Publisher;
use KaiHempel\Mastodon\Publishing\StatusValidator;

/**
 * Validates against the given InstanceInfo and records the status instead
 * of sending it.
 */
class FakePublisher implements Publisher
{
    /** @var list<PendingStatus> */
    public array $published = [];

    private int $sequence = 0;

    public function __construct(
        private readonly StatusValidator $validator,
        private readonly InstanceInfo $instance,
    ) {}

    public function publish(PendingStatus $status): Status|ScheduledStatus
    {
        $this->validator->validate($status, $this->instance);
        $status->ensureIdempotencyKey();

        $this->published[] = $status;
        $id = (string) (++$this->sequence);

        if ($status->isScheduled()) {
            return ScheduledStatus::fromArray([
                'id' => $id,
                'scheduled_at' => $status->getScheduledAt()?->toIso8601String(),
                'params' => ['text' => $status->getStatus()] + $status->toParams(),
                'media_attachments' => [],
            ]);
        }

        $account = $status->client()->account();

        return Status::fromArray([
            'id' => $id,
            'created_at' => CarbonImmutable::now()->toIso8601String(),
            'url' => rtrim((string) $account->app?->instance_url, '/').'/@'.$account->username.'/'.$id,
            'content' => '<p>'.e($status->getStatus()).'</p>',
            'visibility' => $status->getVisibility() ?? 'public',
            'sensitive' => (bool) $status->isSensitive(),
            'spoiler_text' => (string) $status->getSpoilerText(),
            'language' => $status->getLanguage(),
            'media_attachments' => [],
        ]);
    }
}
