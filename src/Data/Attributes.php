<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tolerant accessors for API payloads: unknown fields are ignored, missing
 * optional fields become null. IDs are always kept as strings.
 *
 * @internal
 */
final class Attributes
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->nullableString($key) ?? $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        return $this->nullableInt($key) ?? $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return is_bool($value) ? $value : ($value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function date(string $key): ?CarbonImmutable
    {
        $value = $this->get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        return array_values(array_map('strval', array_filter($this->array($key), 'is_scalar')));
    }
}
