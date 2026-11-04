<?php

namespace KaiHempel\Mastodon\Support;

final class Scopes
{
    /**
     * @param  string|iterable<string>|null  $scopes
     * @return list<string>
     */
    public static function parse(string|iterable|null $scopes): array
    {
        if ($scopes === null) {
            return [];
        }

        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $result = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && ! in_array($scope, $result, true)) {
                $result[] = $scope;
            }
        }

        return $result;
    }

    /**
     * @param  string|iterable<string>  $scopes
     */
    public static function toString(string|iterable $scopes): string
    {
        return implode(' ', self::parse($scopes));
    }

    /**
     * Whether a granted scope set covers a single scope. A top-level scope
     * ("read", "write") covers all of its granular sub-scopes ("write:media").
     *
     * @param  list<string>  $granted
     */
    public static function grants(array $granted, string $scope): bool
    {
        if (in_array($scope, $granted, true)) {
            return true;
        }

        $parent = strstr($scope, ':', true);

        return $parent !== false && in_array($parent, $granted, true);
    }

    /**
     * Scopes of $required that are not covered by $granted.
     *
     * @param  string|iterable<string>  $granted
     * @param  string|iterable<string>  $required
     * @return list<string>
     */
    public static function missing(string|iterable $granted, string|iterable $required): array
    {
        $granted = self::parse($granted);

        return array_values(array_filter(
            self::parse($required),
            fn (string $scope) => ! self::grants($granted, $scope),
        ));
    }

    /**
     * @param  string|iterable<string>  $granted
     * @param  string|iterable<string>  $required
     */
    public static function covers(string|iterable $granted, string|iterable $required): bool
    {
        return self::missing($granted, $required) === [];
    }
}
