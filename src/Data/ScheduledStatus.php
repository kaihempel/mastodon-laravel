<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;

final class ScheduledStatus
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<MediaAttachment>  $mediaAttachments
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?CarbonImmutable $scheduledAt,
        public readonly array $params,
        public readonly array $mediaAttachments,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        return new self(
            id: $a->string('id'),
            scheduledAt: $a->date('scheduled_at'),
            params: $a->array('params'),
            mediaAttachments: array_map(
                fn ($m) => MediaAttachment::fromArray((array) $m),
                array_values($a->array('media_attachments')),
            ),
            raw: $data,
        );
    }

    public function text(): ?string
    {
        return isset($this->params['text']) ? (string) $this->params['text'] : null;
    }
}
