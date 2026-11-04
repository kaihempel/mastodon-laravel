<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Publishing\CharacterCounter;
use PHPUnit\Framework\TestCase;

class CharacterCounterTest extends TestCase
{
    public function test_plain_text_counts_code_points(): void
    {
        $this->assertSame(0, CharacterCounter::count(''));
        $this->assertSame(5, CharacterCounter::count('Hallo'));
        $this->assertSame(4, CharacterCounter::count('Über'));
        $this->assertSame(2, CharacterCounter::count('🐘🐘'));
    }

    public function test_urls_count_as_reserved_characters(): void
    {
        $url = 'https://example.com/a/very/long/path/that/is/much/longer/than/23/characters?x=1';

        $this->assertSame(23, CharacterCounter::count($url));
        $this->assertSame(6 + 23, CharacterCounter::count("Neu:  {$url}"));
        $this->assertSame(23 * 2 + 1, CharacterCounter::count("{$url} http://a.de"));
        $this->assertSame(10, CharacterCounter::count('https://x.de', 10));
    }

    public function test_trailing_punctuation_is_not_part_of_the_url(): void
    {
        $this->assertSame(23 + 1, CharacterCounter::count('https://example.com/foo.'));
        $this->assertSame(1 + 23 + 1, CharacterCounter::count('(https://example.com/foo)'));
    }

    public function test_remote_mentions_count_only_the_username(): void
    {
        $this->assertSame(strlen('Hi @alice'), CharacterCounter::count('Hi @alice@example.social'));
        $this->assertSame(strlen('Hi @alice'), CharacterCounter::count('Hi @alice'));
    }

    public function test_email_addresses_are_not_treated_as_mentions(): void
    {
        $this->assertSame(strlen('mail me: a@b.de'), CharacterCounter::count('mail me: a@b.de'));
    }

    public function test_hashtags_count_normally(): void
    {
        $this->assertSame(strlen('Neue Version ist da #release'), CharacterCounter::count('Neue Version ist da #release'));
    }
}
