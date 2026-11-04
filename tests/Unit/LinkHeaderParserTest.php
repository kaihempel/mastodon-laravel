<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Support\LinkHeaderParser;
use PHPUnit\Framework\TestCase;

class LinkHeaderParserTest extends TestCase
{
    public function test_it_parses_next_and_prev(): void
    {
        $header = '<https://example.social/api/v1/scheduled_statuses?limit=2&max_id=7>; rel="next", <https://example.social/api/v1/scheduled_statuses?limit=2&min_id=9>; rel="prev"';

        $this->assertSame([
            'next' => 'https://example.social/api/v1/scheduled_statuses?limit=2&max_id=7',
            'prev' => 'https://example.social/api/v1/scheduled_statuses?limit=2&min_id=9',
        ], LinkHeaderParser::parse($header));
    }

    public function test_it_handles_unquoted_and_multiple_relations(): void
    {
        $links = LinkHeaderParser::parse('<https://a.test/1>; rel=next; title="x", <https://a.test/2>; rel="prev start"');

        $this->assertSame('https://a.test/1', $links['next']);
        $this->assertSame('https://a.test/2', $links['prev']);
        $this->assertSame('https://a.test/2', $links['start']);
    }

    public function test_it_handles_empty_and_invalid_headers(): void
    {
        $this->assertSame([], LinkHeaderParser::parse(null));
        $this->assertSame([], LinkHeaderParser::parse(''));
        $this->assertSame([], LinkHeaderParser::parse('garbage'));
        $this->assertNull(LinkHeaderParser::next('<https://a.test/1>; rel="prev"'));
        $this->assertSame('https://a.test/1', LinkHeaderParser::prev('<https://a.test/1>; rel="prev"'));
    }
}
