<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;

final class Poll
{
    /**
     * @param  list<array{title: string, votes_count: ?int}>  $options
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly bool $expired,
        public readonly bool $multiple,
        public readonly int $votesCount,
        public readonly array $options,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        $options = [];
        foreach ($a->array('options') as $option) {
            $o = new Attributes(is_array($option) ? $option : []);
            $options[] = ['title' => $o->string('title'), 'votes_count' => $o->nullableInt('votes_count')];
        }

        return new self(
            id: $a->string('id'),
            expiresAt: $a->date('expires_at'),
            expired: $a->bool('expired'),
            multiple: $a->bool('multiple'),
            votesCount: $a->int('votes_count'),
            options: $options,
            raw: $data,
        );
    }
}
