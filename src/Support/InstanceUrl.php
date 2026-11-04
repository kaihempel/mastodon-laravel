<?php

namespace KaiHempel\Mastodon\Support;

use KaiHempel\Mastodon\Exceptions\InvalidInstanceException;
use Stringable;

/**
 * Normalized and validated base URL of a Mastodon instance.
 *
 * The instance is user input and therefore an SSRF vector: only https, only a
 * host name (no path, query, credentials) and no private, loopback, link-local
 * or reserved addresses are accepted.
 */
final class InstanceUrl implements Stringable
{
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port = null,
    ) {}

    /**
     * @throws InvalidInstanceException
     */
    public static function parse(string $input, ?HostResolver $resolver = null, bool $allowInsecure = false): self
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidInstanceException('The instance must not be empty.');
        }

        // "@user@example.social" or "user@example.social" -> host part.
        if (! str_contains($input, '://') && str_contains($input, '@')) {
            $input = substr($input, strrpos($input, '@') + 1);
        }

        if (! str_contains($input, '://')) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);

        if ($parts === false || empty($parts['host'])) {
            throw new InvalidInstanceException('The instance URL could not be parsed.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https' && ! ($allowInsecure && $scheme === 'http')) {
            throw new InvalidInstanceException('Only https instances are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidInstanceException('The instance URL must not contain credentials.');
        }

        if (isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '/')) {
            throw new InvalidInstanceException('The instance must be a host name without path, query or fragment.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $host = trim($host, '[]');

        if (! filter_var($host, FILTER_VALIDATE_IP) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidInstanceException('The instance host name is invalid.');
            }
            $host = $ascii;
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // Fully qualified name (at least one dot); single labels only in insecure (local) mode.
        $pattern = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)'.($allowInsecure ? '*' : '+').'$/';

        if (! $isIp && ! preg_match($pattern, $host)) {
            throw new InvalidInstanceException('The instance host name is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === 443 && $scheme === 'https' || $port === 80 && $scheme === 'http') {
            $port = null;
        }

        if (! $allowInsecure) {
            if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                throw new InvalidInstanceException('Local host names are not allowed.');
            }

            $addresses = $isIp ? [$host] : ($resolver?->resolve($host) ?? []);

            if ($resolver !== null && ! $isIp && $addresses === []) {
                throw new InvalidInstanceException("The host {$host} could not be resolved.");
            }

            foreach ($addresses as $address) {
                if (! self::isPublicAddress($address)) {
                    throw new InvalidInstanceException('The instance resolves to a private or reserved address.');
                }
            }
        }

        return new self($scheme, $host, $port);
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) -> check the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m)) {
            $address = $m[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Ranges not (reliably) covered by the filter flags.
        $blocked = [
            '100.64.0.0/10',   // carrier-grade NAT
            '169.254.0.0/16',  // link-local
            '127.0.0.0/8',
            '0.0.0.0/8',
            '192.0.0.0/24',
            '198.18.0.0/15',
            'fc00::/7',        // unique local
            'fe80::/10',       // link-local
            '::1/128',
            '::/128',
        ];

        foreach ($blocked as $cidr) {
            if (self::inRange($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $address = inet_pton($address);
        $subnet = inet_pton($subnet);

        if ($address === false || $subnet === false || strlen($address) !== strlen($subnet)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($address, $subnet, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (($address[$bytes] & $mask) === ($subnet[$bytes] & $mask));
    }

    public function baseUrl(): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$this->host}]" : $this->host;

        return $this->scheme.'://'.$host.($this->port !== null ? ':'.$this->port : '');
    }

    /** Whether an absolute URL points to this instance (used for pagination links). */
    public function owns(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === $this->scheme
            && strtolower(trim($parts['host'] ?? '', '[]')) === $this->host
            && (isset($parts['port']) ? (int) $parts['port'] : null) === $this->port;
    }

    public function __toString(): string
    {
        return $this->baseUrl();
    }
}
