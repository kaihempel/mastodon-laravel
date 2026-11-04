<?php

namespace KaiHempel\Mastodon\Support;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
