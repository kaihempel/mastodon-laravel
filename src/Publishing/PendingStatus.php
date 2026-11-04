<?php

namespace KaiHempel\Mastodon\Publishing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Enums\Visibility;
use KaiHempel\Mastodon\Jobs\PublishStatusJob;
use KaiHempel\Mastodon\MastodonClient;
use KaiHempel\Mastodon\Resources\ScheduledStatusesResource;

/**
 * Fluent builder for a new status.
 *
 *   Mastodon::for($account)->status('Hello #fediverse')->visibility('unlisted')->publish();
 */
class PendingStatus
{
    private string $status;

    private ?string $visibility = null;

    private ?string $language = null;

    private ?string $spoilerText = null;

    private ?bool $sensitive = null;

    private ?string $inReplyToId = null;

    private ?CarbonImmutable $scheduledAt = null;

    private ?string $idempotencyKey = null;

    private ?Model $owner = null;

    /** @var list<array{file: ?MediaFile, id: ?string, description: ?string, focus: ?array<int, float>, thumbnail: ?MediaFile}> */
    private array $media = [];

    /** @var array{options: list<string>, expires_in: int, multiple: bool, hide_totals: bool}|null */
    private ?array $poll = null;

    public function __construct(
        private readonly MastodonClient $client,
        private readonly Publisher $publisher,
        string $status = '',
    ) {
        $this->status = $status;
    }

    public function status(string $text): static
    {
        $this->status = $text;

        return $this;
    }

    public function visibility(string|Visibility $visibility): static
    {
        $this->visibility = $visibility instanceof Visibility ? $visibility->value : $visibility;

        return $this;
    }

