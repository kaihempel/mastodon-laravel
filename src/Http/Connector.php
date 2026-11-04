<?php

namespace KaiHempel\Mastodon\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use KaiHempel\Mastodon\Events\MastodonRateLimited;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\ConnectionException;
use KaiHempel\Mastodon\Exceptions\ExceptionFactory;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\Support\RateLimitState;

/**
 * Sends requests to one instance (optionally with a token), retries on 429,
 * 5xx and connection errors with exponential backoff, tracks X-RateLimit-*
 * headers and maps error responses to exceptions.
 *
 * Options for request():
 *  - query:     array of query parameters
 *  - json:      JSON body
 *  - form:      form-encoded body
 *  - multipart: multipart fields; files: list of [name, contents, filename, headers]
 *  - headers:   additional headers
 *  - allow:     list of non-2xx status codes returned instead of thrown
 *  - retry:     false disables retries
 */
class Connector
{
    public function __construct(
        private readonly PendingRequestFactory $factory,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly ?string $rateLimitKey = null,
        private readonly ?Closure $onUnauthorized = null,
    ) {}

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = [], array $options = []): Response
    {
        return $this->request('GET', $path, ['query' => $query] + $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $path, array $options = []): Response
    {
        return $this->request('POST', $path, $options);
    }

    /** @param array<string, mixed> $options */
    public function put(string $path, array $options = []): Response
    {
        return $this->request('PUT', $path, $options);
    }

    /** @param array<string, mixed> $options */
    public function delete(string $path, array $options = []): Response
    {
        return $this->request('DELETE', $path, $options);
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $path, array $options = []): Response
    {
        $this->guardRateLimit();

        $maxRetries = ($options['retry'] ?? true) ? (int) $this->factory->config('http.retries', 2) : 0;
        $maxWait = (int) $this->factory->config('http.max_retry_wait_s', 30);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->send($method, $path, $options);
            } catch (HttpConnectionException $e) {
                if ($attempt < $maxRetries) {
                    Sleep::usleep($this->backoffMs($attempt++) * 1000);

                    continue;
                }

                throw new ConnectionException('Connection to the Mastodon instance failed: '.$e->getMessage(), previous: $e);
            }

            $this->afterResponse($method, $path, $response);

            if ($response->successful() || in_array($response->status(), $options['allow'] ?? [], true)) {
                return $response;
            }

            $exception = ExceptionFactory::fromResponse($response);

            if ($exception->isRetryable() && $attempt < $maxRetries) {
                $delayMs = $exception instanceof RateLimitException
                    ? $exception->retryAfterSeconds() * 1000
                    : $this->backoffMs($attempt);

                if ($delayMs <= $maxWait * 1000) {
                    $attempt++;
                    Sleep::usleep($delayMs * 1000);

                    continue;
                }
            }

            throw $this->handleFailure($exception);
        }
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options): Response
    {
        $request = $this->factory->make($this->baseUrl, $this->token)
            ->withHeaders($options['headers'] ?? []);

        $payload = [];

        if (isset($options['multipart'])) {
            foreach ($options['files'] ?? [] as $file) {
                $request->attach($file[0], $file[1], $file[2] ?? null, $file[3] ?? []);
            }
            $request->asMultipart();
            $payload['multipart'] = $options['multipart'];
        } elseif (array_key_exists('json', $options)) {
            $request->asJson();
            $payload['json'] = $options['json'];
        } elseif (isset($options['form'])) {
            $request->asForm();
            $payload['form_params'] = $options['form'];
        }

        if (! empty($options['query'])) {
            $payload['query'] = $options['query'];
        }

        return $request->send($method, $path, $payload);
    }

    private function backoffMs(int $attempt): int
    {
        return (int) $this->factory->config('http.backoff_ms', 500) * (2 ** $attempt);
    }

    /**
     * Pre-emptive check: when the last response reported no remaining
     * requests, wait (short) or fail fast with a RateLimitException.
     */
    private function guardRateLimit(): void
    {
        if ($this->rateLimitKey === null) {
            return;
        }

        $state = $this->factory->rateLimits()->get($this->rateLimitKey);

        if ($state === null || ! $state->isExhausted()) {
            return;
        }

        $exception = RateLimitException::until($state->resetAt, 'Rate limit exhausted until '.$state->resetAt?->toIso8601String());
        $wait = $exception->retryAfterSeconds();

        if ($wait <= (int) $this->factory->config('http.max_retry_wait_s', 30)) {
            Sleep::for($wait)->seconds();

            return;
        }

        throw $this->handleFailure($exception);
    }

    private function afterResponse(string $method, string $path, Response $response): void
    {
        if ($this->rateLimitKey !== null && ($state = RateLimitState::fromResponse($response)) !== null) {
            $this->factory->rateLimits()->put($this->rateLimitKey, $state);
        }

        // Mastodon >= 4.4 marks deprecated endpoints with a Deprecation header.
        if ($response->header('Deprecation') !== '' && $this->factory->config('debug', false)) {
            $this->factory->logger()->warning('Mastodon API endpoint is deprecated', [
                'method' => $method,
                'path' => $path,
                'deprecation' => $response->header('Deprecation'),
                'sunset' => $response->header('Sunset') ?: null,
            ]);
        }
    }

    private function handleFailure(MastodonException $exception): MastodonException
    {
        if ($exception instanceof RateLimitException) {
            $this->factory->events()->dispatch(new MastodonRateLimited(
                $this->rateLimitKey ?? (string) parse_url($this->baseUrl, PHP_URL_HOST),
                $exception->resetAt,
            ));
        }

        if ($exception instanceof AuthenticationException && $this->onUnauthorized !== null) {
            ($this->onUnauthorized)($exception);
        }

        return $exception;
    }
}
