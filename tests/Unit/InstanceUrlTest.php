<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Exceptions\InvalidInstanceException;
use KaiHempel\Mastodon\Support\HostResolver;
use KaiHempel\Mastodon\Support\InstanceUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstanceUrlTest extends TestCase
{
    private function resolver(array $addresses = ['93.184.216.34']): HostResolver
    {
        return new class($addresses) implements HostResolver
        {
            public function __construct(private array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    public static function validInputs(): array
    {
        return [
            'host' => ['example.social', 'https://example.social'],
            'url' => ['https://example.social', 'https://example.social'],
            'trailing slash' => ['https://Example.Social/', 'https://example.social'],
            'whitespace' => ['  example.social  ', 'https://example.social'],
            'handle' => ['@alice@example.social', 'https://example.social'],
            'port' => ['https://example.social:8443', 'https://example.social:8443'],
            'default port' => ['https://example.social:443', 'https://example.social'],
            'idn' => ['mästodon.de', 'https://xn--mstodon-5wa.de'],
            'trailing dot' => ['example.social.', 'https://example.social'],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_it_normalizes_valid_instances(string $input, string $expected): void
    {
        $this->assertSame($expected, InstanceUrl::parse($input, $this->resolver())->baseUrl());
    }

    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'http' => ['http://example.social'],
            'ftp' => ['ftp://example.social'],
            'path' => ['https://example.social/api'],
            'query' => ['https://example.social/?a=b'],
            'fragment' => ['https://example.social/#x'],
            'credentials' => ['https://user:pass@example.social'],
            'localhost' => ['localhost'],
            'localhost subdomain' => ['foo.localhost'],
            'single label' => ['intranet'],
            'loopback ip' => ['127.0.0.1'],
            'private ip' => ['10.0.0.5'],
            'private ip 192' => ['192.168.1.1'],
            'link local' => ['169.254.169.254'],
            'cgnat' => ['100.64.1.1'],
            'ipv6 loopback' => ['https://[::1]'],
            'ipv6 unique local' => ['https://[fd00::1]'],
            'invalid host' => ['exa mple.social'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_or_unsafe_instances(string $input): void
    {
        $this->expectException(InvalidInstanceException::class);

        InstanceUrl::parse($input, $this->resolver());
    }

    public function test_it_rejects_hosts_resolving_to_private_addresses(): void
    {
        $this->expectException(InvalidInstanceException::class);

        InstanceUrl::parse('evil.example', $this->resolver(['93.184.216.34', '10.1.2.3']));
    }

    public function test_it_rejects_ipv4_mapped_ipv6_private_addresses(): void
    {
        $this->expectException(InvalidInstanceException::class);

        InstanceUrl::parse('evil.example', $this->resolver(['::ffff:127.0.0.1']));
    }

    public function test_it_rejects_unresolvable_hosts(): void
    {
        $this->expectException(InvalidInstanceException::class);

        InstanceUrl::parse('nothing.example', $this->resolver([]));
    }

    public function test_insecure_mode_allows_http_and_private_hosts(): void
    {
        $this->assertSame('http://localhost:3000', InstanceUrl::parse('http://localhost:3000', null, allowInsecure: true)->baseUrl());
    }

    public function test_public_address_check(): void
    {
        $this->assertTrue(InstanceUrl::isPublicAddress('93.184.216.34'));
        $this->assertTrue(InstanceUrl::isPublicAddress('2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertFalse(InstanceUrl::isPublicAddress('172.16.0.1'));
        $this->assertFalse(InstanceUrl::isPublicAddress('fe80::1'));
        $this->assertFalse(InstanceUrl::isPublicAddress('0.0.0.0'));
        $this->assertFalse(InstanceUrl::isPublicAddress('not-an-ip'));
    }

    public function test_owns_only_matches_the_same_origin(): void
    {
        $url = InstanceUrl::parse('example.social', $this->resolver());

        $this->assertTrue($url->owns('https://example.social/api/v1/scheduled_statuses?max_id=1'));
        $this->assertFalse($url->owns('https://evil.example/api/v1/scheduled_statuses'));
        $this->assertFalse($url->owns('http://example.social/api'));
        $this->assertFalse($url->owns('https://example.social:8443/api'));
    }
}
