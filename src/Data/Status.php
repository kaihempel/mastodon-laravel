<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;

final class Status
{
    /**
     * @param  list<MediaAttachment>  $mediaAttachments
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $uri,
        public readonly ?string $url,
        public readonly ?CarbonImmutable $createdAt,
        public readonly ?CarbonImmutable $editedAt,
        public readonly string $content,
        public readonly ?string $text,
        public readonly string $visibility,
        public readonly bool $sensitive,
        public readonly string $spoilerText,
        public readonly ?string $language,
        public readonly ?string $inReplyToId,
        public readonly array $mediaAttachments,
        public readonly ?Poll $poll,
        public readonly ?Account $account,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        return new self(
            id: $a->string('id'),
            uri: $a->nullableString('uri'),
            url: $a->nullableString('url'),
            createdAt: $a->date('created_at'),
            editedAt: $a->date('edited_at'),
            content: $a->string('content'),
            // Only present in the response of DELETE (source for "delete & redraft").
            text: $a->nullableString('text'),
            visibility: $a->string('visibility', 'public'),
            sensitive: $a->bool('sensitive'),
            spoilerText: $a->string('spoiler_text'),
            language: $a->nullableString('language'),
            inReplyToId: $a->nullableString('in_reply_to_id'),
            mediaAttachments: array_map(
                fn ($m) => MediaAttachment::fromArray((array) $m),
                array_values($a->array('media_attachments')),
            ),
            poll: is_array($a->get('poll')) ? Poll::fromArray($a->get('poll')) : null,
            account: is_array($a->get('account')) ? Account::fromArray($a->get('account')) : null,
            raw: $data,
        );
    }
}
