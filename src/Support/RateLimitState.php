<?php

namespace KaiHempel\Mastodon\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

final class RateLimitState
{
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly ?CarbonImmutable $resetAt,
    ) {}

    public static function fromResponse(Response $response): ?self
    {
        $limit = $response->header('X-RateLimit-Limit');
        $remaining = $response->header('X-RateLimit-Remaining');
        $reset = $response->header('X-RateLimit-Reset');

        if ($limit === '' && $remaining === '' && $reset === '') {
            return null;
        }

        $resetAt = null;
        if ($reset !== '') {
            try {
                $resetAt = is_numeric($reset)
                    ? CarbonImmutable::createFromTimestamp((int) $reset)
                    : CarbonImmutable::parse($reset);
            } catch (Throwable) {
                $resetAt = null;
            }
        }

        return new self((int) $limit, (int) $remaining, $resetAt);
    }

    public function isExhausted(): bool
    {
        return $this->remaining <= 0 && $this->resetAt !== null && $this->resetAt->isFuture();
    }

    /** @return array{limit: int, remaining: int, reset_at: ?string} */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'reset_at' => $this->resetAt?->toIso8601String(),
        ];
    }

    /** @param array{limit?: int, remaining?: int, reset_at?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['limit'] ?? 0),
            (int) ($data['remaining'] ?? 0),
            isset($data['reset_at']) ? CarbonImmutable::parse($data['reset_at']) : null,
        );
    }
}
