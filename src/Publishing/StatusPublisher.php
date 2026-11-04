<?php

namespace KaiHempel\Mastodon\Publishing;

use Illuminate\Contracts\Events\Dispatcher;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Enums\PostState;
use KaiHempel\Mastodon\Events\MastodonStatusFailed;
use KaiHempel\Mastodon\Events\MastodonStatusPublished;
use KaiHempel\Mastodon\Events\MastodonStatusScheduled;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Models\MastodonPost;
use Throwable;

/**
 * Publishing pipeline: load limits, validate, upload media, wait for
 * processing, create the status with an Idempotency-Key and track the
 * result in mastodon_posts.
 */
class StatusPublisher implements Publisher
{
    public function __construct(
        private readonly StatusValidator $validator,
        private readonly Dispatcher $events,
    ) {}

    public function publish(PendingStatus $pending): Status|ScheduledStatus
    {
        $client = $pending->client();
        $account = $client->account();
        $key = $pending->ensureIdempotencyKey();

        $post = MastodonPost::query()->where('idempotency_key', $key)->first();

        if ($post !== null) {
            if ((int) $post->mastodon_account_id !== (int) $account->getKey()) {
                throw new MastodonException("The idempotency key {$key} is already used by another account.");
            }

            // Already done: never post twice, return the remote state instead.
            if ($post->state === PostState::Published && $post->remote_status_id !== null) {
                return $client->statuses()->get($post->remote_status_id);
            }

            if ($post->state === PostState::Scheduled && $post->remote_scheduled_id !== null) {
                return $client->scheduled()->get($post->remote_scheduled_id);
            }

            if ($post->state === PostState::Deleted) {
                throw new MastodonException("The post with idempotency key {$key} has been deleted.");
            }
        }

        $this->validator->validate($pending, $client->instance()->info());

        $post ??= MastodonPost::query()->create([
            'mastodon_account_id' => $account->getKey(),
            'owner_type' => $pending->getOwner()?->getMorphClass(),
            'owner_id' => $pending->getOwner()?->getKey(),
            'idempotency_key' => $key,
            'state' => PostState::Pending,
            'scheduled_at' => $pending->getScheduledAt(),
            'payload' => $pending->toArray(),
        ]);

        try {
            $mediaIds = $this->uploadMedia($pending, $post);
            $result = $client->statuses()->create($pending->toParams($mediaIds), $key);
        } catch (Throwable $e) {
            $post->forceFill([
                'state' => PostState::Failed,
                'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 2000),
            ])->save();

            $this->events->dispatch(new MastodonStatusFailed($post, $e));

            throw $e;
        }

        if ($result instanceof ScheduledStatus) {
            $post->forceFill([
                'state' => PostState::Scheduled,
                'remote_scheduled_id' => $result->id,
                'scheduled_at' => $result->scheduledAt,
                'error' => null,
            ])->save();

            $this->events->dispatch(new MastodonStatusScheduled($post, $result));
        } else {
            $post->forceFill([
                'state' => PostState::Published,
                'remote_status_id' => $result->id,
                'url' => $result->url,
                'published_at' => $result->createdAt ?? now(),
                'error' => null,
            ])->save();

            $this->events->dispatch(new MastodonStatusPublished($post, $result));
        }

        return $result;
    }

    /**
     * Uploads all attached files and waits for processing. Uploaded IDs are
     * stored in the post payload, so a retry does not upload again.
     *
     * @return list<string>
     */
    private function uploadMedia(PendingStatus $pending, MastodonPost $post): array
    {
        $media = $pending->getMedia();

        if ($media === []) {
            return [];
        }

        $payload = $post->payload ?? [];
        $ids = $payload['media_ids'] ?? [];

        if (count($ids) === count($media)) {
            return $ids;
        }

        $post->forceFill(['state' => PostState::Uploading])->save();

        $ids = [];
        foreach ($media as $item) {
            if ($item['file'] === null) {
                $ids[] = (string) $item['id'];

                continue;
            }

            $ids[] = $pending->client()->media()->upload(
                $item['file'],
                $item['description'],
                $item['focus'],
                $item['thumbnail'],
                wait: true,
            )->id;
        }

        $payload['media_ids'] = $ids;
        $post->forceFill(['payload' => $payload])->save();

        return $ids;
    }
}
