<?php

namespace KaiHempel\Mastodon\Support;

/**
 * PKCE helper (RFC 7636, method S256).
 */
final class Pkce
{
    /**
     * @return array{verifier: string, challenge: string}
     */
    public static function generate(int $length = 64): array
    {
        $verifier = self::verifier($length);

        return ['verifier' => $verifier, 'challenge' => self::challenge($verifier)];
    }

    public static function verifier(int $length = 64): string
    {
        $length = max(43, min(128, $length));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $max = strlen($alphabet) - 1;
        $verifier = '';

        for ($i = 0; $i < $length; $i++) {
            $verifier .= $alphabet[random_int(0, $max)];
        }

        return $verifier;
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
