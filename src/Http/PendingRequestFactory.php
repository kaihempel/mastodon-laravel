<?php

namespace KaiHempel\Mastodon\Http;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use KaiHempel\Mastodon\Support\RateLimitStore;
use Psr\Log\LoggerInterface;

/**
 * Builds pre-configured HTTP requests (base URL, bearer token, timeouts,
 * redirect limits) and connectors that add retry, error mapping and rate
 * limit tracking on top.
 */
class PendingRequestFactory
{
    /** @param array<string, mixed> $config the "mastodon" config array */
    public function __construct(
        private readonly Factory $http,
        private readonly RateLimitStore $rateLimits,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly array $config,
    ) {}

    public function make(string $baseUrl, #[\SensitiveParameter] ?string $token = null): PendingRequest
    {
        $http = $this->config['http'] ?? [];
        $allowInsecure = (bool) ($this->config['allow_http_instances'] ?? false);

        $request = $this->http->baseUrl($baseUrl)
            ->acceptJson()
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10))
            ->withUserAgent($http['user_agent'] ?? 'kaihempel/mastodon-laravel')
            ->withOptions([
                'allow_redirects' => [
                    'max' => (int) ($http['max_redirects'] ?? 3),
                    'protocols' => $allowInsecure ? ['https', 'http'] : ['https'],
                    'strict' => true,
                ],
            ]);

        if ($token !== null && $token !== '') {
            $request->withToken($token);
        }

        return $request;
    }

    /**
     * @param  string|null  $rateLimitKey  key under which X-RateLimit-* state is stored
     * @param  Closure(\KaiHempel\Mastodon\Exceptions\AuthenticationException): void|null  $onUnauthorized
     */
    public function connector(
        string $baseUrl,
        #[\SensitiveParameter] ?string $token = null,
        ?string $rateLimitKey = null,
        ?Closure $onUnauthorized = null,
    ): Connector {
        return new Connector($this, $baseUrl, $token, $rateLimitKey, $onUnauthorized);
    }

    public function rateLimits(): RateLimitStore
    {
        return $this->rateLimits;
    }

    public function events(): Dispatcher
    {
        return $this->events;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
