<?php

namespace KaiHempel\Mastodon\Data;

final class MediaAttachment
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $url,
        public readonly ?string $previewUrl,
        public readonly ?string $remoteUrl,
        public readonly ?string $description,
        public readonly ?string $blurhash,
        public readonly array $meta,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        return new self(
            id: $a->string('id'),
            type: $a->string('type', 'unknown'),
            url: $a->nullableString('url'),
            previewUrl: $a->nullableString('preview_url'),
            remoteUrl: $a->nullableString('remote_url'),
            description: $a->nullableString('description'),
            blurhash: $a->nullableString('blurhash'),
            meta: $a->array('meta'),
            raw: $data,
        );
    }

    /** Processing is finished once the server returns a URL. */
    public function isProcessed(): bool
    {
        return $this->url !== null;
    }
}
