<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;
use KaiHempel\Mastodon\Support\Scopes;

final class Token
{
    /** @param list<string> $scopes */
    public function __construct(
        #[\SensitiveParameter] public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly array $scopes,
        public readonly ?CarbonImmutable $createdAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);
        $createdAt = $a->nullableInt('created_at');

        return new self(
            accessToken: $a->string('access_token'),
            tokenType: $a->string('token_type', 'Bearer'),
            scopes: Scopes::parse($a->nullableString('scope') ?? 'read'),
            createdAt: $createdAt !== null ? CarbonImmutable::createFromTimestamp($createdAt) : null,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '***',
            'tokenType' => $this->tokenType,
            'scopes' => $this->scopes,
            'createdAt' => $this->createdAt,
        ];
    }
}
