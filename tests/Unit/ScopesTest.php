<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Support\Scopes;
use PHPUnit\Framework\TestCase;

class ScopesTest extends TestCase
{
    public function test_parse_and_to_string(): void
    {
        $this->assertSame(['read', 'write:media'], Scopes::parse(' read  write:media read'));
        $this->assertSame(['a', 'b'], Scopes::parse(['a', 'b', 'a']));
        $this->assertSame([], Scopes::parse(null));
        $this->assertSame('profile write:statuses', Scopes::toString(['profile', 'write:statuses']));
    }

    public function test_top_level_scopes_cover_granular_scopes(): void
    {
        $this->assertTrue(Scopes::covers('write read', ['write:statuses', 'write:media', 'read:statuses']));
        $this->assertFalse(Scopes::covers('write:statuses', ['write']));
        $this->assertSame(['profile'], Scopes::missing('write read', 'profile write:media'));
    }

    public function test_missing_scopes(): void
    {
        $this->assertSame(['write:media'], Scopes::missing('profile write:statuses', ['profile', 'write:statuses', 'write:media']));
        $this->assertSame([], Scopes::missing(['profile', 'write:statuses'], 'write:statuses'));
    }
}
