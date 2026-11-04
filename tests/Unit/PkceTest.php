<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Support\Pkce;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase
{
    public function test_challenge_is_base64url_encoded_sha256(): void
    {
        $this->assertSame(
            'bk10AynlmoTWYmmZcbxfAbmw5xFWq09Up3EePgxA1lc',
            Pkce::challenge('dBjftJeZ4CVP-mJ92K9qkhuMhLtL3NSdbDThbI6SmOw'),
        );
    }

    public function test_generated_verifier_matches_rfc_7636(): void
    {
        $pkce = Pkce::generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $pkce['verifier']);
        $this->assertSame(Pkce::challenge($pkce['verifier']), $pkce['challenge']);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $pkce['challenge']);
    }

    public function test_verifier_length_is_clamped(): void
    {
        $this->assertSame(43, strlen(Pkce::verifier(10)));
        $this->assertSame(128, strlen(Pkce::verifier(500)));
    }

    public function test_verifiers_are_random(): void
    {
        $this->assertNotSame(Pkce::verifier(), Pkce::verifier());
    }
}
