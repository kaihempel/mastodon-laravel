<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;

final class Account
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $acct,
        public readonly string $displayName,
        public readonly ?string $url,
        public readonly ?string $avatar,
        public readonly bool $locked,
        public readonly bool $bot,
        public readonly ?CarbonImmutable $createdAt,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        return new self(
            id: $a->string('id'),
            username: $a->string('username'),
            acct: $a->string('acct'),
            displayName: $a->string('display_name'),
            url: $a->nullableString('url'),
            avatar: $a->nullableString('avatar'),
            locked: $a->bool('locked'),
            bot: $a->bool('bot'),
            createdAt: $a->date('created_at'),
            raw: $data,
        );
    }
}