    public function language(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function spoilerText(?string $spoilerText): static
    {
        $this->spoilerText = $spoilerText;

        return $this;
    }

    public function sensitive(bool $sensitive = true): static
    {
        $this->sensitive = $sensitive;

        return $this;
    }

    public function inReplyTo(?string $statusId): static
    {
        $this->inReplyToId = $statusId;

        return $this;
    }

    /**
     * Attach a file (path inside mastodon.media.allowed_paths or a MediaFile).
     *
     * @param  array{0: float, 1: float}|null  $focus  x,y between -1.0 and 1.0
     */
    public function attach(string|MediaFile $file, ?string $description = null, ?array $focus = null, string|MediaFile|null $thumbnail = null): static
    {
        $this->media[] = [
            'file' => is_string($file) ? MediaFile::fromPath($file) : $file,
            'id' => null,
            'description' => $description,
            'focus' => $focus !== null ? array_values($focus) : null,
            'thumbnail' => is_string($thumbnail) ? MediaFile::fromPath($thumbnail) : $thumbnail,
        ];

        return $this;
    }

    /**
     * Attach a file from a filesystem disk listed in mastodon.media.allowed_disks.
     *
     * @param  array{0: float, 1: float}|null  $focus
     */
    public function attachFromDisk(string $disk, string $path, ?string $description = null, ?array $focus = null): static
    {
        return $this->attach(MediaFile::fromDisk($disk, $path), $description, $focus);
    }

    /** Attach media that has already been uploaded. */
    public function attachExisting(string $mediaId): static
    {
        $this->media[] = ['file' => null, 'id' => $mediaId, 'description' => null, 'focus' => null, 'thumbnail' => null];

        return $this;
    }

    /** @param list<string> $options */
    public function poll(array $options, int $expiresIn, bool $multiple = false, bool $hideTotals = false): static
    {
        $this->poll = [
            'options' => array_values(array_map('strval', $options)),
            'expires_in' => $expiresIn,
            'multiple' => $multiple,
            'hide_totals' => $hideTotals,
        ];

        return $this;
    }

    /** Server-side scheduling via scheduled_at (at least 5 minutes ahead). */
    public function scheduleAt(?DateTimeInterface $at): static
    {
        $this->scheduledAt = $at !== null ? CarbonImmutable::instance($at) : null;

        return $this;
    }

    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** Model of the host application the post belongs to (stored as morph). */
    public function owner(?Model $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Validate against the instance limits.
     *
     * @throws \KaiHempel\Mastodon\Exceptions\ValidationException
     */
    public function validate(): static
    {
        app(StatusValidator::class)->validate($this, $this->client->instance()->info());

        return $this;
    }

    /**
     * Upload media, wait for processing and create the status.
     */
    public function publish(): Status|ScheduledStatus
    {
        return $this->publisher->publish($this);
    }

    /**
     * Publish in the background. With $at the job runs at that time (local
     * scheduling, e.g. when the host app wants an approval step first).
     * Returns the idempotency key, which identifies the MastodonPost record.
     */
    public function queue(?DateTimeInterface $at = null): string
    {
        $key = $this->ensureIdempotencyKey();

        $pending = PublishStatusJob::dispatch($this->client->account()->getKey(), $this->toArray());

        if ($at !== null) {
            $pending->delay($at);
        }

        return $key;
    }

    public function ensureIdempotencyKey(): string
    {
        return $this->idempotencyKey ??= (string) Str::uuid();
    }

    /**
     * API parameters for POST /api/v1/statuses.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, mixed>
     */
    public function toParams(array $mediaIds = []): array
    {
        $params = ['status' => $this->status];

        if ($mediaIds !== []) {
            $params['media_ids'] = array_values($mediaIds);
        }

        if ($this->poll !== null) {
            $params['poll'] = $this->poll;
        }

        foreach ([
            'in_reply_to_id' => $this->inReplyToId,
            'sensitive' => $this->sensitive,
            'spoiler_text' => $this->spoilerText,
            'visibility' => $this->visibility,
            'language' => $this->language,
            'scheduled_at' => $this->scheduledAt !== null ? ScheduledStatusesResource::formatDate($this->scheduledAt) : null,
        ] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Serializable representation (queue payload and snapshot in mastodon_posts).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'visibility' => $this->visibility,
            'language' => $this->language,
            'spoiler_text' => $this->spoilerText,
            'sensitive' => $this->sensitive,
            'in_reply_to_id' => $this->inReplyToId,
            'scheduled_at' => $this->scheduledAt?->toIso8601String(),
            'idempotency_key' => $this->idempotencyKey,
            'owner_type' => $this->owner?->getMorphClass(),
            'owner_id' => $this->owner?->getKey(),
            'poll' => $this->poll,
            'media' => array_map(fn (array $m) => [
                'file' => $m['file']?->toArray(),
                'id' => $m['id'],
                'description' => $m['description'],
                'focus' => $m['focus'],
                'thumbnail' => $m['thumbnail']?->toArray(),
            ], $this->media),
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        $this->status = (string) ($data['status'] ?? '');
        $this->visibility = $data['visibility'] ?? null;
        $this->language = $data['language'] ?? null;
        $this->spoilerText = $data['spoiler_text'] ?? null;
        $this->sensitive = $data['sensitive'] ?? null;
        $this->inReplyToId = $data['in_reply_to_id'] ?? null;
        $this->scheduledAt = isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at']) : null;
        $this->idempotencyKey = $data['idempotency_key'] ?? null;
        $this->poll = $data['poll'] ?? null;
        $this->media = [];

        if (! empty($data['owner_type']) && isset($data['owner_id'])) {
            $class = Model::getActualClassNameForMorph($data['owner_type']);
            $this->owner = class_exists($class) ? $class::find($data['owner_id']) : null;
        }

        foreach ($data['media'] ?? [] as $m) {
            $this->media[] = [
                'file' => isset($m['file']) ? MediaFile::fromArray($m['file']) : null,
                'id' => $m['id'] ?? null,
                'description' => $m['description'] ?? null,
                'focus' => $m['focus'] ?? null,
                'thumbnail' => isset($m['thumbnail']) ? MediaFile::fromArray($m['thumbnail']) : null,
            ];
        }

        return $this;
    }

    public function client(): MastodonClient
    {
        return $this->client;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getVisibility(): ?string
    {
        return $this->visibility;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getSpoilerText(): ?string
    {
        return $this->spoilerText;
    }

    public function isSensitive(): ?bool
    {
        return $this->sensitive;
    }

    public function getInReplyToId(): ?string
    {
        return $this->inReplyToId;
    }

    public function getScheduledAt(): ?CarbonImmutable
    {
        return $this->scheduledAt;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getOwner(): ?Model
    {
        return $this->owner;
    }

    /** @return list<array{file: ?MediaFile, id: ?string, description: ?string, focus: ?array<int, float>, thumbnail: ?MediaFile}> */
    public function getMedia(): array
    {
        return $this->media;
    }

    /** @return array{options: list<string>, expires_in: int, multiple: bool, hide_totals: bool}|null */
    public function getPoll(): ?array
    {
        return $this->poll;
    }

    public function isScheduled(): bool
    {
        return $this->scheduledAt !== null;
    }
}
